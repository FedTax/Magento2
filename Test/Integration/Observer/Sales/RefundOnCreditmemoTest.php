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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Observer\Sales;

use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that creating a credit memo for an invoiced order fires
 * sales_order_creditmemo_refund, which Magento routes to
 * Observer\Sales\Refund, which calls Api::returnOrder() -> the Returned SOAP
 * operation — and that the cart items in the SOAP payload reflect the credit
 * memo's line items.
 */
class RefundOnCreditmemoTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
    }

    public function testRefundObserverFiresOnCreditmemoCreation(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $this->payInvoice($order);

        // Capture + lookup/verify chatter is irrelevant to the refund assertion.
        $soap->resetCalls();

        $this->refundOrder($order);

        $this->assertSame(
            1,
            $soap->callCount('Returned'),
            'Creating a credit memo should call the Returned SOAP operation exactly once '
            . 'via Observer\\Sales\\Refund.'
        );

        $args = $soap->firstCallArgs('Returned');
        $this->assertSame(
            $order->getIncrementId(),
            $args['orderID'] ?? null,
            'The returned orderID should match the refunded order.'
        );

        $cartItems = $args['cartItems'] ?? [];
        $this->assertNotEmpty($cartItems, 'The Returned payload should carry the refunded line items.');

        $itemIds = array_column($cartItems, 'ItemID');
        $this->assertContains(
            self::TEST_PRODUCT_SKU,
            $itemIds,
            'The refunded product should appear in the Returned cart items.'
        );

        // Every line in the payload must correspond to a real order line
        // (the product) or the shipping pseudo-item — nothing spurious.
        foreach ($itemIds as $itemId) {
            $this->assertContains(
                $itemId,
                [self::TEST_PRODUCT_SKU, 'shipping'],
                "Unexpected ItemID '$itemId' in the Returned payload."
            );
        }

        $productLine = null;
        foreach ($cartItems as $item) {
            if (($item['ItemID'] ?? null) === self::TEST_PRODUCT_SKU) {
                $productLine = $item;
                break;
            }
        }
        $this->assertNotNull($productLine, 'Product line missing from Returned payload.');
        $this->assertEquals(
            1,
            $productLine['Qty'] ?? null,
            'The full credit memo refunds the single ordered unit.'
        );
    }
}
