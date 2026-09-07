<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\RetailDeliveryFee;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\CartItemResponseHandler;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * The Colorado RDF line in the lookup request, on both transports, and the
 * response-side routing of its echo.
 *
 * The discount test is the load-bearing one: the module nets discounts into
 * line prices, which TaxCloud's own discount exclusion for this TIC cannot
 * see — so the fee line staying at full amount is OUR invariant to hold.
 */
#[AllowMockObjectsWithoutExpectations]
class LookupLineTest extends TestCase
{
    private const STORE_ID = 7;

    /** @var FeeService|\PHPUnit\Framework\MockObject\MockObject */
    private $feeService;

    /** @var ProductTicService|\PHPUnit\Framework\MockObject\MockObject */
    private $ticService;

    /** @var RequestBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->feeService = $this->createMock(FeeService::class);
        $this->feeService->method('getAmount')->willReturn(0.31);
        $this->feeService->method('getTic')->willReturn('11098');

        $this->ticService = $this->createMock(ProductTicService::class);
        $this->ticService->method('getProductTic')->willReturn('00000');
        $this->ticService->method('getShippingTic')->willReturn('11010');

        $this->builder = new RequestBuilder(
            $this->createMock(TaxcloudConfig::class),
            $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createMock(\Magento\Directory\Model\RegionFactory::class),
            $this->ticService,
            $this->createMock(RefundDistributor::class),
            $this->feeService,
            new NullLogger()
        );
    }

    public function testEligibleCartCarriesExactlyOneFeeLine()
    {
        $this->feeService->method('isEligible')->willReturn(true);

        $built = $this->buildCart();

        $feeLines = $this->feeLines($built['cartItems']);
        $this->assertCount(1, $feeLines);
        $fee = array_values($feeLines)[0];
        $this->assertSame('11098', $fee['TIC']);
        $this->assertSame(0.31, $fee['Price']);
        $this->assertSame(1, $fee['Qty']);
        // The sentinel index maps to no quote item.
        $this->assertArrayNotHasKey($fee['Index'], $built['indexedItems']);
    }

    public function testIneligibleCartCarriesNoFeeLine()
    {
        $this->feeService->method('isEligible')->willReturn(false);

        $built = $this->buildCart();

        $this->assertCount(0, $this->feeLines($built['cartItems']));
    }

    public function testDiscountNetsIntoProductLineButNeverTheFeeLine()
    {
        $this->feeService->method('isEligible')->willReturn(true);

        $built = $this->buildCart(10.00); // $10 discount on the product line

        $byId = [];
        foreach ($built['cartItems'] as $line) {
            $byId[$line['ItemID']] = $line;
        }
        $this->assertSame(40.00, (float) $byId['SKU-WIDGET']['Price']);
        $this->assertSame(0.31, (float) $byId[FeeService::ITEM_ID]['Price']);
    }

    public function testRestDelegationMapsTheFeeLineToV3Shape()
    {
        $this->feeService->method('isEligible')->willReturn(true);

        $restBuilder = new RestRequestBuilder(
            $this->createMock(TaxcloudConfig::class),
            $this->builder,
            $this->ticService
        );

        [$itemsByType, $keyed, $address] = $this->cartInputs();
        $built = $restBuilder->buildCartLineItems($itemsByType, $keyed, $address, self::STORE_ID);

        $fee = null;
        foreach ($built['lineItems'] as $line) {
            if ($line['itemId'] === FeeService::ITEM_ID) {
                $fee = $line;
            }
        }
        $this->assertNotNull($fee);
        $this->assertSame(11098, $fee['tic']);
        $this->assertSame(0.31, $fee['price']);
        $this->assertSame(1.0, $fee['quantity']);
    }

    public function testResponseEchoOfTheFeeLineRoutesNowhere()
    {
        $cartItems = [
            ['ItemID' => 'SKU-WIDGET', 'Index' => 0, 'TIC' => '00000', 'Price' => 50.0, 'Qty' => 1],
            ['ItemID' => 'shipping', 'Index' => 1, 'TIC' => '11010', 'Price' => 10.0, 'Qty' => 1],
            ['ItemID' => FeeService::ITEM_ID, 'Index' => 2, 'TIC' => '11098', 'Price' => 0.31, 'Qty' => 1],
        ];
        $indexedItems = [0 => 'code-widget'];
        $result = [Api::ITEM_TYPE_PRODUCT => [], Api::ITEM_TYPE_SHIPPING => 0];

        // Even a hypothetical non-zero echo on the fee line must be ignored.
        $responses = [
            ['CartItemIndex' => 0, 'TaxAmount' => 4.58],
            ['CartItemIndex' => 1, 'TaxAmount' => 0.83],
            ['CartItemIndex' => 2, 'TaxAmount' => 0.99],
        ];

        (new CartItemResponseHandler())
            ->applyProcessedItemsToResult($responses, $cartItems, $indexedItems, $result);

        $this->assertSame([Api::ITEM_TYPE_PRODUCT => ['code-widget' => 4.58], Api::ITEM_TYPE_SHIPPING => 0.83], [
            Api::ITEM_TYPE_PRODUCT => $result[Api::ITEM_TYPE_PRODUCT],
            Api::ITEM_TYPE_SHIPPING => $result[Api::ITEM_TYPE_SHIPPING],
        ]);
    }

    /**
     * @param float $discountAmount
     * @return array
     */
    private function buildCart(float $discountAmount = 0.0)
    {
        [$itemsByType, $keyed, $address] = $this->cartInputs($discountAmount);

        return $this->builder->buildLookupCartItems($itemsByType, $keyed, $address, self::STORE_ID);
    }

    /**
     * One $50 product (optionally discounted) plus $10 shipping.
     *
     * @param float $discountAmount
     * @return array [itemsByType, keyedAddressItems, address]
     */
    private function cartInputs(float $discountAmount = 0.0)
    {
        $product = $this->getMockBuilder(Dbl\ProductDouble::class)
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $item = $this->getMockBuilder(Dbl\QuoteItemDouble::class)
            ->onlyMethods(['getSku', 'getQty', 'getPrice', 'getProduct', 'getParentItem', 'getDiscountAmount'])
            ->getMock();
        $item->method('getSku')->willReturn('SKU-WIDGET');
        $item->method('getQty')->willReturn(1.0);
        $item->method('getPrice')->willReturn(50.00);
        $item->method('getProduct')->willReturn($product);
        $item->method('getParentItem')->willReturn(null);
        $item->method('getDiscountAmount')->willReturn($discountAmount);

        $shippingDetail = $this->getMockBuilder(Dbl\ItemDetailsDouble::class)
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $shippingDetail->method('getRowTotal')->willReturn(10.00);

        $itemsByType = [
            RequestBuilder::ITEM_TYPE_PRODUCT => [
                'code-widget' => [Api::KEY_ITEM => 'detail', Api::KEY_BASE_ITEM => 'detail'],
            ],
            RequestBuilder::ITEM_TYPE_SHIPPING => [
                'code-shipping' => [Api::KEY_ITEM => $shippingDetail],
            ],
        ];
        $keyed = ['code-widget' => $item];

        $address = $this->getMockBuilder(Dbl\QuoteAddressDouble::class)
            ->onlyMethods(['getShippingAmount'])
            ->getMock();
        $address->method('getShippingAmount')->willReturn(10.00);

        return [$itemsByType, $keyed, $address];
    }

    /**
     * @param array $cartItems
     * @return array
     */
    private function feeLines(array $cartItems)
    {
        return array_filter($cartItems, static function ($line) {
            return $line['ItemID'] === FeeService::ITEM_ID;
        });
    }
}
