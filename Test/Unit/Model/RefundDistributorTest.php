<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

require_once __DIR__ . '/../Mocks/MagentoMocks.php';
require_once __DIR__ . '/../../../Model/RefundDistributor.php';

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Logger\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Creditmemo;

class RefundDistributorTest extends TestCase
{
    private $distributor;
    private $ticService;
    private $logger;

    protected function setUp(): void
    {
        $this->ticService = $this->createMock(ProductTicService::class);
        $this->ticService->method('getProductTic')->willReturn('20010');
        $this->ticService->method('getShippingTic')->willReturn('11010');

        $this->logger = $this->createMock(Logger::class);

        $this->distributor = new RefundDistributor($this->ticService, $this->logger);
    }

    /**
     * Build a mock Item with the given attributes.
     */
    private function makeItem(string $sku, float $qtyOrdered, float $qtyRefunded, float $price, float $discount = 0.0)
    {
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getQtyRefunded')->willReturn($qtyRefunded);
        $item->method('getPrice')->willReturn($price);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getParentItem')->willReturn(null);
        return $item;
    }

    /**
     * Build a mock Order with given items, shipping, tax, subtotal.
     */
    private function makeOrder(array $items, float $shipping = 0.0, float $shippingRefunded = 0.0, float $tax = 0.0, float $subtotal = 0.0)
    {
        $order = $this->createMock(Order::class);
        $order->method('getAllItems')->willReturn($items);
        $order->method('getShippingAmount')->willReturn($shipping);
        $order->method('getShippingRefunded')->willReturn($shippingRefunded);
        $order->method('getTaxAmount')->willReturn($tax);
        $order->method('getSubtotal')->willReturn($subtotal);
        return $order;
    }

    /**
     * Build a mock Creditmemo bound to the given order with the given grand total.
     */
    private function makeCreditmemo($order, float $grandTotal)
    {
        $cm = $this->createMock(Creditmemo::class);
        $cm->method('getOrder')->willReturn($order);
        $cm->method('getBaseGrandTotal')->willReturn($grandTotal);
        return $cm;
    }

