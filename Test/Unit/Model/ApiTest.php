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
use Taxcloud\Magento2\Model\Api;
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

class ApiTest extends TestCase
{
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
        $this->mockSoapClient = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['Returned', 'lookup', 'authorizedWithCapture', 'OrderDetails', 'GetExemptCertificates'])
            ->getMock();
        $this->mockDataObject = $this->getMockBuilder(DataObject::class)
            ->disableOriginalConstructor()
            ->addMethods(['setParams', 'getParams', 'setResult', 'getResult'])
            ->getMock();

        $this->api = new Api(
            $this->scopeConfig,
            $this->cacheType,
            $this->eventManager,
            $this->soapClientFactory,
            $this->objectFactory,
            $this->productFactory,
            $this->regionFactory,
            $this->logger,
            $this->serializer,
            $this->cartItemResponseHandler,
            $this->productTicService,
            $this->taxCalculationService,
            $this->quoteDetailsFactory,
            $this->quoteDetailsItemFactory,
            $this->taxClassKeyFactory,
            $this->customerAddressFactory,
            $this->customerAddressRegionFactory,
            $this->refundDistributor
        );
        $this->injectMockSoapClientIntoApi();
    }

    /**
     * Inject mock SoapClient into Api so getClient() returns it (Api uses new \SoapClient in getClient).
     */
    private function injectMockSoapClientIntoApi()
    {
        $ref = new \ReflectionClass(Api::class);
        $prop = $ref->getProperty('client');
        if (method_exists($prop, 'setAccessible')) {
            @$prop->setAccessible(true);
        }
        $prop->setValue($this->api, $this->mockSoapClient);
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
        $order = $this->createMock(\Magento\Sales\Model\Order\Order::class);
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
        $order = $this->createMock(\Magento\Sales\Model\Order\Order::class);
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
        $order = $this->createMock(\Magento\Sales\Model\Order\Order::class);
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
        $order = $this->createMock(\Magento\Sales\Model\Order\Order::class);
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
        $order = $this->createMock(\Magento\Sales\Model\Order\Order::class);
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

        $region = $this->createMock(\Magento\Directory\Model\Region::class);
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('GA');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(13.85);

        $shipping = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->createMock(\Magento\Tax\Api\Data\QuoteDetailsItemInterface::class);
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

        $region = $this->createMock(\Magento\Directory\Model\Region::class);
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('GA');
        $this->regionFactory->method('create')->willReturn($region);

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $address = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(0);

        $shipping = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $shipping->method('getAddress')->willReturn($address);
        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->createMock(\Magento\Tax\Api\Data\QuoteDetailsItemInterface::class);
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
     * Returns an array [$itemsByType, $shippingAssignment, $quote, &$lookupParams]
     * so each test can call lookupTaxes() and inspect what was sent to the SOAP lookup.
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
        $this->mockSoapClient = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['Returned', 'lookup', 'authorizedWithCapture', 'OrderDetails', 'GetExemptCertificates'])
            ->getMock();
        $this->mockDataObject = $this->getMockBuilder(DataObject::class)
            ->disableOriginalConstructor()
            ->addMethods(['setParams', 'getParams', 'setResult', 'getResult'])
            ->getMock();

        $this->api = new Api(
            $this->scopeConfig,
            $this->cacheType,
            $this->eventManager,
            $this->soapClientFactory,
            $this->objectFactory,
            $this->productFactory,
            $this->regionFactory,
            $this->logger,
            $this->serializer,
            $this->cartItemResponseHandler,
            $this->productTicService
        );
        $this->injectMockSoapClientIntoApi();

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

        $region = $this->createMock(\Magento\Directory\Model\Region::class);
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

        $address = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getRegionId')->willReturn(1);
        $address->method('getCountryId')->willReturn('US');
        $address->method('getShippingAmount')->willReturn(5.00);

        $shipping = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        $shippingTaxDetailItem = $this->createMock(\Magento\Tax\Api\Data\QuoteDetailsItemInterface::class);
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
        $lookupParams = null;
        $mockLookupResponse = new \stdClass();
        $mockLookupResponse->LookupResult = new \stdClass();
        $mockLookupResponse->LookupResult->ResponseType = 'OK';
        $mockLookupResponse->LookupResult->CartItemsResponse = new \stdClass();
        $mockLookupResponse->LookupResult->CartItemsResponse->CartItemResponse = [
            (object)['CartItemIndex' => 0, 'TaxAmount' => 0],
        ];
        $this->mockSoapClient->method('lookup')->willReturnCallback(
            function ($params) use (&$lookupParams, $mockLookupResponse) {
                $lookupParams = $params;
                return $mockLookupResponse;
            }
        );

        return [$itemsByType, $shippingAssignment, $quote, &$lookupParams];
    }

    /**
     * @dataProvider exemptCertSoapProvider
     */
    public function testLookupTaxesExemptCertStateFilteringViaSoap(
        string $description,
        array $certExemptStates,
        string $destinationState,
        bool $expectCertSent
    ) {
        $certID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        [$itemsByType, $shippingAssignment, $quote, &$lookupParams] =
            $this->setUpLookupWithCert($certID, $destinationState);

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
    public function testLookupTaxesExemptCertStateFilteringViaCache(
        string $description,
        array $cachedStates,
        string $destinationState,
        bool $expectCertSent
    ) {
        $certID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        [$itemsByType, $shippingAssignment, $quote, &$lookupParams] =
            $this->setUpLookupWithCert($certID, $destinationState);

        $certCacheKey = 'taxcloud_cert_states_' . $certID;
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
        [$itemsByType, $shippingAssignment, $quote, &$lookupParams] =
            $this->setUpLookupWithCert($certID, 'GA');

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
        [$itemsByType, $shippingAssignment, $quote, &$lookupParams] =
            $this->setUpLookupWithCert('', 'GA');

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
        [$itemsByType, $shippingAssignment, $quote, &$lookupParams] =
            $this->setUpLookupWithCert($certID, 'GA');

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
} 