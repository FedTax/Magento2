<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\Cache\ResultCache;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Event\GatewayEventDispatcher;
use Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestExemptionValidator;
use Taxcloud\Magento2\Model\Gateway\Rest\RestGateway;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponseMapper;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\Gateway\RetryPolicy;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * RestGateway orchestration: pre-flight gates, cache, events (REST names
 * only), fallback, benign-duplicate capture, the refund branches with the
 * tax-only exempt re-create, order details, address verification, and
 * entity-store scoping.
 */
#[AllowMockObjectsWithoutExpectations]
class RestGatewayTest extends TestCase
{
    private const STORE_ID = 3;

    /**
     * @var RestClient&\PHPUnit\Framework\MockObject\MockObject
     */
    private $restClient;

    /**
     * @var RestRequestBuilder&\PHPUnit\Framework\MockObject\MockObject
     */
    private $restRequestBuilder;

    /**
     * @var RequestBuilder&\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestBuilder;

    /**
     * @var RestExemptionValidator&\PHPUnit\Framework\MockObject\MockObject
     */
    private $exemptionValidator;

    /**
     * @var ResultCache&\PHPUnit\Framework\MockObject\MockObject
     */
    private $resultCache;

    /**
     * @var MagentoTaxFallback&\PHPUnit\Framework\MockObject\MockObject
     */
    private $fallback;

    /**
     * @var GatewayEventDispatcher&\PHPUnit\Framework\MockObject\MockObject
     */
    private $events;

    /**
     * @var TaxcloudConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var string[] Event names dispatched (before and after), in order
     */
    private $dispatchedEvents = [];

    /**
     * Optional per-test override for before-event payload mutation:
     * fn(string $name, array $params): array
     *
     * @var callable|null
     */
    private $beforeEventOverride;

    private function gateway(): RestGateway
    {
        $this->beforeEventOverride = null;
        $this->restClient = $this->createMock(RestClient::class);
        $this->restRequestBuilder = $this->createMock(RestRequestBuilder::class);
        $this->requestBuilder = $this->createMock(RequestBuilder::class);
        $this->exemptionValidator = $this->createMock(RestExemptionValidator::class);
        $this->resultCache = $this->createMock(ResultCache::class);
        $this->fallback = $this->createMock(MagentoTaxFallback::class);
        $this->config = $this->createMock(TaxcloudConfig::class);

        $this->dispatchedEvents = [];
        $this->events = $this->createMock(GatewayEventDispatcher::class);
        $this->events->method('dispatchBefore')->willReturnCallback(
            function ($name, array $params, array $context = []) {
                $this->dispatchedEvents[] = $name;
                if ($this->beforeEventOverride !== null) {
                    return ($this->beforeEventOverride)($name, $params);
                }
                return $params;
            }
        );
        $this->events->method('dispatchAfter')->willReturnCallback(
            function ($name, $result, array $context = []) {
                $this->dispatchedEvents[] = $name;
                return $result;
            }
        );

        return new RestGateway(
            $this->createMock(GatewayLogger::class),
            $this->config,
            $this->restClient,
            $this->restRequestBuilder,
            new RestResponseMapper(),
            $this->requestBuilder,
            $this->exemptionValidator,
            $this->resultCache,
            $this->fallback,
            $this->events,
            new RetryPolicy($this->createMock(LoggerInterface::class), 0)
        );
    }

    private const V1_ORIGIN = [
        'Address1' => '162 East Ave', 'Address2' => '', 'City' => 'Norwalk',
        'State' => 'CT', 'Zip5' => '06851', 'Zip4' => '',
    ];
    private const V1_DESTINATION = [
        'Address1' => '100 Main St', 'Address2' => '', 'City' => 'Bronx',
        'State' => 'NY', 'Zip5' => '10451', 'Zip4' => '',
    ];
    private const CART_PAYLOAD = ['items' => [['cartId' => '77', 'lineItems' => []]]];

