<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;

/**
 * Covers the request payload assembly extracted from Model\Api: origin/
 * destination construction, order cart items, and the per-operation param
 * arrays (order details, verify address, authorize/capture, return, exempt
 * lookup).
 */
#[AllowMockObjectsWithoutExpectations]
class RequestBuilderTest extends TestCase
{
    private $config;
    private $scopeConfig;
    private $regionFactory;
    private $productTicService;
    private $refundDistributor;
    private RequestBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('getApiId')->willReturn('login-id');
        $this->config->method('getApiKey')->willReturn('secret-key');
        $this->config->method('getGuestCustomerId')->willReturn('-1');

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->regionFactory = $this->createMock(RegionFactory::class);
        $this->productTicService = $this->createMock(ProductTicService::class);
        $this->refundDistributor = $this->createMock(RefundDistributor::class);

        $this->builder = new RequestBuilder(
            $this->config,
            $this->scopeConfig,
            $this->regionFactory,
            $this->productTicService,
            $this->refundDistributor,
            new NullLogger()
        );
    }

    private function stubOriginConfig(string $postcode): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['shipping/origin/postcode', ScopeInterface::SCOPE_STORE, null, $postcode],
            ['shipping/origin/street_line1', ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
            ['shipping/origin/street_line2', ScopeInterface::SCOPE_STORE, null, ''],
            ['shipping/origin/city', ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
            ['shipping/origin/region_id', ScopeInterface::SCOPE_STORE, null, '1'],
        ]);

        // Region's accessors are magic (via AbstractModel::__call), so mock the
        // test double that declares load()/getCode() as real methods.
        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('IL');
        $this->regionFactory->method('create')->willReturn($region);
    }

    public function testBuildOriginReturnsAddressForValidZip()
    {
        $this->stubOriginConfig('60005');

        $origin = $this->builder->buildOrigin();

        $this->assertSame('71 W Seegers Rd', $origin['Address1']);
        $this->assertSame('Arlington Heights', $origin['City']);
        $this->assertSame('IL', $origin['State']);
        $this->assertSame('60005', $origin['Zip5']);
    }

    public function testBuildOriginReturnsNullForInvalidZip()
    {
        $this->stubOriginConfig('not-a-zip');

        $this->assertNull($this->builder->buildOrigin());
    }

    public function testBuildCartItemsFromOrderIncludesItemsAndShipping()
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getQtyOrdered')->willReturn(2.0);
        $item->method('getPrice')->willReturn(10.0);
        $item->method('getDiscountAmount')->willReturn(4.0);
        $item->method('getSku')->willReturn('SKU-1');

        $this->productTicService->method('getProductTic')->willReturn('20000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $order = $this->createMock(Order::class);
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getBaseShippingAmount')->willReturn(5.99);

        $cartItems = $this->builder->buildCartItemsFromOrder($order);

        $this->assertCount(2, $cartItems);
        $this->assertSame('SKU-1', $cartItems[0]['ItemID']);
        // price 10 - discountPerUnit (4/2 = 2) = 8
        $this->assertSame(8.0, $cartItems[0]['Price']);
        $this->assertSame(2.0, $cartItems[0]['Qty']);
        $this->assertSame('shipping', $cartItems[1]['ItemID']);
        $this->assertSame(5.99, $cartItems[1]['Price']);
    }

    public function testBuildCartItemsFromOrderSkipsZeroQtyAndNoShipping()
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getQtyOrdered')->willReturn(0.0);

        $order = $this->createMock(Order::class);
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getBaseShippingAmount')->willReturn(0.0);

        $this->assertSame([], $this->builder->buildCartItemsFromOrder($order));
    }

    /**
     * An order item stubbed for the return / cancel payloads.
     */
    private function returnOrderItem(
        string $sku,
        $qtyOrdered,
        $price,
        $discount = 0.0,
        $parentItemId = null,
        bool $childrenCalculated = false,
        array $children = []
    ) {
        $item = $this->createMock(OrderItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getPrice')->willReturn($price);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->method('getChildrenItems')->willReturn($children);

        return $item;
    }

    /**
     * The order side stores a bundle child's qty absolutely (qty_ordered 2 for
     * the same line the quote stored as 1), so the children are emitted as they
     * stand — multiplying here would double it back.
     */
    public function testBuildCartItemsFromOrderExpandsDynamicBundleIntoChildren()
    {
        $childA = $this->returnOrderItem('child-a', 2.0, 10.0, 0.0, 54, true);
        $childB = $this->returnOrderItem('child-b', 2.0, 10.0, 0.0, 54, true);
        $parent = $this->returnOrderItem('bundle-sku', 2.0, 20.0, 0.0, null, true, [$childA, $childB]);

        $this->productTicService->method('getProductTic')->willReturn('20000');

        $order = $this->createMock(Order::class);
        $order->method('getAllVisibleItems')->willReturn([$parent]);
        $order->method('getBaseShippingAmount')->willReturn(0.0);

        $cartItems = $this->builder->buildCartItemsFromOrder($order);

        $this->assertCount(2, $cartItems, 'the bundle wrapper must not add a third line');
        $this->assertSame(['child-a', 'child-b'], array_column($cartItems, 'ItemID'));
        $this->assertSame([2.0, 2.0], array_column($cartItems, 'Qty'));
        $this->assertSame([0, 1], array_column($cartItems, 'Index'));
    }

    public function testBuildCartItemsFromOrderFallsBackToBundleParentWithoutChildren()
    {
        $parent = $this->returnOrderItem('bundle-sku', 2.0, 20.0, 0.0, null, true, []);

        $this->productTicService->method('getProductTic')->willReturn('20000');

        $order = $this->createMock(Order::class);
        $order->method('getAllVisibleItems')->willReturn([$parent]);
        $order->method('getBaseShippingAmount')->willReturn(0.0);

        $cartItems = $this->builder->buildCartItemsFromOrder($order);

        $this->assertCount(1, $cartItems);
        $this->assertSame('bundle-sku', $cartItems[0]['ItemID']);
    }

    public function testBuildDestinationFromOrderReturnsNullForNonUs()
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getPostcode')->willReturn('M5V 2T6');
        $address->method('getCountryId')->willReturn('CA');

        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn($address);

        $this->assertNull($this->builder->buildDestinationFromOrder($order));
    }

    public function testBuildDestinationFromOrderBuildsAddress()
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getStreet')->willReturn(['405 Victorian Ln', 'Apt 2']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionCode')->willReturn('GA');

        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn($address);

        $destination = $this->builder->buildDestinationFromOrder($order);

        $this->assertSame('405 Victorian Ln', $destination['Address1']);
        $this->assertSame('Apt 2', $destination['Address2']);
        $this->assertSame('Duluth', $destination['City']);
        $this->assertSame('GA', $destination['State']);
        $this->assertSame('30097', $destination['Zip5']);
    }

    /**
     * Stub the directory lookup, so a region ID resolves to $code (null models
     * an ID with no matching region row).
     */
    private function stubRegionLookup(?string $code): void
    {
        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn($code);
        $this->regionFactory->method('create')->willReturn($region);
    }

    private function quoteAddress(?string $regionCode, $regionId)
    {
        $address = $this->createMock(QuoteAddress::class);
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('Albany');
        $address->method('getRegionCode')->willReturn($regionCode);
        $address->method('getRegionId')->willReturn($regionId);

        return $address;
    }

    private function orderAddress(?string $regionCode, $regionId)
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getPostcode')->willReturn('12207');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('Albany');
        $address->method('getRegionCode')->willReturn($regionCode);
        $address->method('getRegionId')->willReturn($regionId);

        return $address;
    }

    private function orderWith($address)
    {
        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn($address);

        return $order;
    }

    private function lookupState($address): string
    {
        return $this->builder->buildLookupDestination($address, ['Zip5' => '12207', 'Zip4' => null])['State'];
    }

    /**
     * A code on the address is used directly, with no directory query issued.
     */
    public function testRegionCodeOnAddressIsUsedWithoutADirectoryLookup()
    {
        $this->regionFactory->expects($this->never())->method('create');

        $this->assertSame('NY', $this->lookupState($this->quoteAddress('NY', 43)));
        $this->assertSame(
            'NY',
            $this->builder->buildDestinationFromOrder($this->orderWith($this->orderAddress('NY', 43)))['State']
        );
    }

    /**
     * An address carrying only a region ID resolves through the directory.
     */
    public function testRegionIdAloneResolvesThroughTheDirectory()
    {
        $this->stubRegionLookup('NY');

        $this->assertSame('NY', $this->lookupState($this->quoteAddress(null, 43)));
        $this->assertSame(
            'NY',
            $this->builder->buildDestinationFromOrder($this->orderWith($this->orderAddress(null, 43)))['State']
        );
    }

    /**
     * When both are present, the code on the address wins.
     */
    public function testRegionCodeWinsOverRegionIdWhenBothPresent()
    {
        // Directory would answer 'CA' — proving the ID was not consulted.
        $this->stubRegionLookup('CA');

        $this->assertSame('NY', $this->lookupState($this->quoteAddress('NY', 43)));
        $this->assertSame(
            'NY',
            $this->builder->buildDestinationFromOrder($this->orderWith($this->orderAddress('NY', 43)))['State']
        );
    }

    /**
     * An ID with no matching region row resolves to '' rather than reaching the
     * request as null.
     *
     * @dataProvider unresolvableRegionProvider
     */
    #[DataProvider('unresolvableRegionProvider')]
    public function testUnresolvableRegionYieldsEmptyString(?string $regionCode, $regionId, string $message)
    {
        $this->stubRegionLookup(null);

        $state = $this->lookupState($this->quoteAddress($regionCode, $regionId));

        $this->assertSame('', $state, $message);
        $this->assertIsString($state, 'State must never reach the request as null');
    }

    public static function unresolvableRegionProvider(): array
    {
        return [
            'invalid id' => [null, 999999, 'unknown region id resolves to empty string'],
            'null id' => [null, null, 'no region at all resolves to empty string'],
            'zero id' => [null, 0, 'region id 0 means "unset" and resolves to empty string'],
            'empty code' => ['', 43, 'empty code falls through to the directory lookup'],
        ];
    }

    public function testBuildLookupCartItemsIndexesProductsAndShipping()
    {
        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getTaxClassId'])->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $quoteItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getSku', 'getPrice', 'getDiscountAmount', 'getQty'])
            ->getMock();
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getSku')->willReturn('SKU-1');
        $quoteItem->method('getPrice')->willReturn(10.0);
        $quoteItem->method('getDiscountAmount')->willReturn(2.0);
        $quoteItem->method('getQty')->willReturn(2.0);

        $shipRow = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getRowTotal'])->getMock();
        $shipRow->method('getRowTotal')->willReturn(5.0);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getShippingAmount'])->getMock();
        $address->method('getShippingAmount')->willReturn(0.0);

        $this->productTicService->method('getProductTic')->willReturn('20000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $itemsByType = [
            'product' => ['p1' => ['item' => 'x']],
            'shipping' => ['shipping' => ['item' => $shipRow]],
        ];

        $built = $this->builder->buildLookupCartItems($itemsByType, ['p1' => $quoteItem], $address);

        $this->assertSame('SKU-1', $built['cartItems'][0]['ItemID']);
        $this->assertSame(9.0, $built['cartItems'][0]['Price']); // 10 - 2/2
        $this->assertSame(['p1'], array_values($built['indexedItems']));
        $this->assertSame('shipping', $built['cartItems'][1]['ItemID']);
        $this->assertSame(5.0, $built['cartItems'][1]['Price']);
    }

    public function testBuildLookupCartItemsSkipsNoneTaxClassProducts()
    {
        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getTaxClassId'])->getMock();
        $product->method('getTaxClassId')->willReturn('0');

        $quoteItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getProduct'])->getMock();
        $quoteItem->method('getProduct')->willReturn($product);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getShippingAmount'])->getMock();

        $built = $this->builder->buildLookupCartItems(
            ['product' => ['p1' => ['item' => 'x']]],
            ['p1' => $quoteItem],
            $address
        );

        $this->assertSame([], $built['cartItems']);
    }

    /**
     * A quote item stubbed with everything buildLookupCartItems() reads,
     * including the composite hierarchy.
     */
    private function lookupQuoteItem(
        string $sku,
        $qty,
        $price,
        $discount = 0.0,
        $parent = null,
        $children = [],
        bool $childrenCalculated = false
    ) {
        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getTaxClassId'])->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $item = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getProduct',
                'getSku',
                'getPrice',
                'getDiscountAmount',
                'getQty',
                'getParentItem',
                'getChildren',
                'isChildrenCalculated',
            ])
            ->getMock();
        $item->method('getProduct')->willReturn($product);
        $item->method('getSku')->willReturn($sku);
        $item->method('getPrice')->willReturn($price);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getQty')->willReturn($qty);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        // A parent and its children reference each other, so the children of a
        // parent built first arrive late — accept a provider for that case.
        if (is_callable($children)) {
            $item->method('getChildren')->willReturnCallback($children);
        } else {
            $item->method('getChildren')->willReturn($children);
        }

        return $item;
    }

    /**
     * A dynamic-price bundle as the quote stores it: the parent priced at the
     * sum of its selections, the child holding the real price at a qty that is
     * per-parent. Returns [parent, child].
     */
    private function dynamicBundleQuoteItems($parentQty, $childQty, $childPrice, $childDiscount = 0.0)
    {
        $children = [];

        $parent = $this->lookupQuoteItem(
            'bundle-sku',
            $parentQty,
            $childPrice * $childQty,
            0.0,
            null,
            function () use (&$children) {
                return $children;
            },
            true
        );
        $child = $this->lookupQuoteItem('child-sku', $childQty, $childPrice, $childDiscount, $parent);
        $children[] = $child;

        return [$parent, $child];
    }

    private function lookupAddress()
    {
        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getShippingAmount'])->getMock();
        $address->method('getShippingAmount')->willReturn(0.0);

        return $address;
    }

    /**
     * Wrap quote items (keyed by tax-calculation id) in the two arguments
     * buildLookupCartItems() takes.
     */
    private function buildLookupFor(array $keyedItems)
    {
        $this->productTicService->method('getProductTic')->willReturn('20000');

        $itemsByType = ['product' => []];
        foreach (array_keys($keyedItems) as $code) {
            $itemsByType['product'][$code] = ['item' => 'x'];
        }

        return $this->builder->buildLookupCartItems($itemsByType, $keyedItems, $this->lookupAddress());
    }

    /**
     * A qty-2 dynamic bundle holding one $10 selection: the quote stores the
     * child at qty 1 against a $20 row total, so the cart line has to say 2.
     * Sending 1 is what under-charged the customer by half.
     */
    public function testBuildLookupCartItemsMultipliesBundleChildQtyByParentQty()
    {
        [$parent, $child] = $this->dynamicBundleQuoteItems(2.0, 1.0, 10.0);

        $built = $this->buildLookupFor(['bundle' => $parent, 'child' => $child]);

        $this->assertCount(1, $built['cartItems'], 'the parent must not be a line of its own');
        $this->assertSame('child-sku', $built['cartItems'][0]['ItemID']);
        $this->assertSame(2.0, $built['cartItems'][0]['Qty']);
        $this->assertSame(10.0, $built['cartItems'][0]['Price']);
        $this->assertSame(['child'], array_values($built['indexedItems']));
    }

    public function testBuildLookupCartItemsSpreadsBundleChildDiscountOverEffectiveQty()
    {
        // $4 off the row, which is 2 units — $2 each, not $4.
        [$parent, $child] = $this->dynamicBundleQuoteItems(2.0, 1.0, 10.0, 4.0);

        $built = $this->buildLookupFor(['bundle' => $parent, 'child' => $child]);

        $this->assertSame(8.0, $built['cartItems'][0]['Price']);
        $this->assertSame(2.0, $built['cartItems'][0]['Qty']);
    }

    /**
     * A configurable (and a fixed-price bundle) prices the parent and zeroes the
     * children, so the parent stays the cart line and its qty is taken as-is.
     */
    public function testBuildLookupCartItemsKeepsConfigurableParentLine()
    {
        $child = $this->lookupQuoteItem('variant-sku', 1.0, 0.0);
        $parent = $this->lookupQuoteItem('config-sku', 2.0, 10.0, 0.0, null, [$child], false);

        $built = $this->buildLookupFor(['config' => $parent]);

        $this->assertCount(1, $built['cartItems']);
        $this->assertSame('config-sku', $built['cartItems'][0]['ItemID']);
        $this->assertSame(2.0, $built['cartItems'][0]['Qty']);
    }

    public function testBuildLookupCartItemsSurvivesZeroQty()
    {
        $item = $this->lookupQuoteItem('SKU-1', 0.0, 10.0, 5.0);

        $built = $this->buildLookupFor(['p1' => $item]);

        // Regression: the discount used to be divided by qty unguarded.
        $this->assertSame(10.0, $built['cartItems'][0]['Price']);
        $this->assertSame(0.0, $built['cartItems'][0]['Qty']);
    }

    public function testBuildLookupParams()
    {
        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getId')->willReturn(999);

        $params = $this->builder->buildLookupParams(
            $customer,
            $quote,
            [['ItemID' => 'SKU-1']],
            ['State' => 'IL'],
            ['State' => 'GA'],
            'cert-1'
        );

        $this->assertSame(7, $params['customerID']);
        $this->assertSame(999, $params['cartID']);
        $this->assertSame(['State' => 'GA'], $params['destination']);
        $this->assertFalse($params['deliveredBySeller']);
        $this->assertSame('cert-1', $params['exemptCert']['CertificateID']);
    }

    public function testBuildOrderDetailsParams()
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');

        $this->assertSame(
            [
                'apiLoginID' => 'login-id',
                'apiKey' => 'secret-key',
                'orderID' => '100000001',
            ],
            $this->builder->buildOrderDetailsParams($order)
        );
    }

    public function testBuildVerifyAddressParamsMapsToLowercaseKeys()
    {
        $params = $this->builder->buildVerifyAddressParams([
            'Address1' => '1 Infinite Loop',
            'Address2' => '',
            'City' => 'Cupertino',
            'State' => 'CA',
            'Zip5' => '95014',
            'Zip4' => '',
        ]);

        $this->assertSame('login-id', $params['apiLoginID']);
        $this->assertSame('1 Infinite Loop', $params['address1']);
        $this->assertSame('Cupertino', $params['city']);
        $this->assertSame('CA', $params['state']);
        $this->assertSame('95014', $params['zip5']);
    }

    public function testBuildAuthorizeCaptureParamsDefaultsCartIdToQuoteId()
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getQuoteId')->willReturn(555);
        $order->method('getIncrementId')->willReturn('100000002');

        $params = $this->builder->buildAuthorizeCaptureParams($order);

        $this->assertSame(7, $params['customerID']);
        $this->assertSame(555, $params['cartID']);
        $this->assertSame('100000002', $params['orderID']);
        $this->assertArrayHasKey('dateAuthorized', $params);
        $this->assertArrayHasKey('dateCaptured', $params);
    }

    public function testBuildAuthorizeCaptureParamsHonorsCartIdOverrideAndGuestFallback()
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getIncrementId')->willReturn('100000003');

        $params = $this->builder->buildAuthorizeCaptureParams($order, '100000003-exempt');

        $this->assertSame('-1', $params['customerID'], 'guest customer id fallback');
        $this->assertSame('100000003-exempt', $params['cartID']);
    }

    /**
     * The SOAP capture files under the date of the document that triggered it,
     * not the clock at the moment the params are built. Both date fields move
     * together — they have always carried the same instant, and splitting them
     * would invent an asymmetry the API never had.
     */
    public function testBuildAuthorizeCaptureParamsFilesUnderTheSuppliedCompletionTime()
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getQuoteId')->willReturn(555);
        $order->method('getIncrementId')->willReturn('100000002');

        $params = $this->builder->buildAuthorizeCaptureParams($order, null, '2026-03-14 09:15:00');

        $expected = date('c', strtotime('2026-03-14 09:15:00 UTC'));
        $this->assertSame($expected, $params['dateAuthorized']);
        $this->assertSame($expected, $params['dateCaptured']);
    }

    /**
     * The rendering stays the offset-bearing ISO-8601 form this transport has
     * always sent; only which instant it names changes.
     */
    public function testBuildAuthorizeCaptureParamsKeepsTheIso8601OffsetFormat()
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('100000002');

        $params = $this->builder->buildAuthorizeCaptureParams($order, null, '2026-03-14 09:15:00');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
            $params['dateCaptured']
        );
    }

    /**
     * No completion time falls back to now — the real path at order placement,
     * where the order is not yet persisted and has no created_at.
     */
    public function testBuildAuthorizeCaptureParamsFallsBackToNowWithoutACompletionTime()
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('100000002');

        $before = time();
        $params = $this->builder->buildAuthorizeCaptureParams($order, null, null);
        $after = time();

        $stamped = strtotime($params['dateCaptured']);
        $this->assertGreaterThanOrEqual($before, $stamped);
        $this->assertLessThanOrEqual($after, $stamped);
        $this->assertSame($params['dateAuthorized'], $params['dateCaptured']);
    }

    public function testBuildReturnParams()
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000004');
        $cartItems = [['ItemID' => 'SKU-1', 'Index' => 0, 'TIC' => '20000', 'Price' => 8.0, 'Qty' => 1]];

        $params = $this->builder->buildReturnParams($order, $cartItems);

        $this->assertSame('100000004', $params['orderID']);
        $this->assertSame($cartItems, $params['cartItems']);
        $this->assertFalse($params['returnCoDeliveryFeeWhenNoCartItems']);
        $this->assertArrayHasKey('returnedDate', $params);
    }

    public function testBuildReturnCartItemsFromCreditmemoItemsAndShipping()
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getSku')->willReturn('SKU-1');

        $creditItem = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $creditItem->method('getQty')->willReturn(2.0);
        $creditItem->method('getOrderItem')->willReturn($orderItem);
        $creditItem->method('getPrice')->willReturn(10.0);
        $creditItem->method('getDiscountAmount')->willReturn(4.0);

        $this->productTicService->method('getProductTic')->willReturn('20000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $order = $this->createMock(Order::class);
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([$creditItem]);
        $creditmemo->method('getShippingAmount')->willReturn(5.99);

        $return = $this->builder->buildReturnCartItems($creditmemo);

        $this->assertFalse($return['skip']);
        $this->assertFalse($return['wasTaxOnlyRefund']);
        $this->assertSame('SKU-1', $return['cartItems'][0]['ItemID']);
        $this->assertSame(8.0, $return['cartItems'][0]['Price']); // 10 - 4/2
        $this->assertSame('shipping', $return['cartItems'][1]['ItemID']);
    }

    /**
     * Refunding a dynamic bundle: the children are credit-memo items in their
     * own right, so returning the wrapper as well would hand back tax on twice
     * the basis that was authorized.
     */
    public function testBuildReturnCartItemsSkipsDynamicBundleParent()
    {
        $parentOrderItem = $this->returnOrderItem('bundle-sku', 2.0, 20.0, 0.0, null, true);
        $childOrderItem = $this->returnOrderItem('child-sku', 2.0, 10.0, 0.0, 54, true);

        $parentCredit = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $parentCredit->method('getQty')->willReturn(2.0);
        $parentCredit->method('getOrderItem')->willReturn($parentOrderItem);
        $parentCredit->method('getPrice')->willReturn(20.0);
        $parentCredit->method('getDiscountAmount')->willReturn(0.0);

        $childCredit = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $childCredit->method('getQty')->willReturn(2.0);
        $childCredit->method('getOrderItem')->willReturn($childOrderItem);
        $childCredit->method('getPrice')->willReturn(10.0);
        $childCredit->method('getDiscountAmount')->willReturn(0.0);

        $this->productTicService->method('getProductTic')->willReturn('20000');

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($this->createMock(Order::class));
        $creditmemo->method('getAllItems')->willReturn([$parentCredit, $childCredit]);
        $creditmemo->method('getShippingAmount')->willReturn(0.0);

        $return = $this->builder->buildReturnCartItems($creditmemo);

        $this->assertCount(1, $return['cartItems']);
        $this->assertSame('child-sku', $return['cartItems'][0]['ItemID']);
        $this->assertSame(2.0, $return['cartItems'][0]['Qty'], 'order-side qty is already absolute');
        $this->assertSame(0, $return['cartItems'][0]['Index']);
    }

    public function testBuildReturnCartItemsDetectsTaxOnlyRefund()
    {
        $order = $this->createMock(Order::class);
        $order->method('getBaseTaxAmount')->willReturn(5.0);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0.0);
        $creditmemo->method('getBaseGrandTotal')->willReturn(5.0);

        $return = $this->builder->buildReturnCartItems($creditmemo);

        $this->assertTrue($return['wasTaxOnlyRefund']);
        $this->assertFalse($return['skip']);
        $this->assertSame([], $return['cartItems']);
    }

    public function testBuildReturnCartItemsSkipsOnDistributorSkip()
    {
        $order = $this->createMock(Order::class);
        $order->method('getBaseTaxAmount')->willReturn(0.0);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0.0);
        $creditmemo->method('getBaseGrandTotal')->willReturn(3.0);

        $this->refundDistributor->method('distribute')->willReturn([
            'action' => RefundDistributor::ACTION_SKIP,
            'cartItems' => [],
            'reason' => 'nothing to distribute',
        ]);

        $return = $this->builder->buildReturnCartItems($creditmemo);

        $this->assertTrue($return['skip']);
    }

    public function testBuildReturnCartItemsUsesDistributedCartItems()
    {
        $order = $this->createMock(Order::class);
        $order->method('getBaseTaxAmount')->willReturn(0.0);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0.0);
        $creditmemo->method('getBaseGrandTotal')->willReturn(3.0);

        $distributed = [['ItemID' => 'SKU-9', 'Index' => 0, 'TIC' => '20000', 'Price' => 3.0, 'Qty' => 1]];
        $this->refundDistributor->method('distribute')->willReturn([
            'action' => RefundDistributor::ACTION_DISTRIBUTE,
            'cartItems' => $distributed,
            'reason' => 'proportional',
        ]);

        $return = $this->builder->buildReturnCartItems($creditmemo);

        $this->assertFalse($return['skip']);
        $this->assertSame($distributed, $return['cartItems']);
    }

    public function testBuildExemptLookupParams()
    {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn(9);
        $order->method('getIncrementId')->willReturn('100000005');

        $cartItems = [['ItemID' => 'SKU-1']];
        $destination = ['State' => 'GA'];
        $origin = ['State' => 'IL'];

        $params = $this->builder->buildExemptLookupParams($order, $cartItems, $destination, $origin);

        $this->assertSame(9, $params['customerID']);
        $this->assertSame('100000005-exempt', $params['cartID']);
        $this->assertSame($cartItems, $params['cartItems']);
        $this->assertSame($origin, $params['origin']);
        $this->assertSame($destination, $params['destination']);
        $this->assertTrue($params['isExempt']);
    }
}
