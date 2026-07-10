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

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Webapi\Soap\ClientFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\Directory\Model\RegionFactory;
use Taxcloud\Magento2\Logger\Logger;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\DataObject;
use Taxcloud\Magento2\Model\CartItemResponseHandler;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;

#[AllowMockObjectsWithoutExpectations]
class ApiTest extends TestCase
{
    use \Taxcloud\Magento2\Test\Unit\BuildsGatewayApi;

    private $api;
    private $scopeConfig;
    private $cacheType;
    private $eventManager;
    private $soapClientFactory;
    private $objectFactory;
    private $productFactory;
    private $regionFactory;
    private $logger;
    private $serializer;
    private $cartItemResponseHandler;
    private $productTicService;
    private $taxCalculationService;
    private $quoteDetailsFactory;
    private $quoteDetailsItemFactory;
    private $taxClassKeyFactory;
    private $customerAddressFactory;
    private $customerAddressRegionFactory;
    private $refundDistributor;
    private $mockSoapClient;
    private $mockDataObject;
    private $capturedLookupParams;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->soapClientFactory = $this->createMock(ClientFactory::class);
        $this->objectFactory = $this->createMock(DataObjectFactory::class);
        $this->productFactory = $this->createMock(ProductFactory::class);
        $this->regionFactory = $this->createMock(RegionFactory::class);
        $this->logger = $this->createMock(Logger::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->cartItemResponseHandler = $this->createMock(CartItemResponseHandler::class);
        $this->productTicService = $this->createMock(ProductTicService::class);
        $this->taxCalculationService = $this->createMock(TaxCalculationInterface::class);
        $this->quoteDetailsFactory = $this->createMock(QuoteDetailsInterfaceFactory::class);
        $this->quoteDetailsItemFactory = $this->createMock(QuoteDetailsItemInterfaceFactory::class);
        $this->taxClassKeyFactory = $this->createMock(TaxClassKeyInterfaceFactory::class);
        $this->customerAddressFactory = $this->createMock(AddressInterfaceFactory::class);
        $this->customerAddressRegionFactory = $this->createMock(RegionInterfaceFactory::class);
        $this->refundDistributor = $this->createMock(RefundDistributor::class);
        // Default: behave like the original empty-cartItems path (full return) so existing
        // tests that don't care about adjustment-only refunds continue to work as before.
        $this->refundDistributor->method('distribute')->willReturn([
            'action'    => RefundDistributor::ACTION_FULL_RETURN,
            'cartItems' => [],
            'reason'    => 'test default',
        ]);
        $this->mockSoapClient = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Returned', 'lookup', 'authorizedWithCapture', 'OrderDetails', 'GetExemptCertificates', 'verifyAddress'])
            ->getMock();
        $this->mockDataObject = $this->getMockBuilder(Dbl\DataObjectDouble::class)
            ->onlyMethods(['setParams', 'getParams', 'setResult', 'getResult'])
            ->getMock();
        // getClient() builds its SoapClient via the injected factory — hand it ours.
        $this->soapClientFactory->method('create')->willReturn($this->mockSoapClient);

        $this->constructApi();
    }

    /**
     * Construct $this->api from the current mock properties. Rebuild after
     * swapping a dependency (e.g. a real CartItemResponseHandler, or a factory
     * that fails) instead of reaching into private state.
     */
    private function constructApi(): void
    {
        $this->api = $this->buildGatewayApi([
            'scopeConfig' => $this->scopeConfig,
            'cacheType' => $this->cacheType,
            'eventManager' => $this->eventManager,
            'soapClientFactory' => $this->soapClientFactory,
            'objectFactory' => $this->objectFactory,
            'regionFactory' => $this->regionFactory,
            'logger' => $this->logger,
            'serializer' => $this->serializer,
            'cartItemResponseHandler' => $this->cartItemResponseHandler,
            'productTicService' => $this->productTicService,
            'taxCalculationService' => $this->taxCalculationService,
            'quoteDetailsFactory' => $this->quoteDetailsFactory,
            'quoteDetailsItemFactory' => $this->quoteDetailsItemFactory,
            'taxClassKeyFactory' => $this->taxClassKeyFactory,
            'customerAddressFactory' => $this->customerAddressFactory,
            'refundDistributor' => $this->refundDistributor,
        ]);
    }

    /**
     * Swap the mocked CartItemResponseHandler for a real instance and rebuild
     * the Api. Use this in tests that assert on the lookupTaxes() return value,
     * since the real handler is what writes per-item TaxAmount into the result.
     */
    private function useRealCartItemResponseHandler(): void
    {
        $this->cartItemResponseHandler = new CartItemResponseHandler();
        $this->constructApi();
    }

    public function testReturnOrderIncludesReturnCoDeliveryFeeWhenNoCartItems()
    {
        // Mock configuration
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        // Mock SOAP client
        $this->soapClientFactory->method('create')
            ->willReturn($this->mockSoapClient);

        // Mock data object for event handling
        $this->objectFactory->method('create')
            ->willReturn($this->mockDataObject);

        // Mock credit memo
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_123');
        $order->method('getBaseTaxAmount')->willReturn(0);

        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0);

        // Mock successful SOAP response
        $mockResponse = new \stdClass();
        $mockResponse->ReturnedResult = new \stdClass();
        $mockResponse->ReturnedResult->ResponseType = 'OK';
        $mockResponse->ReturnedResult->Messages = [];

        $this->mockSoapClient->method('Returned')
            ->willReturn($mockResponse);