    /**
     * @return array{0: array, 1: object, 2: object} [$itemsByType, $shippingAssignment, $quote]
     */
    private function lookupInputs(?string $certValue = null, array $addressOverrides = []): array
    {
        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $attribute = $this->createMock(\Magento\Framework\Api\AttributeInterface::class);
        $attribute->method('getValue')->willReturn($certValue);
        $customer->method('getCustomAttribute')
            ->with('taxcloud_cert')
            ->willReturn($certValue !== null ? $attribute : null);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getId')->willReturn(77);
        $quote->method('getCustomer')->willReturn($customer);

        $defaults = [
            'getPostcode' => '10451',
            'getStreet' => ['100 Main St'],
            'getCity' => 'Bronx',
            'getRegionId' => 43,
            'getCountryId' => 'US',
        ];
        $address = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCity', 'getCountryId', 'getPostcode', 'getRegionId', 'getStreet'])
            ->getMock();
        foreach ($addressOverrides + $defaults as $method => $value) {
            $address->method($method)->willReturn($value);
        }

        $shipping = $this->getMockBuilder(\Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);
        $shippingAssignment->method('getItems')->willReturn([]);

        return [[], $shippingAssignment, $quote];
    }

    private function stubLookupBuilders(array $lineItems = [['index' => 0]], array $indexed = [0 => 'code-a']): void
    {
        $this->requestBuilder->method('buildLookupDestination')->willReturn(self::V1_DESTINATION);
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->restRequestBuilder->method('buildCartLineItems')->willReturn([
            'lineItems' => $lineItems,
            'indexedItems' => $indexed,
        ]);
        $this->restRequestBuilder->method('buildCartPayload')->willReturn(self::CART_PAYLOAD);
    }

    private function cartResponse(array $lineItems): RestResponse
    {
        return new RestResponse(200, (string) json_encode(['items' => [['cartId' => '77', 'lineItems' => $lineItems]]]));
    }

    public function testLookupGatesShortCircuitWithoutApiCall()
    {
        foreach ([
            'no postcode' => ['getPostcode' => null],
            'invalid zip' => ['getPostcode' => 'ABCDE'],
            'non-US' => ['getCountryId' => 'CA'],
            'no region' => ['getRegionId' => 0],
            'no city' => ['getCity' => ''],
        ] as $label => $override) {
            $gateway = $this->gateway();
            $this->stubLookupBuilders();
            $this->restClient->expects($this->never())->method('request');

            [$itemsByType, $assignment, $quote] = $this->lookupInputs(null, $override);
            $result = $gateway->lookupTaxes($itemsByType, $assignment, $quote);

            $this->assertSame(
                [Api::ITEM_TYPE_PRODUCT => [], Api::ITEM_TYPE_SHIPPING => 0],
                $result,
                $label
            );
        }
    }

    public function testLookupWithNoLineItemsShortCircuits()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders([], []);
        $this->restClient->expects($this->never())->method('request');

        [$itemsByType, $assignment, $quote] = $this->lookupInputs();
        $result = $gateway->lookupTaxes($itemsByType, $assignment, $quote);