    /**
     * Adjustment-only $2 refund on a $130 order (2x $50 + 1x $30).
     * Expected: distribute action with fractional quantities.
     */
    public function testDistributesAdjustmentAcrossRemainingItems()
    {
        $itemA = $this->makeItem('SKU-A', 2, 0, 50.0);
        $itemB = $this->makeItem('SKU-B', 1, 0, 30.0);
        $order = $this->makeOrder([$itemA, $itemB], 0.0, 0.0, 0.0, 130.0);
        $cm = $this->makeCreditmemo($order, 2.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
        $this->assertCount(2, $result['cartItems']);

        // adjustmentPercent = (1 * 2) / 130 = ~0.01538 (taxRatio is 1 since tax=0)
        $this->assertEqualsWithDelta(0.0308, $result['cartItems'][0]['Qty'], 0.0001);
        $this->assertEqualsWithDelta(0.0154, $result['cartItems'][1]['Qty'], 0.0001);

        $this->assertEquals('SKU-A', $result['cartItems'][0]['ItemID']);
        $this->assertEquals(50.0, $result['cartItems'][0]['Price']);
        $this->assertEquals('20010', $result['cartItems'][0]['TIC']);
    }

    /**
     * Tax ratio should reduce the effective adjustment so we don't over-refund tax.
     */
    public function testTaxRatioReducesDistribution()
    {
        $item = $this->makeItem('SKU-A', 1, 0, 100.0);
        // tax=10, subtotal=100 → taxRatio = 1 - (10/110) = 0.9091
        $order = $this->makeOrder([$item], 0.0, 0.0, 10.0, 100.0);
        $cm = $this->makeCreditmemo($order, 10.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
        // adjustmentPercent = (0.9091 * 10) / 100 = 0.0909
        // qty = 1 * 0.0909 = 0.0909 → rounded to 0.0909
        $this->assertEqualsWithDelta(0.0909, $result['cartItems'][0]['Qty'], 0.0001);
    }

    /**
     * Includes shipping in the distribution pool.
     */
    public function testIncludesShippingInDistribution()
    {
        $item = $this->makeItem('SKU-A', 1, 0, 65.0);
        $order = $this->makeOrder([$item], 35.0, 0.0, 0.0, 65.0);
        $cm = $this->makeCreditmemo($order, 10.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
        $this->assertCount(2, $result['cartItems']);

        $shippingItem = null;
        foreach ($result['cartItems'] as $ci) {
            if ($ci['ItemID'] === 'shipping') {
                $shippingItem = $ci;
            }
        }
        $this->assertNotNull($shippingItem, 'shipping should be included in distribution');
        $this->assertEquals(35.0, $shippingItem['Price']);
        $this->assertEquals('11010', $shippingItem['TIC']);
        // remainingTotal=100, percent = 10/100 = 0.10, shipping qty = 1 * 0.10 = 0.10
        $this->assertEqualsWithDelta(0.10, $shippingItem['Qty'], 0.0001);
    }

    /**
     * Skip prior-refunded items (qtyRefunded subtracted from qtyOrdered).
     */
    public function testSkipsAlreadyFullyRefundedItems()
    {
        $itemA = $this->makeItem('SKU-A', 2, 2, 50.0); // fully refunded already
        $itemB = $this->makeItem('SKU-B', 1, 0, 30.0); // 1 remaining
        $order = $this->makeOrder([$itemA, $itemB], 0.0, 0.0, 0.0, 130.0);
        $cm = $this->makeCreditmemo($order, 1.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
        $this->assertCount(1, $result['cartItems']);
        $this->assertEquals('SKU-B', $result['cartItems'][0]['ItemID']);
    }

    /**
     * Adjustment within $0.01 of remaining total → full return fallback.
     */
    public function testFullReturnFallbackWhenAdjustmentEqualsRemaining()
    {
        $item = $this->makeItem('SKU-A', 1, 0, 100.0);
        $order = $this->makeOrder([$item], 0.0, 0.0, 0.0, 100.0);
        // adjustment = 100 (matches remainingTotal exactly)
        $cm = $this->makeCreditmemo($order, 100.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_FULL_RETURN, $result['action']);
        $this->assertEmpty($result['cartItems']);
    }

    /**
     * Adjustment 99.99 vs remaining 100.00 → still triggers full return (within $0.01).
     */
    public function testFullReturnFallbackWithinPennyTolerance()
    {
        $item = $this->makeItem('SKU-A', 1, 0, 100.0);
        $order = $this->makeOrder([$item], 0.0, 0.0, 0.0, 100.0);
        $cm = $this->makeCreditmemo($order, 99.99);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_FULL_RETURN, $result['action']);
    }

    /**
     * Adjustment 99.50 vs remaining 100.00 → distribute, NOT full return.
     */
    public function testDistributeJustOutsidePennyTolerance()
    {
        $item = $this->makeItem('SKU-A', 1, 0, 100.0);
        $order = $this->makeOrder([$item], 0.0, 0.0, 0.0, 100.0);
        $cm = $this->makeCreditmemo($order, 99.50);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
    }

    /**
     * Adjustment below MIN_ADJUSTMENT → skip entirely.
     */
    public function testSkipsForNegligibleAdjustment()
    {
        $item = $this->makeItem('SKU-A', 1, 0, 100.0);
        $order = $this->makeOrder([$item], 0.0, 0.0, 0.0, 100.0);
        $cm = $this->makeCreditmemo($order, 0.005);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_SKIP, $result['action']);
        $this->assertEmpty($result['cartItems']);
    }

    /**
     * No remaining items AND no remaining shipping → skip.
     */
    public function testSkipsWhenNoRemainingItemsOrShipping()
    {
        $itemA = $this->makeItem('SKU-A', 2, 2, 50.0);     // fully refunded
        $order = $this->makeOrder([$itemA], 35.0, 35.0, 0.0, 100.0); // shipping fully refunded
        $cm = $this->makeCreditmemo($order, 5.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_SKIP, $result['action']);
    }

    /**
     * Discount per unit is subtracted from the effective price used in distribution.
     */
    public function testAppliesDiscountPerUnit()
    {
        // qty=2, price=50, discount=20 → effectivePrice = 50 - (20/2) = 40
        $item = $this->makeItem('SKU-A', 2, 0, 50.0, 20.0);
        $order = $this->makeOrder([$item], 0.0, 0.0, 0.0, 80.0);
        $cm = $this->makeCreditmemo($order, 8.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
        $this->assertEquals(40.0, $result['cartItems'][0]['Price']);
    }

    /**
     * Quantities should be rounded to 4 decimal places.
     */
    public function testQuantitiesRoundedTo4Decimals()
    {
        $item = $this->makeItem('SKU-A', 7, 0, 13.0); // remainingTotal = 91
        $order = $this->makeOrder([$item], 0.0, 0.0, 0.0, 91.0);
        $cm = $this->makeCreditmemo($order, 1.0);

        $result = $this->distributor->distribute($cm);

        $qty = $result['cartItems'][0]['Qty'];
        // The string repr of qty should not exceed 4 decimal digits.
        $this->assertMatchesRegularExpression('/^\d+(\.\d{1,4})?$/', (string) $qty);
    }

    /**
     * Child items (configurable / bundle children) should be skipped.
     */
    public function testSkipsChildItems()
    {
        $parent = $this->createMock(Item::class);
        $parent->method('getSku')->willReturn('PARENT');
        $parent->method('getQtyOrdered')->willReturn(1.0);
        $parent->method('getQtyRefunded')->willReturn(0.0);
        $parent->method('getPrice')->willReturn(100.0);
        $parent->method('getDiscountAmount')->willReturn(0.0);
        $parent->method('getParentItem')->willReturn(null);

        $child = $this->createMock(Item::class);
        $child->method('getSku')->willReturn('CHILD');
        $child->method('getQtyOrdered')->willReturn(1.0);
        $child->method('getQtyRefunded')->willReturn(0.0);
        $child->method('getPrice')->willReturn(0.0);
        $child->method('getDiscountAmount')->willReturn(0.0);
        $child->method('getParentItem')->willReturn($parent); // child of parent

        $order = $this->makeOrder([$parent, $child], 0.0, 0.0, 0.0, 100.0);
        $cm = $this->makeCreditmemo($order, 5.0);

        $result = $this->distributor->distribute($cm);

        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $result['action']);
        $this->assertCount(1, $result['cartItems']);
        $this->assertEquals('PARENT', $result['cartItems'][0]['ItemID']);
    }
}
