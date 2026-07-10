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
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