        $this->assertSame([Api::ITEM_TYPE_PRODUCT => [], Api::ITEM_TYPE_SHIPPING => 0], $result);
    }

    public function testLookupAppliesTaxCachesAndFiresRestEvents()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders(
            [['index' => 0], ['index' => 1, 'itemId' => 'shipping']],
            [0 => 'code-a']
        );
        $this->resultCache->method('getLookup')->willReturn(false);

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(
            function (...$args) use (&$requestArgs) {
                $requestArgs = $args;
                return $this->cartResponse([
                    ['index' => 0, 'itemId' => 'sku-1', 'tax' => ['amount' => 3.83]],
                    ['index' => 1, 'itemId' => 'shipping', 'tax' => ['amount' => 0.51]],
                ]);
            }
        );

        $saved = null;
        $this->resultCache->expects($this->once())->method('saveLookup')->willReturnCallback(
            static function ($params, $data) use (&$saved) {
                $saved = $data;
                return true;
            }
        );

        [$itemsByType, $assignment, $quote] = $this->lookupInputs();
        $result = $gateway->lookupTaxes($itemsByType, $assignment, $quote);

        $this->assertSame(['code-a' => 3.83], $result[Api::ITEM_TYPE_PRODUCT]);
        $this->assertSame(0.51, $result[Api::ITEM_TYPE_SHIPPING]);
        $this->assertSame($result, $saved);

        // The call went to the v3 carts endpoint with the quote's store.
        $this->assertSame(['POST', '/carts', self::CART_PAYLOAD, self::STORE_ID, true], $requestArgs);

        // REST event names only — the SOAP taxcloud_* events never fire here.
        $this->assertSame(['taxcloud_rest_lookup_before', 'taxcloud_rest_lookup_after'], $this->dispatchedEvents);
    }

    public function testLookupUsesCacheAfterBeforeEvent()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders();
        $cached = [Api::ITEM_TYPE_PRODUCT => ['code-a' => 9.99], Api::ITEM_TYPE_SHIPPING => 1.0];
        $this->resultCache->method('getLookup')->willReturn($cached);
        $this->restClient->expects($this->never())->method('request');

        [$itemsByType, $assignment, $quote] = $this->lookupInputs();
        $result = $gateway->lookupTaxes($itemsByType, $assignment, $quote);

        $this->assertSame($cached, $result);
        // The before event still fired (cache key uses post-observer payload).
        $this->assertSame(['taxcloud_rest_lookup_before'], $this->dispatchedEvents);
    }

    public function testLookupBeforeEventMutationReachesTheApi()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders();
        $this->resultCache->method('getLookup')->willReturn(false);

        $mutated = ['items' => [['cartId' => '77', 'lineItems' => [], 'destination' => ['line1' => 'verified']]]];
        $this->beforeEventOverride = static function ($name, array $params) use ($mutated) {
            return $name === 'taxcloud_rest_lookup_before' ? $mutated : $params;
        };

        $sentPayload = null;
        $this->restClient->method('request')->willReturnCallback(
            function ($method, $path, $payload) use (&$sentPayload) {
                $sentPayload = $payload;
                return $this->cartResponse([]);
            }
        );

        [$itemsByType, $assignment, $quote] = $this->lookupInputs();
        $gateway->lookupTaxes($itemsByType, $assignment, $quote);

        $this->assertSame($mutated, $sentPayload);
    }

    public function testLookupValidatesExemptionAndPassesCertificate()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders();
        $this->resultCache->method('getLookup')->willReturn(false);
        $this->restClient->method('request')->willReturn($this->cartResponse([]));

        $this->exemptionValidator->expects($this->once())
            ->method('validate')
            ->with('cert-9', '42', 'NY', self::STORE_ID)
            ->willReturn('cert-9');
        $this->restRequestBuilder->expects($this->once())
            ->method('buildCartPayload')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                self::V1_ORIGIN,
                self::V1_DESTINATION,
                'cert-9'
            )
            ->willReturn(self::CART_PAYLOAD);

        [$itemsByType, $assignment, $quote] = $this->lookupInputs('cert-9');
        $gateway->lookupTaxes($itemsByType, $assignment, $quote);
    }

    public function testLookupFailureFallsBackWhenEnabled()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders();
        $this->resultCache->method('getLookup')->willReturn(false);
        $this->restClient->method('request')->willReturn(new RestResponse(422, '{"title":"Unprocessable"}'));
        $this->config->method('isFallbackToMagentoEnabled')->willReturn(true);

        $fallbackResult = [Api::ITEM_TYPE_PRODUCT => ['code-a' => 1.23], Api::ITEM_TYPE_SHIPPING => 0];
        $this->fallback->expects($this->once())->method('calculate')->willReturn($fallbackResult);

        [$itemsByType, $assignment, $quote] = $this->lookupInputs();
        $this->assertSame($fallbackResult, $gateway->lookupTaxes($itemsByType, $assignment, $quote));
    }

    public function testLookupFailureReturnsZeroWhenFallbackDisabled()
    {
        $gateway = $this->gateway();
        $this->stubLookupBuilders();
        $this->resultCache->method('getLookup')->willReturn(false);
        $this->restClient->method('request')->willThrowException(new RestTransportException('Operation timed out'));
        $this->config->method('isFallbackToMagentoEnabled')->willReturn(false);
        $this->fallback->expects($this->never())->method('calculate');

        [$itemsByType, $assignment, $quote] = $this->lookupInputs();
        $this->assertSame(
            [Api::ITEM_TYPE_PRODUCT => [], Api::ITEM_TYPE_SHIPPING => 0],
            $gateway->lookupTaxes($itemsByType, $assignment, $quote)
        );
    }

    private function orderMock(int $storeId = self::STORE_ID): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getAllVisibleItems')->willReturn([]);

        return $order;
    }

    private const ORDER_PAYLOAD = ['orderId' => '100000042', 'lineItems' => [['index' => 0]]];

    public function testAuthorizeCaptureSucceedsAndFiresCaptureEvents()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(self::ORDER_PAYLOAD);

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(function (...$args) use (&$requestArgs) {
            $requestArgs = $args;
            return new RestResponse(201, '{"orderId":"100000042"}');
        });

        $this->assertTrue($gateway->authorizeCapture($this->orderMock()));
        $this->assertSame(['POST', '/orders', self::ORDER_PAYLOAD, self::STORE_ID, true], $requestArgs);
        $this->assertSame(['taxcloud_rest_capture_before', 'taxcloud_rest_capture_after'], $this->dispatchedEvents);
    }

    public function testAuthorizeCaptureUsesTheEntityStoreNotTheAmbientOne()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(self::ORDER_PAYLOAD);

        $storeArg = null;
        $this->restClient->method('request')->willReturnCallback(
            function ($method, $path, $body, $store) use (&$storeArg) {
                $storeArg = $store;
                return new RestResponse(201, '');
            }
        );

        $gateway->authorizeCapture($this->orderMock(8));
        $this->assertSame(8, $storeArg, 'the order\'s store must scope the call');
    }

    public function testAuthorizeCaptureTreatsDuplicateAsSuccess()
    {
        // Exact wording observed live (2026-08-06): re-POSTing an existing
        // orderId answers 400 with this ErrorModel detail.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(self::ORDER_PAYLOAD);
        $this->restClient->method('request')->willReturn(new RestResponse(
            400,
            '{"title":"Bad Request","status":400,"detail":"completed order already exists for this store"}'
        ));

        $this->assertTrue($gateway->authorizeCapture($this->orderMock()));

        // Tolerant on status for equivalent wordings (409/422 both plausible).
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(self::ORDER_PAYLOAD);
        $this->restClient->method('request')->willReturn(new RestResponse(
            409,
            '{"title":"Conflict","detail":"An order with this orderId already exists"}'
        ));

        $this->assertTrue($gateway->authorizeCapture($this->orderMock()));
    }

    public function testAuthorizeCaptureFailsClosed()
    {
        // Unrecognized failure: a missed capture can be re-tried, a double
        // filing cannot be undone — so anything unrecognized is a failure.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(self::ORDER_PAYLOAD);
        $this->restClient->method('request')->willReturn(new RestResponse(422, '{"detail":"zip is invalid"}'));
        $this->assertFalse($gateway->authorizeCapture($this->orderMock()));

        // Transport failure.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(self::ORDER_PAYLOAD);
        $this->restClient->method('request')->willThrowException(new RestTransportException('Operation timed out'));
        $this->assertFalse($gateway->authorizeCapture($this->orderMock()));

        // Unbuildable payload (no valid addresses).
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(null);
        $this->restClient->expects($this->never())->method('request');
        $this->assertFalse($gateway->authorizeCapture($this->orderMock()));
    }

    private function creditmemoMock(Order $order): Creditmemo
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getIncrementId')->willReturn('CM-1');
        $creditmemo->method('getAllItems')->willReturn([]);

        return $creditmemo;
    }

    public function testReturnOrderSkipCaseSucceedsWithoutApiCall()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildRefundItems')->willReturn(
            ['items' => [], 'wasTaxOnlyRefund' => false, 'skip' => true, 'fullRefund' => false]
        );
        $this->restClient->expects($this->never())->method('request');

        $this->assertTrue($gateway->returnOrder($this->creditmemoMock($this->orderMock())));
        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testReturnOrderSubmitsQuantityRefundAndFiresRefundEvents()
    {
        $gateway = $this->gateway();
        $items = [['itemId' => 'sku-1', 'quantity' => 2.0], ['itemId' => 'shipping', 'quantity' => 0.4]];
        $this->restRequestBuilder->method('buildRefundItems')->willReturn(
            ['items' => $items, 'wasTaxOnlyRefund' => false, 'skip' => false, 'fullRefund' => false]
        );

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(function (...$args) use (&$requestArgs) {
            $requestArgs = $args;
            return new RestResponse(201, '[]');
        });

        $this->assertTrue($gateway->returnOrder($this->creditmemoMock($this->orderMock())));
        $this->assertSame(
            ['POST', '/orders/refunds/100000042', ['items' => $items], self::STORE_ID, true],
            $requestArgs
        );
        $this->assertSame(['taxcloud_rest_refund_before', 'taxcloud_rest_refund_after'], $this->dispatchedEvents);
    }

    public function testReturnOrderTaxOnlyRefundRecreatesOrderAsExempt()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildRefundItems')->willReturn(
            ['items' => [], 'wasTaxOnlyRefund' => true, 'skip' => false, 'fullRefund' => true]
        );
        $exemptPayload = ['orderId' => '100000042-exempt', 'exemption' => ['isExempt' => true]];
        $this->restRequestBuilder->expects($this->once())
            ->method('buildOrderPayload')
            ->with($this->anything(), '100000042-exempt', true)
            ->willReturn($exemptPayload);

        $calls = [];
        $this->restClient->method('request')->willReturnCallback(
            function ($method, $path, $payload) use (&$calls) {
                $calls[] = [$path, $payload];
                return new RestResponse(201, '');
            }
        );

        $this->assertTrue($gateway->returnOrder($this->creditmemoMock($this->orderMock())));
        $this->assertSame(
            [
                ['/orders/refunds/100000042', ['items' => []]], // full refund first
                ['/orders', $exemptPayload],                    // then exempt re-create
            ],
            $calls
        );
    }

    public function testReturnOrderExemptRecreateFailureStillReportsSuccess()
    {
        // v1 parity: the return succeeded; a failed re-create is a warning.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildRefundItems')->willReturn(
            ['items' => [], 'wasTaxOnlyRefund' => true, 'skip' => false, 'fullRefund' => true]
        );
        $this->restRequestBuilder->method('buildOrderPayload')->willReturn(['orderId' => 'x-exempt']);
        $this->restClient->method('request')->willReturnCallback(
            static function ($method, $path) {
                return $path === '/orders'
                    ? new RestResponse(422, '{"detail":"nope"}')
                    : new RestResponse(201, '');
            }
        );

        $this->assertTrue($gateway->returnOrder($this->creditmemoMock($this->orderMock())));
    }

    public function testReturnOrderFailureReportsFalse()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildRefundItems')->willReturn(
            ['items' => [['itemId' => 's', 'quantity' => 1.0]], 'wasTaxOnlyRefund' => false, 'skip' => false, 'fullRefund' => false]
        );
        $this->restClient->method('request')->willReturn(
            new RestResponse(404, '{"title":"Not Found"}')
        );

        $this->assertFalse($gateway->returnOrder($this->creditmemoMock($this->orderMock())));
    }

    public function testReturnOrderCancellationSubmitsFullRefund()
    {
        $gateway = $this->gateway();

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(function (...$args) use (&$requestArgs) {
            $requestArgs = $args;
            return new RestResponse(201, '[]');
        });

        $this->assertTrue($gateway->returnOrderCancellation($this->orderMock()));
        $this->assertSame(
            ['POST', '/orders/refunds/100000042', ['items' => []], self::STORE_ID, true],
            $requestArgs
        );
        $this->assertSame(['taxcloud_rest_refund_before', 'taxcloud_rest_refund_after'], $this->dispatchedEvents);
    }

    public function testGetOrderDetailsMapsTheOrderResource()
    {
        $gateway = $this->gateway();

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(function (...$args) use (&$requestArgs) {
            $requestArgs = $args;
            return new RestResponse(200, (string) json_encode([
                'orderId' => '100000042',
                'completedDate' => '2026-08-02T09:00:00Z',
            ]));
        });

        $details = $gateway->getOrderDetails($this->orderMock());

        $this->assertSame('2026-08-02T09:00:00Z', $details['CapturedDate']);
        $this->assertSame(
            ['GET', '/orders/100000042?expand=refunds', null, self::STORE_ID, true],
            $requestArgs
        );
    }

    public function testGetOrderDetailsReturnsNullOnNotFoundAndErrors()
    {
        // 404 — unknown order, callers treat as never captured.
        $gateway = $this->gateway();
        $this->restClient->method('request')->willReturn(new RestResponse(404, ''));
        $this->assertNull($gateway->getOrderDetails($this->orderMock()));

        // Other error.
        $gateway = $this->gateway();
        $this->restClient->method('request')->willReturn(new RestResponse(403, '{"title":"Forbidden"}'));
        $this->assertNull($gateway->getOrderDetails($this->orderMock()));

        // Transport failure.
        $gateway = $this->gateway();
        $this->restClient->method('request')->willThrowException(new RestTransportException('boom'));
        $this->assertNull($gateway->getOrderDetails($this->orderMock()));
    }

    private const V1_ADDRESS_INPUT = [
        'Address1' => '100 main st', 'Address2' => '', 'City' => 'bronx',
        'State' => 'NY', 'Zip5' => '10451', 'Zip4' => '',
    ];
    private const V3_VERIFY_PAYLOAD = ['line1' => '100 main st', 'city' => 'bronx', 'state' => 'NY', 'zip' => '10451'];

    public function testVerifyAddressReturnsContractShapeAndCaches()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildVerifyAddressPayload')->willReturn(self::V3_VERIFY_PAYLOAD);
        $this->resultCache->method('getAddress')->willReturn(false);

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(function (...$args) use (&$requestArgs) {
            $requestArgs = $args;
            return new RestResponse(200, (string) json_encode([
                'line1' => '100 Main St', 'city' => 'Bronx', 'state' => 'NY', 'zip' => '10451-1234',
            ]));
        });
        $this->resultCache->expects($this->once())->method('saveAddress');

        $result = $gateway->verifyAddress(self::V1_ADDRESS_INPUT, self::STORE_ID);

        $this->assertSame(
            [
                'Address1' => '100 Main St', 'Address2' => '', 'City' => 'Bronx',
                'State' => 'NY', 'Zip5' => '10451', 'Zip4' => '1234',
            ],
            $result
        );
        // Account-level endpoint: not connection-scoped.
        $this->assertSame(
            ['POST', '/tax/verify-address', self::V3_VERIFY_PAYLOAD, self::STORE_ID, false],
            $requestArgs
        );
        $this->assertSame(
            ['taxcloud_rest_verify_address_before', 'taxcloud_rest_verify_address_after'],
            $this->dispatchedEvents
        );
    }

    public function testVerifyAddressUsesCache()
    {
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildVerifyAddressPayload')->willReturn(self::V3_VERIFY_PAYLOAD);
        $cached = ['Address1' => '100 Main St', 'City' => 'Bronx'];
        $this->resultCache->method('getAddress')->willReturn($cached);
        $this->restClient->expects($this->never())->method('request');

        $this->assertSame($cached, $gateway->verifyAddress(self::V1_ADDRESS_INPUT, self::STORE_ID));
    }

    public function testVerifyAddressFailuresReturnFalse()
    {
        // Non-2xx.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildVerifyAddressPayload')->willReturn(self::V3_VERIFY_PAYLOAD);
        $this->resultCache->method('getAddress')->willReturn(false);
        $this->restClient->method('request')->willReturn(new RestResponse(422, '{"detail":"unverifiable"}'));
        $this->assertFalse($gateway->verifyAddress(self::V1_ADDRESS_INPUT, self::STORE_ID));

        // Unusable body.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildVerifyAddressPayload')->willReturn(self::V3_VERIFY_PAYLOAD);
        $this->resultCache->method('getAddress')->willReturn(false);
        $this->restClient->method('request')->willReturn(new RestResponse(200, '{}'));
        $this->assertFalse($gateway->verifyAddress(self::V1_ADDRESS_INPUT, self::STORE_ID));

        // Transport failure.
        $gateway = $this->gateway();
        $this->restRequestBuilder->method('buildVerifyAddressPayload')->willReturn(self::V3_VERIFY_PAYLOAD);
        $this->resultCache->method('getAddress')->willReturn(false);
        $this->restClient->method('request')->willThrowException(new RestTransportException('boom'));
        $this->assertFalse($gateway->verifyAddress(self::V1_ADDRESS_INPUT, self::STORE_ID));
    }

    public function testGetValidatedCertificateIdDelegatesToTheRestValidator()
    {
        $gateway = $this->gateway();
        $this->exemptionValidator->expects($this->once())
            ->method('validate')
            ->with('cert-1', 'cust-1', 'NY', self::STORE_ID)
            ->willReturn('cert-1');

        $this->assertSame('cert-1', $gateway->getValidatedCertificateID('cert-1', 'cust-1', 'NY', self::STORE_ID));
    }
}
