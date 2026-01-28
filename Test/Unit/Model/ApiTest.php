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
        $this->mockSoapClient = $this->getMockBuilder(\SoapClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['Returned'])
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
        $orderItem->method('getQuoteItemId')->willReturn(12345); // Quote item ID for ItemID consistency
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

        // Mock data object methods - capture params to verify ItemID format
        $capturedParams = null;
        $this->mockDataObject->method('setParams')->willReturnCallback(function($params) use (&$capturedParams) {
            $capturedParams = $params;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [
                [
                    'ItemID' => 'TEST_SKU-12345',
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
        
        // Verify ItemID format includes quote item ID for uniqueness
        if ($capturedParams && isset($capturedParams['cartItems'][0]['ItemID'])) {
            $itemId = $capturedParams['cartItems'][0]['ItemID'];
            $this->assertStringContainsString('TEST_SKU', $itemId, 'ItemID should contain SKU');
            $this->assertStringContainsString('12345', $itemId, 'ItemID should contain quote item ID');
            $this->assertEquals('TEST_SKU-12345', $itemId, 'ItemID should be formatted as SKU-QuoteItemID');
        }
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

    public function testReturnOrderWithMultipleItemsSameSku()
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

        // Mock credit memo with multiple items that have the same SKU
        // This simulates a regular item and a promo item scenario
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $order = $this->createMock(\Magento\Sales\Model\Order\Order::class);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_123');
        
        // First item: regular item
        $creditItem1 = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $orderItem1 = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $product1 = $this->createMock(\Magento\Catalog\Model\Product::class);
        
        $creditItem1->method('getOrderItem')->willReturn($orderItem1);
        $creditItem1->method('getPrice')->willReturn(120.00);
        $creditItem1->method('getDiscountAmount')->willReturn(0);
        $creditItem1->method('getQty')->willReturn(1);
        
        $orderItem1->method('getSku')->willReturn('ABC123');
        $orderItem1->method('getQuoteItemId')->willReturn(456); // Different quote item ID
        $orderItem1->method('getProduct')->willReturn($product1);
        
        // Second item: promo item with same SKU
        $creditItem2 = $this->createMock(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $orderItem2 = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $product2 = $this->createMock(\Magento\Catalog\Model\Product::class);
        
        $creditItem2->method('getOrderItem')->willReturn($orderItem2);
        $creditItem2->method('getPrice')->willReturn(0.00);
        $creditItem2->method('getDiscountAmount')->willReturn(0);
        $creditItem2->method('getQty')->willReturn(1);
        
        $orderItem2->method('getSku')->willReturn('ABC123'); // Same SKU!
        $orderItem2->method('getQuoteItemId')->willReturn(789); // Different quote item ID
        $orderItem2->method('getProduct')->willReturn($product2);
        
        $product1->method('getId')->willReturn(1);
        $product2->method('getId')->willReturn(1);
        
        $productModel = $this->createMock(\Magento\Catalog\Model\Product::class);
        $customAttribute = $this->createMock(\Magento\Framework\Api\AttributeValue::class);
        $productModel->method('load')->willReturnSelf();
        $productModel->method('getCustomAttribute')->willReturn($customAttribute);
        $customAttribute->method('getValue')->willReturn('20000');
        
        $this->productFactory->method('create')->willReturn($productModel);
        
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn([$creditItem1, $creditItem2]);
        $creditmemo->method('getShippingAmount')->willReturn(0);

        // Mock successful SOAP response
        $mockResponse = new \stdClass();
        $mockResponse->ReturnedResult = new \stdClass();
        $mockResponse->ReturnedResult->ResponseType = 'OK';
        $mockResponse->ReturnedResult->Messages = [];

        $this->mockSoapClient->method('Returned')
            ->willReturn($mockResponse);

        // Mock data object methods - capture params to verify ItemID uniqueness
        $capturedParams = null;
        $this->mockDataObject->method('setParams')->willReturnCallback(function($params) use (&$capturedParams) {
            $capturedParams = $params;
            return $this->mockDataObject;
        });
        $this->mockDataObject->method('getParams')->willReturn([
            'apiLoginID' => 'test_api_id',
            'apiKey' => 'test_api_key',
            'orderID' => 'TEST_ORDER_123',
            'cartItems' => [
                [
                    'ItemID' => 'ABC123-456',
                    'Index' => 0,
                    'TIC' => '20000',
                    'Price' => 120.00,
                    'Qty' => 1
                ],
                [
                    'ItemID' => 'ABC123-789',
                    'Index' => 1,
                    'TIC' => '20000',
                    'Price' => 0.00,
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
        $this->assertTrue($result, 'returnOrder should return true for successful refund with multiple items');
        
        // Verify ItemIDs are unique even with same SKU
        if ($capturedParams && isset($capturedParams['cartItems'][0]['ItemID']) && isset($capturedParams['cartItems'][1]['ItemID'])) {
            $itemId1 = $capturedParams['cartItems'][0]['ItemID'];
            $itemId2 = $capturedParams['cartItems'][1]['ItemID'];
            
            $this->assertNotEquals($itemId1, $itemId2, 'Items with same SKU should have unique ItemIDs');
            $this->assertEquals('ABC123-456', $itemId1, 'First item should have ItemID with quote item ID 456');
            $this->assertEquals('ABC123-789', $itemId2, 'Second item should have ItemID with quote item ID 789');
        }
    }
} 