        // Mock data object methods
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [],
            'returnedDate' => '2025-01-03T00:00:00+00:00',
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ]);
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'Messages' => []
        ]);

        // Execute the method
        $result = $this->api->returnOrder($creditmemo);

        // Assert the result
        $this->assertTrue($result, 'returnOrder should return true for successful refund');
    }

    /**
     * Tax-only refund: empty credit memo where refund amount equals the order tax.
     * Returned is called with empty cartItems (TaxCloud treats this as a full order return),
     * then an exempt re-create is attempted via Lookup and AuthorizedWithCapture.
     */
    public function testReturnOrderTaxOnlyRefundCallsReturnedThenReCreatesAsExempt()
    {
        // Mock configuration
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        // Mock SOAP client
        $this->soapClientFactory->method('create')
            ->willReturn($this->mockSoapClient);

        // Mock data object for event handling
        $this->objectFactory->method('create')
            ->willReturn($this->mockDataObject);

        // Mock credit memo
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_123');
        $order->method('getBaseTaxAmount')->willReturn(5.0);
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getBaseShippingAmount')->willReturn(0);

        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0);
        $creditmemo->method('getBaseTaxAmount')->willReturn(5.0);
        $creditmemo->method('getBaseGrandTotal')->willReturn(5.0);

        // Mock successful SOAP response
        $mockResponse = new \stdClass();
        $mockResponse->ReturnedResult = new \stdClass();
        $mockResponse->ReturnedResult->ResponseType = 'OK';
        $mockResponse->ReturnedResult->Messages = [];

        $this->mockSoapClient->method('Returned')
            ->willReturn($mockResponse);

        // Mock data object methods
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [],
            'returnedDate' => '2026-01-03T00:00:00+00:00',
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ]);
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'Messages' => []
        ]);

        // Execute the method
        $result = $this->api->returnOrder($creditmemo);

        // Assert the result
        $this->assertTrue($result, 'returnOrder should return true for a tax-only refund');
    }

    public function testReturnOrderHandlesSoapErrorGracefully()
    {
        // Mock configuration
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        // Mock SOAP client
        $this->soapClientFactory->method('create')
            ->willReturn($this->mockSoapClient);

        // Mock data object for event handling
        $this->objectFactory->method('create')
            ->willReturn($this->mockDataObject);

        // Mock credit memo
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_123');
        
        $order->method('getBaseTaxAmount')->willReturn(0);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0);

        // Mock SOAP error
        $this->mockSoapClient->method('Returned')
            ->willThrowException(new \SoapFault('SOAP-ERROR', 'Encoding: object has no \'returnCoDeliveryFeeWhenNoCartItems\' property'));

        // Mock data object methods
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [],
            'returnedDate' => '2025-01-03T00:00:00+00:00',
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ]);

        // Execute the method
        $result = $this->api->returnOrder($creditmemo);

        // Assert the result
        $this->assertFalse($result, 'returnOrder should return false when SOAP error occurs');
    }

    public function testReturnOrderWithCartItems()
    {
        // Mock configuration
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        // Mock SOAP client
        $this->soapClientFactory->method('create')
            ->willReturn($this->mockSoapClient);

        // Mock data object for event handling
        $this->objectFactory->method('create')
            ->willReturn($this->mockDataObject);

        // Mock credit memo with items
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_123');

        $creditItem = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $orderItem = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productModel = $this->createMock(\Magento\Catalog\Model\Product::class);
        $customAttribute = $this->createMock(\Magento\Framework\Api\AttributeValue::class);

        $creditItem->method('getOrderItem')->willReturn($orderItem);
        $creditItem->method('getPrice')->willReturn(14.99);
        $creditItem->method('getDiscountAmount')->willReturn(0);
        $creditItem->method('getQty')->willReturn(1);
        
        $orderItem->method('getSku')->willReturn('TEST_SKU');
        $orderItem->method('getProduct')->willReturn($product);
        
        $product->method('getId')->willReturn(1);
        $productModel->method('load')->willReturnSelf();
        $productModel->method('getCustomAttribute')->willReturn($customAttribute);
        $customAttribute->method('getValue')->willReturn('20000');
        
        $this->productFactory->method('create')->willReturn($productModel);

        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([$creditItem]);
        $creditmemo->method('getShippingAmount')->willReturn(5.99);

        // Mock successful SOAP response
        $mockResponse = new \stdClass();
        $mockResponse->ReturnedResult = new \stdClass();
        $mockResponse->ReturnedResult->ResponseType = 'OK';
        $mockResponse->ReturnedResult->Messages = [];

        $this->mockSoapClient->method('Returned')
            ->willReturn($mockResponse);

        // Mock data object methods
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [
                [
                    'ItemID' => 'TEST_SKU',
                    'Index' => 0,
                    'TIC' => '20000',
                    'Price' => 14.99,
                    'Qty' => 1
                ],
                [
                    'ItemID' => 'shipping',
                    'Index' => 1,
                    'TIC' => '11010',
                    'Price' => 5.99,
                    'Qty' => 1
                ]
            ],
            'returnedDate' => '2025-01-03T00:00:00+00:00',
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ]);
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'Messages' => []
        ]);

        // Execute the method
        $result = $this->api->returnOrder($creditmemo);

        // Assert the result
        $this->assertTrue($result, 'returnOrder should return true for successful refund with items');
    }

    /**
     * Test that specifically covers the failure case where returnCoDeliveryFeeWhenNoCartItems
     * parameter gets lost during event processing
     * This test should FAIL when the fix is not applied
     */
    public function testReturnOrderFailsWhenParameterIsLost()
    {
        // Mock configuration
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        // Mock SOAP client
        $this->soapClientFactory->method('create')
            ->willReturn($this->mockSoapClient);

        // Mock data object for event handling
        $this->objectFactory->method('create')
            ->willReturn($this->mockDataObject);

        // Mock credit memo
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_123');
        
        $order->method('getBaseTaxAmount')->willReturn(0);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0);

        // Mock SOAP error that occurs when returnCoDeliveryFeeWhenNoCartItems is missing
        $this->mockSoapClient->method('Returned')
            ->willThrowException(new \SoapFault('SOAP-ERROR', 'Encoding: object has no \'returnCoDeliveryFeeWhenNoCartItems\' property'));

        // Mock data object methods - simulate event processing that removes the parameter
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [],
            'returnedDate' => '2025-01-03T00:00:00+00:00'
            // Note: returnCoDeliveryFeeWhenNoCartItems is intentionally missing here
        ]);

        // Execute the method
        $result = $this->api->returnOrder($creditmemo);

        // This should FAIL when the fix is not applied
        // The test expects the method to return false due to the SOAP error
        $this->assertFalse($result, 'returnOrder should return false when returnCoDeliveryFeeWhenNoCartItems parameter is missing');
    }

    /**
     * returnOrderCancellation: success path with order items and shipping.
     */
    public function testReturnOrderCancellationSuccess()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        $this->objectFactory->method('create')->willReturn($this->mockDataObject);
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'CANCEL_ORDER_123',
            'cartItems' => [],
            'returnedDate' => '2026-01-03T00:00:00+00:00',
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ]);
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'Messages' => []
        ]);

        $orderItem = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $orderItem->method('getQtyOrdered')->willReturn(1);
        $orderItem->method('getPrice')->willReturn(10.00);
        $orderItem->method('getDiscountAmount')->willReturn(0);
        $orderItem->method('getSku')->willReturn('SKU1');

        $this->productTicService->method('getProductTic')->with($orderItem, 'returnOrder')->willReturn('20000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('CANCEL_ORDER_123');
        $order->method('getAllVisibleItems')->willReturn([$orderItem]);
        $order->method('getBaseShippingAmount')->willReturn(5.99);

        $mockResponse = new \stdClass();
        $mockResponse->ReturnedResult = new \stdClass();
        $mockResponse->ReturnedResult->ResponseType = 'OK';
        $mockResponse->ReturnedResult->Messages = [];
        $this->mockSoapClient->method('Returned')->willReturn($mockResponse);

        $result = $this->api->returnOrderCancellation($order);

        $this->assertTrue($result, 'returnOrderCancellation should return true on success');
    }

    /**
     * returnOrderCancellation: empty cart items returns false.
     */
    public function testReturnOrderCancellationEmptyCartItemsReturnsFalse()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1']
            ]);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('EMPTY_ORDER');
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getBaseShippingAmount')->willReturn(0);

        $result = $this->api->returnOrderCancellation($order);

        $this->assertFalse($result, 'returnOrderCancellation should return false when order has no cart items');
    }

    /**
     * returnOrderCancellation: SOAP error returns false.
     */
    public function testReturnOrderCancellationSoapErrorReturnsFalse()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '00000'],
                ['tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '11010']
            ]);

        $this->objectFactory->method('create')->willReturn($this->mockDataObject);
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'CANCEL_ORDER_123',
            'cartItems' => [['ItemID' => 'SKU1', 'Index' => 0, 'TIC' => '20000', 'Price' => 10, 'Qty' => 1]],
            'returnedDate' => '2026-01-03T00:00:00+00:00',
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ]);

        $orderItem = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $orderItem->method('getQtyOrdered')->willReturn(1);
        $orderItem->method('getPrice')->willReturn(10.00);
        $orderItem->method('getDiscountAmount')->willReturn(0);
        $orderItem->method('getSku')->willReturn('SKU1');
        $this->productTicService->method('getProductTic')->willReturn('20000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('CANCEL_ORDER_123');
        $order->method('getAllVisibleItems')->willReturn([$orderItem]);
        $order->method('getBaseShippingAmount')->willReturn(0);

        $this->mockSoapClient->method('Returned')
            ->willThrowException(new \SoapFault('SOAP-ERROR', 'Server error'));

        $result = $this->api->returnOrderCancellation($order);
        $this->assertFalse($result, 'returnOrderCancellation should return false when SOAP call fails');
    }

    /**
     * getOrderDetails: success path returns OrderDetailsResult array with CapturedDate.
     */
    public function testGetOrderDetailsReturnsResultWhenCaptured()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ]);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('ORDER_100');

        $mockResponse = new \stdClass();
        $mockResponse->OrderDetailsResult = new \stdClass();
        $mockResponse->OrderDetailsResult->ResponseType = 'OK';
        $mockResponse->OrderDetailsResult->CapturedDate = '2024-01-15T12:00:00';

        $this->mockSoapClient->method('OrderDetails')
            ->with($this->callback(function ($params) {
                return isset($params['apiLoginID'], $params['apiKey'], $params['orderID'])
                    && $params['orderID'] === 'ORDER_100';
            }))
            ->willReturn($mockResponse);

        $result = $this->api->getOrderDetails($order);

        $this->assertIsArray($result, 'getOrderDetails should return array on success');
        $this->assertSame('OK', $result['ResponseType']);
        $this->assertSame('2024-01-15T12:00:00', $result['CapturedDate']);
    }

    /**
     * getOrderDetails: returns null when ResponseType is not OK or order not found.
     */
    public function testGetOrderDetailsReturnsNullWhenNotOkOrError()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ]);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('ORDER_101');

        $mockResponse = new \stdClass();
        $mockResponse->OrderDetailsResult = new \stdClass();
        $mockResponse->OrderDetailsResult->ResponseType = 'Error';

        $this->mockSoapClient->method('OrderDetails')->willReturn($mockResponse);

        $result = $this->api->getOrderDetails($order);

        $this->assertNull($result, 'getOrderDetails should return null when ResponseType is not OK');
    }

    /**
     * lookupTaxes: when shipping row total is 0, uses address getShippingAmount() for shipping price sent to TaxCloud.
     */
    public function testLookupTaxesUsesAddressShippingAmountWhenShippingRowTotalIsZero()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
                ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
                ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
                ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
                ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
                ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('GA');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(13.85);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $shippingTaxDetailItem->method('getRowTotal')->willReturn(0);

        $itemsByType = [
            Api::ITEM_TYPE_SHIPPING => [
                'shipping' => [Api::KEY_ITEM => $shippingTaxDetailItem],
            ],
        ];

        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $this->cacheType->method('load')->willReturn(false);

        $capturedParams = null;
        $this->mockDataObject->method('setParams')->willReturnCallback(function ($p) use (&$capturedParams) {
            $capturedParams = $p;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getParams')->willReturnCallback(function () use (&$capturedParams) {
            return $capturedParams;
        });
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'CartItemsResponse' => ['CartItemResponse' => [['CartItemIndex' => 0, 'TaxAmount' => 0]]],
        ]);
        $this->objectFactory->method('create')->willReturn($this->mockDataObject);

        $lookupParams = null;
        $mockLookupResponse = new \stdClass();
        $mockLookupResponse->LookupResult = new \stdClass();
        $mockLookupResponse->LookupResult->ResponseType = 'OK';
        $mockLookupResponse->LookupResult->CartItemsResponse = new \stdClass();
        $mockLookupResponse->LookupResult->CartItemsResponse->CartItemResponse = [
            (object)['CartItemIndex' => 0, 'TaxAmount' => 0],
        ];
        $this->mockSoapClient->method('lookup')->willReturnCallback(function ($params) use (&$lookupParams, $mockLookupResponse) {
            $lookupParams = $params;
            return $mockLookupResponse;
        });

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertNotNull($lookupParams, 'lookup should have been called');
        $cartItems = $lookupParams['cartItems'] ?? [];
        $shippingItem = null;
        foreach ($cartItems as $item) {
            if (isset($item['ItemID']) && $item['ItemID'] === 'shipping') {
                $shippingItem = $item;
                break;
            }
        }
        $this->assertNotNull($shippingItem, 'cartItems should contain shipping');
        $this->assertSame(13.85, (float) $shippingItem['Price'], 'lookupTaxes should send address getShippingAmount() when shipping row total is 0');
    }

    /**
     * lookupTaxes: cache key is computed from params after taxcloud_lookup_before event.
     */
    public function testLookupTaxesCacheKeyUsesParamsAfterEvent()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '3600'],
                ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
                ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
                ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
                ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
                ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
                ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('GA');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);
        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $shippingTaxDetailItem->method('getRowTotal')->willReturn(0);
        $itemsByType = [
            Api::ITEM_TYPE_SHIPPING => [
                'shipping' => [Api::KEY_ITEM => $shippingTaxDetailItem],
            ],
        ];

        $this->productTicService->method('getShippingTic')->willReturn('11010');
        $this->cacheType->method('load')->willReturn(false);

        $modifiedDestination = [
            'Address1' => 'Modified Street By Observer',
            'Address2' => '',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];
        $this->mockDataObject->method('setParams')->willReturnSelf();
        $this->mockDataObject->method('getParams')->willReturnCallback(function () use ($modifiedDestination) {
            $base = [
                'apiLoginID' => 'test_api_id',
                'apiKey' => 'test_api_key',
                'customerID' => 1,
                'cartID' => null,
                'cartItems' => [['ItemID' => 'shipping', 'Index' => 0, 'TIC' => '11010', 'Price' => 0, 'Qty' => 1]],
                'origin' => ['Address1' => '71 W Seegers Rd', 'City' => 'Arlington Heights', 'State' => 'GA', 'Zip5' => '60005', 'Zip4' => null],
                'destination' => $modifiedDestination,
                'deliveredBySeller' => false,
                'exemptCert' => ['CertificateID' => null],
            ];
            return $base;
        });
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'CartItemsResponse' => ['CartItemResponse' => [['CartItemIndex' => 0, 'TaxAmount' => 0]]],
        ]);
        $this->objectFactory->method('create')->willReturn($this->mockDataObject);

        $cacheKeyUsed = null;
        $this->cacheType->method('load')->willReturnCallback(function ($key) use (&$cacheKeyUsed) {
            $cacheKeyUsed = $key;
            return false;
        });

        $mockLookupResponse = new \stdClass();
        $mockLookupResponse->LookupResult = new \stdClass();
        $mockLookupResponse->LookupResult->ResponseType = 'OK';
        $mockLookupResponse->LookupResult->CartItemsResponse = new \stdClass();
        $mockLookupResponse->LookupResult->CartItemsResponse->CartItemResponse = [
            (object)['CartItemIndex' => 0, 'TaxAmount' => 0],
        ];
        $this->mockSoapClient->method('lookup')->willReturn($mockLookupResponse);

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $expectedKey = 'taxcloud_rates_' . hash('sha256', json_encode($this->mockDataObject->getParams()));
        $this->assertSame($expectedKey, $cacheKeyUsed, 'lookupTaxes cache key should be computed from params after taxcloud_lookup_before event');
    }

    // ─── Exemption Certificate State Filtering Tests ────────────────────

    /**
     * Build a mock GetExemptCertificates SOAP response.
     *
     * @param string   $certID       Certificate UUID
     * @param string[] $stateAbbrs   e.g. ['NY', 'NJ']
     * @return \stdClass
     */
    private function buildGetExemptCertsResponse(string $certID, array $stateAbbrs): \stdClass
    {
        $exemptStates = [];
        foreach ($stateAbbrs as $abbr) {
            $es = new \stdClass();
            $es->StateAbbr = $abbr;
            $es->StateAbbreviation = $abbr;
            $es->ReasonForExemption = 'Resale';
            $es->IdentificationNumber = '12345';
            $exemptStates[] = $es;
        }

        $detail = new \stdClass();
        $detail->ExemptStates = new \stdClass();
        $detail->ExemptStates->ExemptState = $exemptStates;

        $cert = new \stdClass();
        $cert->CertificateID = $certID;
        $cert->Detail = $detail;

        $result = new \stdClass();
        $result->ResponseType = 'OK';
        $result->ExemptCertificates = new \stdClass();
        $result->ExemptCertificates->ExemptionCertificate = [$cert];

        $response = new \stdClass();
        $response->GetExemptCertificatesResult = $result;
        return $response;
    }

    /**
     * Common setup for the exemption-certificate lookup tests.
     *
     * Returns an array [$itemsByType, $shippingAssignment, $quote] so each test can
     * call lookupTaxes(); the params sent to the SOAP lookup are captured into
     * $this->capturedLookupParams for inspection.
     *
     * @param string      $certID            Certificate UUID on the customer (empty string = no cert)
     * @param string      $destinationState  Two-letter state code for the shipping address
     * @return array
     */
    private function setUpLookupWithCert(string $certID, string $destinationState): array
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->soapClientFactory = $this->createMock(ClientFactory::class);
        $this->objectFactory = $this->createMock(DataObjectFactory::class);
        $this->productFactory = $this->createMock(ProductFactory::class);
        $this->regionFactory = $this->createMock(RegionFactory::class);
        $this->logger = $this->createMock(Logger::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->cartItemResponseHandler = $this->createMock(CartItemResponseHandler::class);
        $this->productTicService = $this->createMock(ProductTicService::class);
        $this->mockSoapClient = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Returned', 'lookup', 'authorizedWithCapture', 'OrderDetails', 'GetExemptCertificates', 'verifyAddress'])
            ->getMock();
        $this->mockDataObject = $this->getMockBuilder(Dbl\DataObjectDouble::class)
            ->onlyMethods(['setParams', 'getParams', 'setResult', 'getResult'])
            ->getMock();
        $this->soapClientFactory->method('create')->willReturn($this->mockSoapClient);

        $this->constructApi();

        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
                ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
                ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
                ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
                ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
                ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn($destinationState);
        $this->regionFactory->method('create')->willReturn($region);

        $certAttr = null;
        if ($certID !== '') {
            $certAttr = $this->createMock(\Magento\Framework\Api\AttributeValue::class);
            $certAttr->method('getValue')->willReturn($certID);
        }

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getCustomAttribute')
            ->willReturnCallback(function ($attr) use ($certAttr) {
                return $attr === 'taxcloud_cert' ? $certAttr : null;
            });

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);
        $quote->method('getId')->willReturn(999);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(5.00);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $shippingTaxDetailItem->method('getRowTotal')->willReturn(5.00);

        $itemsByType = [
            Api::ITEM_TYPE_SHIPPING => [
                'shipping' => [Api::KEY_ITEM => $shippingTaxDetailItem],
            ],
        ];

        $this->productTicService->method('getShippingTic')->willReturn('11010');

        // DataObject pass-through for event dispatch
        $capturedParams = null;
        $this->mockDataObject->method('setParams')->willReturnCallback(function ($p) use (&$capturedParams) {
            $capturedParams = $p;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getParams')->willReturnCallback(function () use (&$capturedParams) {
            return $capturedParams;
        });
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->mockDataObject->method('getResult')->willReturn([
            'ResponseType' => 'OK',
            'CartItemsResponse' => ['CartItemResponse' => [['CartItemIndex' => 0, 'TaxAmount' => 0]]],
        ]);
        $this->objectFactory->method('create')->willReturn($this->mockDataObject);

        // Standard lookup SOAP response
        $this->capturedLookupParams = null;
        $mockLookupResponse = new \stdClass();
        $mockLookupResponse->LookupResult = new \stdClass();
        $mockLookupResponse->LookupResult->ResponseType = 'OK';
        $mockLookupResponse->LookupResult->CartItemsResponse = new \stdClass();
        $mockLookupResponse->LookupResult->CartItemsResponse->CartItemResponse = [
            (object)['CartItemIndex' => 0, 'TaxAmount' => 0],
        ];
        $this->mockSoapClient->method('lookup')->willReturnCallback(
            function ($params) use ($mockLookupResponse) {
                $this->capturedLookupParams = $params;
                return $mockLookupResponse;
            }
        );

        return [$itemsByType, $shippingAssignment, $quote];
    }

    /**
     * @dataProvider exemptCertSoapProvider
     */
    #[DataProvider('exemptCertSoapProvider')]
    public function testLookupTaxesExemptCertStateFilteringViaSoap(
        string $description,
        array $certExemptStates,
        string $destinationState,
        bool $expectCertSent
    ) {
        $certID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        [$itemsByType, $shippingAssignment, $quote] =
            $this->setUpLookupWithCert($certID, $destinationState);
        $lookupParams = &$this->capturedLookupParams;

        $this->mockSoapClient->method('GetExemptCertificates')
            ->willReturn($this->buildGetExemptCertsResponse($certID, $certExemptStates));

        $this->cacheType->method('load')->willReturn(false);

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertNotNull($lookupParams, 'lookup should have been called');
        if ($expectCertSent) {
            $this->assertSame($certID, $lookupParams['exemptCert']['CertificateID'], $description);
        } else {
            $this->assertNull($lookupParams['exemptCert']['CertificateID'], $description);
        }
    }

    public static function exemptCertSoapProvider(): array
    {
        return [
            'cert covers destination state (exact match)' => [
                'Cert covering GA should be sent when shipping to GA',
                ['GA'],
                'GA',
                true,
            ],
            'cert covers destination among multiple states' => [
                'Cert covering GA+NY should be sent when shipping to GA',
                ['GA', 'NY'],
                'GA',
                true,
            ],
            'cert does not cover destination state' => [
                'Cert covering only NY must not be sent when shipping to GA',
                ['NY'],
                'GA',
                false,
            ],
            'cert covers different states, none match destination' => [
                'Cert covering NY+NJ must not be sent when shipping to TX',
                ['NY', 'NJ'],
                'TX',
                false,
            ],
            'cert has no exempt states' => [
                'Cert with empty exempt states must not be sent',
                [],
                'GA',
                false,
            ],
        ];
    }

    /**
     * @dataProvider exemptCertCacheProvider
     */
    #[DataProvider('exemptCertCacheProvider')]
    public function testLookupTaxesExemptCertStateFilteringViaCache(
        string $description,
        array $cachedStates,
        string $destinationState,
        bool $expectCertSent
    ) {
        $certID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        [$itemsByType, $shippingAssignment, $quote] =
            $this->setUpLookupWithCert($certID, $destinationState);
        $lookupParams = &$this->capturedLookupParams;

        // Cache key is scoped per (customer, certificate); customer ID is 42
        // in setUpLookupWithCert().
        $certCacheKey = 'taxcloud_cert_states_42_' . $certID;
        $this->cacheType->method('load')->willReturnCallback(function ($key) use ($certCacheKey, $cachedStates) {
            if ($key === $certCacheKey) {
                return json_encode($cachedStates);
            }
            return false;
        });

        // Cache hit means no SOAP call needed
        $this->mockSoapClient->expects($this->never())->method('GetExemptCertificates');

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertNotNull($lookupParams, 'lookup should have been called');
        if ($expectCertSent) {
            $this->assertSame($certID, $lookupParams['exemptCert']['CertificateID'], $description);
        } else {
            $this->assertNull($lookupParams['exemptCert']['CertificateID'], $description);
        }
    }

    public static function exemptCertCacheProvider(): array
    {
        return [
            'cached states include destination' => [
                'Cached GA+NY should allow cert when shipping to GA',
                ['GA', 'NY'],
                'GA',
                true,
            ],
            'cached states do not include destination' => [
                'Cached NY must block cert when shipping to GA',
                ['NY'],
                'GA',
                false,
            ],
        ];
    }

    /**
     * GetExemptCertificates SOAP call fails → fail closed, cert not applied.
     */
    public function testLookupTaxesOmitsCertWhenGetExemptCertificatesFails()
    {
        $certID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        [$itemsByType, $shippingAssignment, $quote] =
            $this->setUpLookupWithCert($certID, 'GA');
        $lookupParams = &$this->capturedLookupParams;

        $this->mockSoapClient->method('GetExemptCertificates')
            ->willThrowException(new \SoapFault('SOAP-ERROR', 'Service unavailable'));

        $this->cacheType->method('load')->willReturn(false);

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertNotNull($lookupParams, 'lookup should have been called');
        $this->assertNull(
            $lookupParams['exemptCert']['CertificateID'],
            'CertificateID must be null when GetExemptCertificates SOAP call fails'
        );
    }

    /**
     * No cert on customer → CertificateID should be null (unchanged behavior).
     */
    public function testLookupTaxesNoCertOnCustomerSendsNullCertificateID()
    {
        [$itemsByType, $shippingAssignment, $quote] =
            $this->setUpLookupWithCert('', 'GA');
        $lookupParams = &$this->capturedLookupParams;

        $this->cacheType->method('load')->willReturn(false);
        $this->mockSoapClient->expects($this->never())->method('GetExemptCertificates');

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertNotNull($lookupParams, 'lookup should have been called');
        $this->assertNull(
            $lookupParams['exemptCert']['CertificateID'],
            'CertificateID should be null when customer has no cert'
        );
    }

    /**
     * Single-cert SOAP response (SOAP returns object instead of array for one cert).
     */
    public function testLookupTaxesHandlesSingleCertSoapResponse()
    {
        $certID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        [$itemsByType, $shippingAssignment, $quote] =
            $this->setUpLookupWithCert($certID, 'GA');
        $lookupParams = &$this->capturedLookupParams;

        // Build response where ExemptionCertificate is a single object, not an array
        $response = $this->buildGetExemptCertsResponse($certID, ['GA']);
        $response->GetExemptCertificatesResult->ExemptCertificates->ExemptionCertificate =
            $response->GetExemptCertificatesResult->ExemptCertificates->ExemptionCertificate[0];

        $this->mockSoapClient->method('GetExemptCertificates')->willReturn($response);
        $this->cacheType->method('load')->willReturn(false);

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertNotNull($lookupParams, 'lookup should have been called');
        $this->assertSame(
            $certID,
            $lookupParams['exemptCert']['CertificateID'],
            'Should handle single-cert SOAP response (object instead of array)'
        );
    }

    // ─── Section 1: canonical amounts / shipping TIC pass-through ───────────

    /**
     * Section 1.1: lookupTaxes must surface TaxCloud's per-item TaxAmount
     * unchanged through the result array.
     *
     * The SOAP response is loaded from a pinned fixture captured against the
     * TaxCloud sandbox — see fixtures/lookup_single_item_taxable.json. Updating
     * that file should re-validate against the sandbox so "matches dashboard"
     * stays meaningful.
     */
    public function testLookupTaxesProducesCanonicalAmountsForSingleItemInTaxableState()
    {
        $this->configureBaseLookupScopeConfig('0');

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('NY');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('10001');
        $address->method('getStreet')->willReturn(['350 Fifth Ave']);
        $address->method('getCity')->willReturn('New York');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn('2');
        $quoteItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrice', 'getProduct', 'getQty', 'getSku', 'getDiscountAmount', 'getTaxCalculationItemId'])
            ->getMock();
        $quoteItem->method('getTaxCalculationItemId')->willReturn('item-1');
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getQty')->willReturn(1);
        $quoteItem->method('getPrice')->willReturn(14.99);
        $quoteItem->method('getDiscountAmount')->willReturn(0);
        $quoteItem->method('getSku')->willReturn('SKU-1');

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $productTaxDetail = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $productTaxDetail->method('getRowTotal')->willReturn(14.99);

        $itemsByType = [
            Api::ITEM_TYPE_PRODUCT => [
                'item-1' => [Api::KEY_ITEM => $productTaxDetail],
            ],
        ];

        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->cacheType->method('load')->willReturn(false);

        $this->setUpPassThroughDataObject();

        // Mock SOAP lookup to return the fixture as stdClass (mirrors real SOAP shape).
        $fixturePath = __DIR__ . '/../fixtures/lookup_single_item_taxable.json';
        $fixture = json_decode(file_get_contents($fixturePath));
        $this->mockSoapClient->method('lookup')->willReturn($fixture);

        // Use the real handler so $result is actually populated from the SOAP response.
        $this->useRealCartItemResponseHandler();

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertArrayHasKey(Api::ITEM_TYPE_PRODUCT, $result);
        $this->assertArrayHasKey('item-1', $result[Api::ITEM_TYPE_PRODUCT]);
        $this->assertSame(
            1.23,
            $result[Api::ITEM_TYPE_PRODUCT]['item-1'],
            'lookupTaxes must surface the fixture TaxAmount unchanged — pinned to TaxCloud sandbox.'
        );
    }

    /**
     * Section 1.3: the configured shipping TIC is what's sent to TaxCloud,
     * and the shipping tax returned matches the (mocked) TaxCloud verdict.
     *
     * Cases cover both TIC variants (11010 shipping-only, 11000 shipping+handling)
     * crossed with a shipping-taxable state (TX) and a non-shipping-taxable one (CA).
     *
     * @dataProvider shippingTicPassthroughProvider
     */
    #[DataProvider('shippingTicPassthroughProvider')]
    public function testLookupTaxesShippingTic11010VsTic11000InShippingTaxableState(
        string $shippingTic,
        string $destinationState,
        float $mockedShippingTaxAmount,
        string $description
    ) {
        $this->configureBaseLookupScopeConfig('0');

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn($destinationState);
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('75001');
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('Anywhere');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetail = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $shippingTaxDetail->method('getRowTotal')->willReturn(10.00);

        $itemsByType = [
            Api::ITEM_TYPE_SHIPPING => [
                'shipping' => [Api::KEY_ITEM => $shippingTaxDetail],
            ],
        ];

        $this->productTicService->method('getShippingTic')->willReturn($shippingTic);
        $this->cacheType->method('load')->willReturn(false);

        $this->setUpPassThroughDataObject();

        // Capture cartItems sent to SOAP, return mocked shipping tax.
        $capturedTic = null;
        $this->mockSoapClient->method('lookup')->willReturnCallback(
            function ($params) use (&$capturedTic, $mockedShippingTaxAmount) {
                foreach ($params['cartItems'] ?? [] as $item) {
                    if (($item['ItemID'] ?? null) === 'shipping') {
                        $capturedTic = $item['TIC'] ?? null;
                        break;
                    }
                }
                $response = new \stdClass();
                $response->LookupResult = new \stdClass();
                $response->LookupResult->ResponseType = 'OK';
                $response->LookupResult->CartItemsResponse = new \stdClass();
                $response->LookupResult->CartItemsResponse->CartItemResponse = [
                    (object)['CartItemIndex' => 0, 'TaxAmount' => $mockedShippingTaxAmount],
                ];
                return $response;
            }
        );

        $this->useRealCartItemResponseHandler();

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame(
            $shippingTic,
            $capturedTic,
            "Shipping cart item should carry configured shipping_tic (case: $description)"
        );
        $this->assertSame(
            $mockedShippingTaxAmount,
            (float) $result[Api::ITEM_TYPE_SHIPPING],
            "Returned shipping tax should match mocked TaxCloud response (case: $description)"
        );
    }

    public static function shippingTicPassthroughProvider(): array
    {
        return [
            'TIC 11010 (shipping-only), TX taxes shipping' => ['11010', 'TX', 0.50, '11010-TX'],
            'TIC 11000 (shipping+handling), TX taxes shipping' => ['11000', 'TX', 0.50, '11000-TX'],
            'TIC 11010, CA does not tax shipping' => ['11010', 'CA', 0.0, '11010-CA'],
            'TIC 11000, CA does not tax shipping' => ['11000', 'CA', 0.0, '11000-CA'],
        ];
    }

    // ─── Section 5: returnOrder refund branches ─────────────────────────────

    /**
     * Section 5.1: when ProductTicService returns the default TIC (e.g. because the
     * underlying product was deleted), Api::returnOrder must forward that TIC into the
     * SOAP Returned call — not silently substitute another value.
     */
    public function testReturnOrderUsesDefaultTicWhenProductWasDeleted()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ]);

        // ProductTicService is mocked at the test fixture; simulate the "deleted product"
        // verdict by returning the configured default TIC.
        $this->productTicService->method('getProductTic')->willReturn('00000');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_DEL');
        $order->method('getBaseTaxAmount')->willReturn(2.0);

        $orderItem = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $orderItem->method('getSku')->willReturn('SKU-DEL');
        // Deleted product: getProduct() returns null. ProductTicService handles this
        // and returns getDefaultTic() — we've already mocked that above.
        $orderItem->method('getProduct')->willReturn(null);

        $creditItem = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $creditItem->method('getOrderItem')->willReturn($orderItem);
        $creditItem->method('getQty')->willReturn(1);
        $creditItem->method('getPrice')->willReturn(10.00);
        $creditItem->method('getDiscountAmount')->willReturn(0);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([$creditItem]);
        $creditmemo->method('getShippingAmount')->willReturn(0);

        $this->setUpPassThroughDataObject();

        // Capture cartItems sent to SOAP Returned.
        $capturedTic = null;
        $mockResponse = new \stdClass();
        $mockResponse->ReturnedResult = new \stdClass();
        $mockResponse->ReturnedResult->ResponseType = 'OK';
        $mockResponse->ReturnedResult->Messages = [];
        $this->mockSoapClient->method('Returned')->willReturnCallback(
            function ($params) use (&$capturedTic, $mockResponse) {
                $capturedTic = $params['cartItems'][0]['TIC'] ?? null;
                return $mockResponse;
            }
        );

        $result = $this->api->returnOrder($creditmemo);

        $this->assertTrue($result, 'returnOrder must succeed even when the product was deleted');
        $this->assertSame(
            '00000',
            $capturedTic,
            'Default TIC (00000) must reach the SOAP Returned call when the product was deleted'
        );
    }

    /**
     * Section 5.2: tax-only refund. Returned succeeds, then the exempt re-create lookup
     * throws — returnOrder must still report success (the return is recorded). Critically,
     * Returned must NOT be called a second time to "roll back" — that would double-return.
     */
    public function testTaxOnlyRefundExemptRecreateFailureDoesNotRollBackReturned()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
                ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
                ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
                ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
                ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
                ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('NY');
        $this->regionFactory->method('create')->willReturn($region);

        // Build an order with a visible item + valid US shipping address so lookupForOrderExempt
        // actually reaches the SOAP lookup call we want to fail.
        $orderItem = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $orderItem->method('getSku')->willReturn('SKU-1');
        $orderItem->method('getQtyOrdered')->willReturn(1);
        $orderItem->method('getPrice')->willReturn(10.0);
        $orderItem->method('getDiscountAmount')->willReturn(0);
        $orderItem->method('getProduct')->willReturn(null);

        $shippingAddress = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionCode', 'getRegionId', 'getStreet'])
            ->getMock();
        $shippingAddress->method('getPostcode')->willReturn('10001');
        $shippingAddress->method('getStreet')->willReturn(['1 Main St']);
        $shippingAddress->method('getCity')->willReturn('New York');
        $shippingAddress->method('getRegionId')->willReturn(1);
        $shippingAddress->method('getCountryId')->willReturn('US');
        $shippingAddress->method('getRegionCode')->willReturn('NY');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_TAXONLY');
        $order->method('getBaseTaxAmount')->willReturn(5.0);
        $order->method('getAllVisibleItems')->willReturn([$orderItem]);
        $order->method('getBaseShippingAmount')->willReturn(0);
        $order->method('getShippingAddress')->willReturn($shippingAddress);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->method('getShippingAmount')->willReturn(0);
        $creditmemo->method('getBaseTaxAmount')->willReturn(5.0);
        $creditmemo->method('getBaseGrandTotal')->willReturn(5.0);

        $this->setUpPassThroughDataObject();

        // Returned succeeds — and must be called EXACTLY ONCE. No rollback retry allowed.
        $returnedResponse = new \stdClass();
        $returnedResponse->ReturnedResult = new \stdClass();
        $returnedResponse->ReturnedResult->ResponseType = 'OK';
        $returnedResponse->ReturnedResult->Messages = [];
        $this->mockSoapClient->expects($this->once())
            ->method('Returned')
            ->willReturn($returnedResponse);

        // The exempt re-create lookup throws — exercise the re-create-failure path.
        $this->mockSoapClient->method('lookup')
            ->willThrowException(new \SoapFault('SOAP-ERROR', 'Service unavailable'));

        // authorizedWithCapture must NOT be called because lookupForOrderExempt returned false.
        $this->mockSoapClient->expects($this->never())->method('authorizedWithCapture');

        $result = $this->api->returnOrder($creditmemo);

        $this->assertTrue(
            $result,
            'Tax-only refund must report success when the exempt re-create lookup fails — Returned itself succeeded'
        );
    }

    // ─── Coverage section C: Api.php gaps ───────────────────────────────────

    /**
     * C1.1: missing postcode — lookupTaxes returns the empty result and never calls SOAP.
     */
    public function testLookupTaxesReturnsZeroWhenAddressMissingPostcode()
    {
        $this->assertLookupTaxesShortCircuitsForAddressOverride(['postcode' => '']);
    }

    /** C1.2: non-US country code triggers an early return. */
    public function testLookupTaxesReturnsZeroWhenCountryNotUS()
    {
        $this->assertLookupTaxesShortCircuitsForAddressOverride(['countryId' => 'CA']);
    }

    /** C1.3: regionId=0 triggers an early return. */
    public function testLookupTaxesReturnsZeroWhenNoRegion()
    {
        $this->assertLookupTaxesShortCircuitsForAddressOverride(['regionId' => 0]);
    }

    /** C1.4: missing city triggers an early return. */
    public function testLookupTaxesReturnsZeroWhenNoCity()
    {
        $this->assertLookupTaxesShortCircuitsForAddressOverride(['city' => '']);
    }

    /** C1.5: invalid postcode format triggers PostalCodeParser::isValid() = false. */
    public function testLookupTaxesReturnsZeroWhenInvalidPostcodeFormat()
    {
        $this->assertLookupTaxesShortCircuitsForAddressOverride(['postcode' => 'XXX']);
    }

    /**
     * Shared assertion: given an address-property override that should short-circuit
     * lookupTaxes, verify the SOAP client is never called and the result is empty.
     */
    private function assertLookupTaxesShortCircuitsForAddressOverride(array $addressOverrides): void
    {
        $this->configureBaseLookupScopeConfig('0');

        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('NY');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->buildAddressMockWithOverrides($addressOverrides);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $itemsByType = [];

        $this->mockSoapClient->expects($this->never())->method('lookup');

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame([], $result[Api::ITEM_TYPE_PRODUCT] ?? null);
        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING] ?? null);
    }

    /**
     * Build a Quote\Address mock with default valid US fields, overridable per test.
     */
    private function buildAddressMockWithOverrides(array $overrides): \Magento\Quote\Model\Quote\Address
    {
        $defaults = [
            'postcode' => '10001',
            'street' => ['1 Main St'],
            'city' => 'New York',
            'regionId' => 1,
            'countryId' => 'US',
            'shippingAmount' => 0,
        ];
        $merged = array_merge($defaults, $overrides);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn($merged['postcode']);
        $address->method('getStreet')->willReturn($merged['street']);
        $address->method('getCity')->willReturn($merged['city']);
        $address->method('getRegionId')->willReturn($merged['regionId']);
        $address->method('getCountryId')->willReturn($merged['countryId']);
        $address->method('getShippingAmount')->willReturn($merged['shippingAmount']);
        return $address;
    }

    // ── C2: authorizeCapture ──

    /** C2.1: happy-path — ResponseType=OK, returns true. */
    public function testAuthorizeCaptureSuccess()
    {
        $this->configureAuthorizeCaptureScopeConfig();
        $order = $this->buildOrderForAuthorizeCapture();
        $this->setUpPassThroughDataObject();

        $response = $this->buildAuthorizedWithCaptureResponse('OK');
        $this->mockSoapClient->method('authorizedWithCapture')->willReturn($response);

        $this->assertTrue($this->api->authorizeCapture($order));
    }

    /**
     * C2.2: TaxCloud's "already authorized" duplicate-message must be treated as success.
     */
    public function testAuthorizeCaptureTreatsDuplicateAsSuccess()
    {
        $this->configureAuthorizeCaptureScopeConfig();
        $order = $this->buildOrderForAuthorizeCapture();
        $this->setUpPassThroughDataObject();

        $response = $this->buildAuthorizedWithCaptureResponse(
            'Error',
            'This transaction has already been marked as authorized'
        );
        $this->mockSoapClient->method('authorizedWithCapture')->willReturn($response);

        $this->assertTrue($this->api->authorizeCapture($order));
    }

    /** C2.3: non-OK non-duplicate response — returns false. */
    public function testAuthorizeCaptureReturnsFalseOnNonOkNonDuplicate()
    {
        $this->configureAuthorizeCaptureScopeConfig();
        $order = $this->buildOrderForAuthorizeCapture();
        $this->setUpPassThroughDataObject();

        $response = $this->buildAuthorizedWithCaptureResponse('Error', 'Some other error');
        $this->mockSoapClient->method('authorizedWithCapture')->willReturn($response);

        $this->assertFalse($this->api->authorizeCapture($order));
    }

    /** C2.4: first attempt throws SoapFault, retry succeeds — returns true. */
    public function testAuthorizeCaptureRetriesOnSoapFault()
    {
        $this->configureAuthorizeCaptureScopeConfig();
        $order = $this->buildOrderForAuthorizeCapture();
        $this->setUpPassThroughDataObject();

        $response = $this->buildAuthorizedWithCaptureResponse('OK');
        $this->mockSoapClient->expects($this->exactly(2))
            ->method('authorizedWithCapture')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \SoapFault('SOAP-ERROR', 'transient')),
                $response
            );

        $this->assertTrue($this->api->authorizeCapture($order));
    }

    /** C2.5: both attempts throw — returns false. */
    public function testAuthorizeCaptureReturnsFalseWhenBothAttemptsThrow()
    {
        $this->configureAuthorizeCaptureScopeConfig();
        $order = $this->buildOrderForAuthorizeCapture();
        $this->setUpPassThroughDataObject();

        $this->mockSoapClient->method('authorizedWithCapture')->willThrowException(
            new \SoapFault('SOAP-ERROR', 'persistent')
        );

        $this->assertFalse($this->api->authorizeCapture($order));
    }

    /** C2.6: getClient()=null — returns false without attempting SOAP. */
    public function testAuthorizeCaptureReturnsFalseWhenClientIsNull()
    {
        $this->configureAuthorizeCaptureScopeConfig();
        $order = $this->buildOrderForAuthorizeCapture();
        $this->setUpPassThroughDataObject();

        // Force the client to be falsy without hitting the network on lazy init.
        // Make the SoapClient factory fail so getClient() returns null without
        // touching the network (mirrors a failed WSDL fetch).
        $this->soapClientFactory = $this->createMock(ClientFactory::class);
        $this->soapClientFactory->method('create')->willThrowException(new \Exception('WSDL unavailable'));
        $this->constructApi();

        $this->mockSoapClient->expects($this->never())->method('authorizedWithCapture');

        $this->assertFalse($this->api->authorizeCapture($order));
    }

    private function configureAuthorizeCaptureScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
            ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
        ]);
    }

    private function buildOrderForAuthorizeCapture(): \Magento\Sales\Model\Order
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORD_AUTH');
        return $order;
    }

    private function buildAuthorizedWithCaptureResponse(string $responseType, string $message = ''): \stdClass
    {
        $response = new \stdClass();
        $response->AuthorizedWithCaptureResult = new \stdClass();
        $response->AuthorizedWithCaptureResult->ResponseType = $responseType;
        $response->AuthorizedWithCaptureResult->Messages = new \stdClass();
        $response->AuthorizedWithCaptureResult->Messages->ResponseMessage = new \stdClass();
        $response->AuthorizedWithCaptureResult->Messages->ResponseMessage->Message = $message;
        return $response;
    }

    // ── C3: verifyAddress ──

    /** C3.1: happy path — verifyResult.ErrNumber=0 — returns the mapped address. */
    public function testVerifyAddressSuccessReturnsResultArray()
    {
        $this->configureVerifyAddressScopeConfig('0');
        $this->cacheType->method('load')->willReturn(false);
        $this->setUpPassThroughDataObject();

        $verifyResponse = $this->buildVerifyAddressResponse([
            'ErrNumber' => 0,
            'Address1' => '350 5TH AVE',
            'Address2' => '',
            'City' => 'NEW YORK',
            'State' => 'NY',
            'Zip5' => '10118',
            'Zip4' => '0110',
        ]);
        $this->mockSoapClient->method('verifyAddress')->willReturn($verifyResponse);
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));

        $result = $this->api->verifyAddress([
            'Address1' => '350 5th Ave',
            'Address2' => '',
            'City' => 'New York',
            'State' => 'NY',
            'Zip5' => '10118',
            'Zip4' => '',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('350 5TH AVE', $result['Address1']);
        $this->assertSame('NY', $result['State']);
        $this->assertSame('10118', $result['Zip5']);
    }

    /** C3.2: cache hit — returns the cached entry without hitting SOAP. */
    public function testVerifyAddressReturnsCachedResultOnSecondCall()
    {
        $this->configureVerifyAddressScopeConfig('3600');
        $this->setUpPassThroughDataObject();

        $storage = [];
        // Use `function` with `use (&$storage)` — arrow fns capture by value, so
        // they wouldn't see the save() callback's writes to $storage.
        $this->cacheType->method('load')->willReturnCallback(function ($k) use (&$storage) {
            return $storage[$k] ?? false;
        });
        $this->cacheType->method('save')->willReturnCallback(function ($data, $key) use (&$storage) {
            $storage[$key] = $data;
            return true;
        });
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));
        $this->serializer->method('unserialize')->willReturnCallback(fn($v) => json_decode($v, true));

        $verifyResponse = $this->buildVerifyAddressResponse([
            'ErrNumber' => 0,
            'Address1' => '350 5TH AVE',
            'Address2' => '',
            'City' => 'NEW YORK',
            'State' => 'NY',
            'Zip5' => '10118',
            'Zip4' => '0110',
        ]);
        $this->mockSoapClient->expects($this->once())->method('verifyAddress')->willReturn($verifyResponse);

        $address = [
            'Address1' => '350 5th Ave',
            'Address2' => '',
            'City' => 'New York',
            'State' => 'NY',
            'Zip5' => '10118',
            'Zip4' => '',
        ];

        $first = $this->api->verifyAddress($address);
        $second = $this->api->verifyAddress($address);

        $this->assertSame($first, $second);
    }

    /** C3.3: ErrNumber != 0 — returns false. */
    public function testVerifyAddressReturnsFalseOnErrNumberNonZero()
    {
        $this->configureVerifyAddressScopeConfig('0');
        $this->cacheType->method('load')->willReturn(false);
        $this->setUpPassThroughDataObject();

        $verifyResponse = $this->buildVerifyAddressResponse([
            'ErrNumber' => 1,
            'ErrDescription' => 'Address not found',
            'Address1' => '',
            'Address2' => '',
            'City' => '',
            'State' => '',
            'Zip5' => '',
            'Zip4' => '',
        ]);
        $this->mockSoapClient->method('verifyAddress')->willReturn($verifyResponse);

        $result = $this->api->verifyAddress([
            'Address1' => 'bogus',
            'Address2' => '',
            'City' => '',
            'State' => 'XX',
            'Zip5' => '00000',
            'Zip4' => '',
        ]);

        $this->assertFalse($result);
    }

    /** C3.4: first SOAP attempt throws, retry succeeds — returns mapped address. */
    public function testVerifyAddressRetriesOnSoapFault()
    {
        $this->configureVerifyAddressScopeConfig('0');
        $this->cacheType->method('load')->willReturn(false);
        $this->setUpPassThroughDataObject();
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));

        $verifyResponse = $this->buildVerifyAddressResponse([
            'ErrNumber' => 0,
            'Address1' => 'X',
            'Address2' => '',
            'City' => 'Y',
            'State' => 'NY',
            'Zip5' => '10001',
            'Zip4' => '',
        ]);
        $this->mockSoapClient->expects($this->exactly(2))
            ->method('verifyAddress')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \SoapFault('SOAP-ERROR', 'transient')),
                $verifyResponse
            );

        $result = $this->api->verifyAddress([
            'Address1' => 'X', 'Address2' => '', 'City' => 'Y', 'State' => 'NY', 'Zip5' => '10001', 'Zip4' => '',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('X', $result['Address1']);
    }

    private function configureVerifyAddressScopeConfig(string $cacheLifetime): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
            ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $cacheLifetime],
        ]);
    }

    private function buildVerifyAddressResponse(array $fields): \stdClass
    {
        $response = new \stdClass();
        $response->VerifyAddressResult = new \stdClass();
        foreach ($fields as $k => $v) {
            $response->VerifyAddressResult->$k = $v;
        }
        return $response;
    }

    // ── C4: Magento fallback when SOAP returns Error response (not exception) ──

    /**
     * C4: SOAP returns a non-OK ResponseType. With fallback_to_magento=1 the
     * second isFallbackToMagentoEnabled branch (Api.php:726) must trigger.
     */
    public function testLookupTaxesFallsBackToMagentoWhenSoapReturnsErrorResponse()
    {
        $this->configureBaseLookupScopeConfig('0', [
            ['tax/taxcloud_settings/fallback_to_magento', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
        ]);
        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->cacheType->method('load')->willReturn(false);
        $this->setUpPassThroughDataObject();

        // SOAP succeeds (no exception) but returns an Error response.
        $errorResponse = new \stdClass();
        $errorResponse->LookupResult = new \stdClass();
        $errorResponse->LookupResult->ResponseType = 'Error';
        $errorResponse->LookupResult->CartItemsResponse = new \stdClass();
        $errorResponse->LookupResult->CartItemsResponse->CartItemResponse = [];
        $this->mockSoapClient->method('lookup')->willReturn($errorResponse);

        $this->stubMagentoFallbackCollaborators(3.33);

        $this->taxCalculationService->expects($this->once())->method('calculateTax');

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame(3.33, $result[Api::ITEM_TYPE_PRODUCT]['item-1'] ?? null);
    }

    // ── C6: origin validation ──

    /**
     * C6: invalid origin postcode (e.g. all letters) — PostalCodeParser::isValid()
     * returns false → getOrigin() returns null → lookupTaxes returns empty result.
     */
    public function testLookupTaxesReturnsEarlyWhenOriginPostcodeInvalid()
    {
        // Override the origin postcode with an invalid value.
        $this->configureBaseLookupScopeConfig('0', [
            ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'BAD'],
        ]);
        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->cacheType->method('load')->willReturn(false);
        $this->setUpPassThroughDataObject();

        $this->mockSoapClient->expects($this->never())->method('lookup');

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame([], $result[Api::ITEM_TYPE_PRODUCT] ?? null);
        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING] ?? null);
    }

    // ─── Section 11: config / guardrails ────────────────────────────────────

    /**
     * Section 11.2: when the SOAP client cannot be built, lookupTaxes must return
     * the initial empty result without throwing. (Logging is exercised by the
     * anonymous logger stub the production code installs when logging=0.)
     */
    public function testLookupTaxesLogsAndReturnsEmptyResultWhenSoapClientCannotBeBuilt()
    {
        $this->configureBaseLookupScopeConfig('0');
        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->setUpPassThroughDataObject();
        $this->cacheType->method('load')->willReturn(false);

        // Force getClient() to return falsy. Setting client=false skips both the
        // `=== null` lazy-init branch and the network call to fetch the WSDL.
        // Make the SoapClient factory fail so getClient() returns null without
        // touching the network (mirrors a failed WSDL fetch).
        $this->soapClientFactory = $this->createMock(ClientFactory::class);
        $this->soapClientFactory->method('create')->willThrowException(new \Exception('WSDL unavailable'));
        $this->constructApi();

        $this->mockSoapClient->expects($this->never())->method('lookup');

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame([], $result[Api::ITEM_TYPE_PRODUCT] ?? null);
        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING] ?? null);
    }

    /**
     * Section 11.4: guest checkout (customer present but no customer ID) must send
     * the configured guest_customer_id to the SOAP lookup.
     */
    public function testGuestCheckoutUsesConfiguredGuestCustomerId()
    {
        $this->configureBaseLookupScopeConfig('0', [
            ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
        ]);
        [$shippingAssignment, $quote, $itemsByType] = $this->buildGuestCheckoutContext();
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->setUpPassThroughDataObject();
        $this->cacheType->method('load')->willReturn(false);

        $capturedCustomerId = null;
        $this->mockSoapClient->method('lookup')->willReturnCallback(
            function ($params) use (&$capturedCustomerId) {
                $capturedCustomerId = $params['customerID'] ?? null;
                return $this->buildBareLookupResponse(0);
            }
        );
        $this->useRealCartItemResponseHandler();

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame('-1', $capturedCustomerId);
    }

    /**
     * Section 11.5: guest_customer_id config returns null — the `?? '-1'` default at
     * Api.php:268 must kick in so the SOAP call still gets a valid customerID.
     */
    public function testGuestCheckoutDefaultsToMinusOneWhenConfigIsNull()
    {
        // Note: we deliberately DON'T pass an override for guest_customer_id, so the
        // base map's value applies. The base map returns null for unmatched keys —
        // but it DOES include guest_customer_id with default '-1'. To force null here,
        // build the scope config directly without using the helper.
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
            ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, null],
            ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
            ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
            ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
            ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
            ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
        ]);

        [$shippingAssignment, $quote, $itemsByType] = $this->buildGuestCheckoutContext();
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->setUpPassThroughDataObject();
        $this->cacheType->method('load')->willReturn(false);

        $capturedCustomerId = null;
        $this->mockSoapClient->method('lookup')->willReturnCallback(
            function ($params) use (&$capturedCustomerId) {
                $capturedCustomerId = $params['customerID'] ?? null;
                return $this->buildBareLookupResponse(0);
            }
        );
        $this->useRealCartItemResponseHandler();

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame(
            '-1',
            $capturedCustomerId,
            'Null guest_customer_id config must default to "-1" via the ?? in Api::getGuestCustomerId'
        );
    }

    /**
     * Build a lookupTaxes context like buildBasicSingleItemLookupContext but with a
     * guest customer (customer present, getId() returns null).
     */
    private function buildGuestCheckoutContext(): array
    {
        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('NY');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        // Guest: no ID
        $customer->method('getId')->willReturn(null);
        $customer->method('getCustomAttribute')->willReturn(null);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('10001');
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('New York');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $quoteItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrice', 'getProduct', 'getQty', 'getSku', 'getDiscountAmount', 'getTaxCalculationItemId'])
            ->getMock();
        $quoteItem->method('getTaxCalculationItemId')->willReturn('item-1');
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getQty')->willReturn(1);
        $quoteItem->method('getPrice')->willReturn(10.00);
        $quoteItem->method('getDiscountAmount')->willReturn(0);
        $quoteItem->method('getSku')->willReturn('SKU-1');

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $productTaxDetail = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $productTaxDetail->method('getRowTotal')->willReturn(10.00);

        $itemsByType = [
            Api::ITEM_TYPE_PRODUCT => [
                'item-1' => [Api::KEY_ITEM => $productTaxDetail],
            ],
        ];

        return [$shippingAssignment, $quote, $itemsByType];
    }

    // ─── Section 10: caching behavior ───────────────────────────────────────

    /**
     * Section 10.1: cache_lifetime=0 must short-circuit the cache. Even when there is a
     * prior cached entry, lookupTaxes must always go live (the && getCacheLifetime() guard
     * at Api.php:638 is the gate).
     */
    public function testLookupTaxesAlwaysCallsLiveApiWhenCacheLifetimeIsZero()
    {
        $this->configureBaseLookupScopeConfig('0');

        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->setUpPassThroughDataObject();

        // Simulate a prior cached entry: cache->load always returns serialized data.
        // serializer->unserialize must therefore yield a populated result.
        $this->cacheType->method('load')->willReturn('"prior-cache-entry"');
        $this->serializer->method('unserialize')->willReturn([
            Api::ITEM_TYPE_PRODUCT => ['item-1' => 999.99],
            Api::ITEM_TYPE_SHIPPING => 0,
        ]);
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));

        // SOAP lookup must be called every time because the cache short-circuit needs
        // getCacheLifetime() > 0.
        $lookupResponse = $this->buildBareLookupResponse(0);
        $this->mockSoapClient->expects($this->exactly(2))
            ->method('lookup')
            ->willReturn($lookupResponse);

        $this->useRealCartItemResponseHandler();

        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);
        $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);
    }

    /**
     * Section 10.2: cache_lifetime=3600 with an in-memory CacheInterface.
     * Two identical lookupTaxes calls — first goes live + writes to cache,
     * second is served from cache. SOAP lookup must be called only once.
     */
    public function testLookupTaxesReturnsCachedResultOnSecondCallWithIdenticalParams()
    {
        $this->configureBaseLookupScopeConfig('3600');

        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');
        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->setUpPassThroughDataObject();

        $this->wireInMemoryCache();

        // Mock SOAP lookup ONCE — second call must be served from cache.
        $lookupResponse = $this->buildBareLookupResponse(1.23);
        $this->mockSoapClient->expects($this->once())
            ->method('lookup')
            ->willReturn($lookupResponse);

        $this->useRealCartItemResponseHandler();

        $firstResult = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);
        $secondResult = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame(
            $firstResult,
            $secondResult,
            'Cached result should match the live result on the second identical call'
        );
        $this->assertSame(1.23, $firstResult[Api::ITEM_TYPE_PRODUCT]['item-1']);
    }

    /**
     * Section 10.3: changing the destination address invalidates the cache key.
     * Two lookupTaxes calls with different destination states must each hit SOAP and
     * write under different cache keys.
     */
    public function testLookupTaxesCacheMissesWhenDestinationAddressChanges()
    {
        $this->configureBaseLookupScopeConfig('3600');

        // regionFactory->create()->load($id)->getCode() — return a different state per region id.
        $regionFactoryReturn = $this->createMock(\Magento\Directory\Model\Region::class);
        $regionFactoryReturn->method('load')->willReturnCallback(function ($id) {
            $code = $id === 1 ? 'NY' : 'CA';
            $r = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getCode'])
                ->getMock();
            $r->method('getCode')->willReturn($code);
            return $r;
        });
        $this->regionFactory->method('create')->willReturn($regionFactoryReturn);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        // Two shippingAssignments with different addresses.
        $shippingAssignmentNY = $this->buildShippingAssignmentForLookup(regionId: 1, postcode: '10001', city: 'New York');
        $shippingAssignmentCA = $this->buildShippingAssignmentForLookup(regionId: 2, postcode: '94101', city: 'San Francisco');

        $productTaxDetail = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $productTaxDetail->method('getRowTotal')->willReturn(10.00);
        $itemsByType = [
            Api::ITEM_TYPE_PRODUCT => [
                'item-1' => [Api::KEY_ITEM => $productTaxDetail],
            ],
        ];

        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->setUpPassThroughDataObject();

        $cacheKeysSeen = [];
        $cacheStorage = [];
        $this->cacheType->method('load')->willReturnCallback(function ($key) use (&$cacheKeysSeen, &$cacheStorage) {
            $cacheKeysSeen[] = $key;
            return $cacheStorage[$key] ?? false;
        });
        $this->cacheType->method('save')->willReturnCallback(function ($data, $key) use (&$cacheStorage) {
            $cacheStorage[$key] = $data;
            return true;
        });
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));
        $this->serializer->method('unserialize')->willReturnCallback(fn($v) => json_decode($v, true));

        // Both calls must hit SOAP — different destinations, different cache keys.
        $lookupResponse = $this->buildBareLookupResponse(1.23);
        $this->mockSoapClient->expects($this->exactly(2))
            ->method('lookup')
            ->willReturn($lookupResponse);

        $this->useRealCartItemResponseHandler();

        $this->api->lookupTaxes($itemsByType, $shippingAssignmentNY, $quote);
        $this->api->lookupTaxes($itemsByType, $shippingAssignmentCA, $quote);

        $distinctKeys = array_values(array_unique($cacheKeysSeen));
        $this->assertCount(
            2,
            $distinctKeys,
            'Each destination must produce a distinct cache key (saw: ' . implode(', ', $cacheKeysSeen) . ')'
        );
    }

    /**
     * Helper: produce a ShippingAssignment whose shipping address matches the given
     * postcode/city/regionId, with one mock $10 product item.
     */
    private function buildShippingAssignmentForLookup(int $regionId, string $postcode, string $city): \Magento\Quote\Api\Data\ShippingAssignmentInterface
    {
        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn($city);
        $address->method('getRegionId')->willReturn($regionId);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $quoteItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrice', 'getProduct', 'getQty', 'getSku', 'getDiscountAmount', 'getTaxCalculationItemId'])
            ->getMock();
        $quoteItem->method('getTaxCalculationItemId')->willReturn('item-1');
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getQty')->willReturn(1);
        $quoteItem->method('getPrice')->willReturn(10.00);
        $quoteItem->method('getDiscountAmount')->willReturn(0);
        $quoteItem->method('getSku')->willReturn('SKU-1');

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        return $shippingAssignment;
    }

    /**
     * Wire CacheInterface as an in-memory dict + serializer as JSON pass-through.
     */
    private function wireInMemoryCache(): void
    {
        $storage = [];
        $this->cacheType->method('load')->willReturnCallback(function ($key) use (&$storage) {
            return $storage[$key] ?? false;
        });
        $this->cacheType->method('save')->willReturnCallback(function ($data, $key) use (&$storage) {
            $storage[$key] = $data;
            return true;
        });
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));
        $this->serializer->method('unserialize')->willReturnCallback(fn($v) => json_decode($v, true));
    }

    /**
     * Build a minimal "OK" SOAP lookup response with one cart-item TaxAmount.
     */
    private function buildBareLookupResponse(float $taxAmount): \stdClass
    {
        $response = new \stdClass();
        $response->LookupResult = new \stdClass();
        $response->LookupResult->ResponseType = 'OK';
        $response->LookupResult->CartItemsResponse = new \stdClass();
        $response->LookupResult->CartItemsResponse->CartItemResponse = [
            (object)['CartItemIndex' => 0, 'TaxAmount' => $taxAmount],
        ];
        return $response;
    }

    // ─── Section 9: fallback to Magento when SOAP fails ─────────────────────

    /**
     * Section 9.1: fallback_to_magento=1 — SOAP lookup throws on both initial + retry;
     * the method must return the Magento-calculated result and TaxCalculationInterface
     * must have been invoked.
     */
    public function testLookupTaxesFallsBackToMagentoWhenSoapFailsAndFallbackEnabled()
    {
        $this->configureBaseLookupScopeConfig('0', [
            ['tax/taxcloud_settings/fallback_to_magento', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
        ]);

        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');

        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->cacheType->method('load')->willReturn(false);

        $this->setUpPassThroughDataObject();

        // SOAP lookup throws on every attempt (Api retries once internally).
        $this->mockSoapClient->method('lookup')->willThrowException(
            new \SoapFault('SOAP-ERROR', 'Service unavailable')
        );

        // Stub the Magento tax-calculation collaborators just enough that
        // getMagentoTaxRates() can run end-to-end.
        $this->stubMagentoFallbackCollaborators(2.50);

        $this->taxCalculationService->expects($this->once())->method('calculateTax');

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertArrayHasKey('item-1', $result[Api::ITEM_TYPE_PRODUCT] ?? []);
        $this->assertSame(
            2.50,
            $result[Api::ITEM_TYPE_PRODUCT]['item-1'],
            'lookupTaxes must surface the Magento-calculated tax when falling back'
        );
    }

    /**
     * Section 9.2: fallback_to_magento=0 — SOAP fails, fallback disabled — the
     * initial empty result is returned and TaxCalculationInterface is NOT invoked.
     */
    public function testLookupTaxesReturnsEmptyResultWhenSoapFailsAndFallbackDisabled()
    {
        $this->configureBaseLookupScopeConfig('0', [
            ['tax/taxcloud_settings/fallback_to_magento', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        [$shippingAssignment, $quote, $itemsByType] = $this->buildBasicSingleItemLookupContext('NY', '10001');

        $this->productTicService->method('getProductTic')->willReturn('00000');
        $this->cacheType->method('load')->willReturn(false);

        $this->setUpPassThroughDataObject();

        $this->mockSoapClient->method('lookup')->willThrowException(
            new \SoapFault('SOAP-ERROR', 'Service unavailable')
        );

        $this->taxCalculationService->expects($this->never())->method('calculateTax');

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame([], $result[Api::ITEM_TYPE_PRODUCT] ?? null, 'No product tax should be recorded');
        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING] ?? null, 'No shipping tax should be recorded');
    }

    /**
     * Build the minimum lookupTaxes context with a single $10 product item.
     * Returns [$shippingAssignment, $quote, $itemsByType].
     */
    private function buildBasicSingleItemLookupContext(string $stateCode, string $postcode): array
    {
        $region = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn($stateCode);
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getCustomerTaxClassId')->willReturn('3');

        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('City');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $product = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ProductDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $quoteItem = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrice', 'getProduct', 'getQty', 'getSku', 'getDiscountAmount', 'getTaxCalculationItemId'])
            ->getMock();
        $quoteItem->method('getTaxCalculationItemId')->willReturn('item-1');
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getQty')->willReturn(1);
        $quoteItem->method('getPrice')->willReturn(10.00);
        $quoteItem->method('getDiscountAmount')->willReturn(0);
        $quoteItem->method('getSku')->willReturn('SKU-1');

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $productTaxDetail = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $productTaxDetail->method('getRowTotal')->willReturn(10.00);

        $itemsByType = [
            Api::ITEM_TYPE_PRODUCT => [
                'item-1' => [Api::KEY_ITEM => $productTaxDetail],
            ],
        ];

        return [$shippingAssignment, $quote, $itemsByType];
    }

    /**
     * Wire enough Magento tax-API stubs that getMagentoTaxRates can complete.
     * The Magento calculator returns a single $itemRowTax for code 'item-1' (product type).
     */
    private function stubMagentoFallbackCollaborators(float $itemRowTax): void
    {
        // calculateTax() type-hints QuoteDetailsInterface, so the factory mocks
        // must produce real interface mocks — stdClass stand-ins get rejected
        // at the call boundary.
        $customerAddress = $this->createMock(\Magento\Customer\Api\Data\AddressInterface::class);
        $this->customerAddressFactory->method('create')->willReturn($customerAddress);

        $quoteDetails = $this->createMock(\Magento\Tax\Api\Data\QuoteDetailsInterface::class);
        $this->quoteDetailsFactory->method('create')->willReturn($quoteDetails);

        $quoteDetailsItem = $this->createMock(\Magento\Tax\Api\Data\QuoteDetailsItemInterface::class);
        $this->quoteDetailsItemFactory->method('create')->willReturn($quoteDetailsItem);

        $taxClassKey = $this->createMock(\Magento\Tax\Api\Data\TaxClassKeyInterface::class);
        $this->taxClassKeyFactory->method('create')->willReturn($taxClassKey);

        // Calculator returns a TaxDetails result with one product item.
        $taxDetailsItem = $this->createMock(\Magento\Tax\Api\Data\TaxDetailsItemInterface::class);
        $taxDetailsItem->method('getCode')->willReturn('item-1');
        $taxDetailsItem->method('getType')->willReturn(Api::ITEM_TYPE_PRODUCT);
        $taxDetailsItem->method('getRowTax')->willReturn($itemRowTax);

        $taxDetails = $this->createMock(\Magento\Tax\Api\Data\TaxDetailsInterface::class);
        $taxDetails->method('getItems')->willReturn([$taxDetailsItem]);

        $this->taxCalculationService->method('calculateTax')->willReturn($taxDetails);
    }

    /**
     * Shared scope-config map for the lookupTaxes tests in Sections 1, 9, 10, 11.
     * Pass cache_lifetime as a string so willReturnMap matches the int Api expects.
     *
     * If an entry in $extras shares the same lookup key as one in $base, the extra
     * replaces it (otherwise willReturnMap would always return the first match).
     */
    private function configureBaseLookupScopeConfig(string $cacheLifetime = '0', array $extras = []): void
    {
        $base = [
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
            ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $cacheLifetime],
            ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
            ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
            ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
            ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
            ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
            ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
        ];

        // Apply overrides: extras win when they match base's lookup tuple (first 3 cols).
        $extrasKeyed = [];
        foreach ($extras as $extra) {
            $extrasKeyed[implode('|', array_slice($extra, 0, 3))] = $extra;
        }
        $merged = [];
        $seen = [];
        foreach ($base as $entry) {
            $key = implode('|', array_slice($entry, 0, 3));
            if (isset($extrasKeyed[$key])) {
                $merged[] = $extrasKeyed[$key];
                $seen[$key] = true;
            } else {
                $merged[] = $entry;
            }
        }
        // Append any extras that didn't overlap with base.
        foreach ($extrasKeyed as $key => $extra) {
            if (!isset($seen[$key])) {
                $merged[] = $extra;
            }
        }

        $this->scopeConfig->method('getValue')->willReturnMap($merged);
    }

    /**
     * Configure mockDataObject as a pass-through holder for taxcloud_lookup_before /
     * taxcloud_lookup_after — captures params/results in closure-scoped variables and
     * returns them unchanged. Used by Section 1+ lookupTaxes tests that want the real
     * params/results flowing through.
     */
    private function setUpPassThroughDataObject(): void
    {
        $params = null;
        $result = null;
        $this->mockDataObject->method('setParams')->willReturnCallback(function ($p) use (&$params) {
            $params = $p;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getParams')->willReturnCallback(function () use (&$params) {
            return $params;
        });
        $this->mockDataObject->method('setResult')->willReturnCallback(function ($r) use (&$result) {
            $result = $r;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getResult')->willReturnCallback(function () use (&$result) {
            return $result;
        });
        $this->objectFactory->method('create')->willReturn($this->mockDataObject);
    }

    // ─── SOAP timeout + bounded retry (DEV-8596) ──────────────────────────────

    /**
     * buildSoapOptions(): uses the default timeout when api_timeout is unset
     * and always pins cache_wsdl => WSDL_CACHE_BOTH plus a stream-context timeout.
     */
    public function testBuildSoapOptionsUsesDefaultTimeoutAndCachesWsdl()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/api_timeout', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, null],
            ]);

        $options = $this->api->buildSoapOptions();

        $this->assertSame(Api::DEFAULT_SOAP_TIMEOUT, $options['connection_timeout']);
        $this->assertSame(WSDL_CACHE_BOTH, $options['cache_wsdl']);
        $this->assertTrue($options['keep_alive']);
        $this->assertIsResource($options['stream_context']);

        $ctx = stream_context_get_options($options['stream_context']);
        $this->assertSame(Api::DEFAULT_SOAP_TIMEOUT, $ctx['http']['timeout']);
        $this->assertSame(Api::DEFAULT_SOAP_TIMEOUT, $ctx['ssl']['timeout']);
    }

    /**
     * buildSoapOptions(): honors a configured api_timeout for both the
     * connection timeout and the stream-context read timeout.
     */
    public function testBuildSoapOptionsUsesConfiguredTimeout()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/api_timeout', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '3'],
            ]);

        $options = $this->api->buildSoapOptions();

        $this->assertSame(3, $options['connection_timeout']);
        $ctx = stream_context_get_options($options['stream_context']);
        $this->assertSame(3, $ctx['http']['timeout']);
    }

    /**
     * @dataProvider timeoutErrorProvider
     */
    #[DataProvider('timeoutErrorProvider')]
    public function testIsTimeoutErrorClassification(\Throwable $error, bool $expected, string $message)
    {
        $this->assertSame($expected, $this->api->isTimeoutError($error), $message);
    }

    public static function timeoutErrorProvider(): array
    {
        return [
            'http faultcode is a timeout' => [
                new \SoapFault('HTTP', 'Error Fetching http headers'),
                true,
                'HTTP faultcode should be treated as a timeout',
            ],
            'message says timed out' => [
                new \SoapFault('Server', 'Connection timed out after 10000ms'),
                true,
                'A "timed out" message should be treated as a timeout',
            ],
            'could not connect' => [
                new \SoapFault('Server', 'Could not connect to host'),
                true,
                'A connect failure should be treated as a timeout',
            ],
            'generic server fault is not a timeout' => [
                new \SoapFault('Server', 'Internal server error'),
                false,
                'A generic server fault should be retried (not a timeout)',
            ],
            'generic exception is not a timeout' => [
                new \RuntimeException('something else'),
                false,
                'A non-timeout exception should be retried',
            ],
        ];
    }

    /**
     * callSoapWithRetry(): retries exactly once on a non-timeout fault.
     */
    public function testCallSoapWithRetryRetriesOnceOnTransientFault()
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        $attempts = 0;
        $result = $this->api->callSoapWithRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new \SoapFault('Server', 'Transient blip');
            }
            return 'ok';
        });

        $this->assertSame(2, $attempts, 'A transient fault should trigger exactly one retry');
        $this->assertSame('ok', $result);
    }

    /**
     * callSoapWithRetry(): honors a configurable retry count.
     */
    public function testCallSoapWithRetryHonorsMaxRetries()
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        $attempts = 0;
        $result = $this->api->callSoapWithRetry(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \SoapFault('Server', 'Transient blip');
            }
            return 'ok';
        }, 2);

        $this->assertSame(3, $attempts, 'maxRetries=2 should allow up to three total attempts');
        $this->assertSame('ok', $result);
    }

    /**
     * callSoapWithRetry(): does NOT retry on a timeout — rethrows immediately.
     */
    public function testCallSoapWithRetryDoesNotRetryOnTimeout()
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        $attempts = 0;
        $this->expectException(\SoapFault::class);
        try {
            $this->api->callSoapWithRetry(function () use (&$attempts) {
                $attempts++;
                throw new \SoapFault('HTTP', 'Error Fetching http headers');
            });
        } finally {
            $this->assertSame(1, $attempts, 'A timeout must not be retried');
        }
    }

    /**
     * Configure a minimal-but-complete lookup context so lookupTaxes() reaches
     * the SOAP lookup() call. Leaves the lookup() stub to the caller.
     *
     * @param string $fallbackEnabled '0' or '1'
     * @return array [$itemsByType, $shippingAssignment, $quote]
     */
    private function configureLookupContext(string $fallbackEnabled): array
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
                ['tax/taxcloud_settings/fallback_to_magento', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $fallbackEnabled],
                ['shipping/origin/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '60005'],
                ['shipping/origin/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '71 W Seegers Rd'],
                ['shipping/origin/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, ''],
                ['shipping/origin/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'Arlington Heights'],
                ['shipping/origin/region_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $region = $this->getMockBuilder(Dbl\RegionDouble::class)
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('GA');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $customer->method('getCustomAttribute')->willReturn(null);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);
        $quote->method('getId')->willReturn(999);

        $address = $this->getMockBuilder(Dbl\QuoteAddressDouble::class)
            ->onlyMethods(['getPostcode', 'getStreet', 'getCity', 'getRegionId', 'getCountryId', 'getShippingAmount'])
            ->getMock();
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(5.00);

        $shipping = $this->getMockBuilder(Dbl\QuoteAddressDouble::class)
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->getMockBuilder(Dbl\ItemDetailsDouble::class)
            ->onlyMethods(['getRowTotal'])
            ->getMock();
        $shippingTaxDetailItem->method('getRowTotal')->willReturn(5.00);
        $itemsByType = [
            Api::ITEM_TYPE_SHIPPING => [
                'shipping' => [Api::KEY_ITEM => $shippingTaxDetailItem],
            ],
        ];

        $this->productTicService->method('getShippingTic')->willReturn('11010');
        $this->cacheType->method('load')->willReturn(false);

        // DataObject pass-through for the taxcloud_lookup_before event.
        $capturedParams = null;
        $this->mockDataObject->method('setParams')->willReturnCallback(function ($p) use (&$capturedParams) {
            $capturedParams = $p;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getParams')->willReturnCallback(function () use (&$capturedParams) {
            return $capturedParams;
        });
        $this->mockDataObject->method('setResult')->willReturnSelf();
        $this->objectFactory->method('create')->willReturn($this->mockDataObject);

        return [$itemsByType, $shippingAssignment, $quote];
    }

    /**
     * lookupTaxes(): a transient (non-timeout) SOAP fault is retried once.
     * With fallback disabled and the retry also failing, a zero result returns.
     */
    public function testLookupTaxesRetriesOnceOnTransientSoapFault()
    {
        [$itemsByType, $shippingAssignment, $quote] = $this->configureLookupContext('0');

        $this->mockSoapClient->expects($this->exactly(2))
            ->method('lookup')
            ->willThrowException(new \SoapFault('Server', 'Transient blip'));

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING]);
        $this->assertSame([], $result[Api::ITEM_TYPE_PRODUCT]);
    }

    /**
     * lookupTaxes(): a timeout fails fast — lookup() is called exactly once
     * (no retry) so checkout doesn't wait through a second stall.
     */
    public function testLookupTaxesDoesNotRetryOnTimeout()
    {
        [$itemsByType, $shippingAssignment, $quote] = $this->configureLookupContext('0');

        $this->mockSoapClient->expects($this->once())
            ->method('lookup')
            ->willThrowException(new \SoapFault('HTTP', 'Error Fetching http headers'));

        $result = $this->api->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING]);
    }
}