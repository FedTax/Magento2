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

namespace Taxcloud\Magento2\Model\Gateway\Rest;

use Taxcloud\Magento2\Api\GatewayInterface;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\Cache\ResultCache;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Event\GatewayEventDispatcher;
use Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\RetryPolicy;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Model\PostalCodeParser;
use Throwable;

/**
 * v3 REST implementation of the TaxCloud gateway contract.
 *
 * Parallel to the SOAP gateway ({@see \Taxcloud\Magento2\Model\Api}): each
 * operation keeps the same orchestration the SOAP path established —
 * pre-flight gates, exemption resolution, before/after events, result cache,
 * Magento-rates fallback, benign-duplicate tolerance, the tax-only-refund
 * exempt re-create — while the transport speaks v3 (carts / orders / refunds
 * resources, auth in headers). Events fire under `taxcloud_rest_*` names with
 * v3-shaped payloads; the SOAP `taxcloud_*` events never fire here.
 *
 * Everything resolves against the store of the entity in hand (quote, order,
 * credit memo, or explicit $store argument), never the ambient store.
 */
class RestGateway implements GatewayInterface
{
    /**
     * @var GatewayLogger
     */
    private $tclogger;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var RestRequestBuilder
     */
    private $restRequestBuilder;

    /**
     * @var RestResponseMapper
     */
    private $restResponseMapper;

    /**
     * @var RequestBuilder
     */
    private $requestBuilder;

    /**
     * @var \Taxcloud\Magento2\Model\Certificate\RestCertificateGateway
     */
    private $certificateGateway;

    /**
     * @var \Taxcloud\Magento2\Model\Certificate\CertificateResolver
     */
    private $certificateResolver;

    /**
     * @var ResultCache
     */
    private $resultCache;

    /**
     * @var MagentoTaxFallback
     */
    private $magentoTaxFallback;

    /**
     * @var GatewayEventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var RetryPolicy
     */
    private $retryPolicy;

    /**
     * @param GatewayLogger $logger
     * @param TaxcloudConfig $config
     * @param RestClient $restClient
     * @param RestRequestBuilder $restRequestBuilder
     * @param RestResponseMapper $restResponseMapper
     * @param RequestBuilder $requestBuilder
     * @param \Taxcloud\Magento2\Model\Certificate\RestCertificateGateway $certificateGateway
     * @param ResultCache $resultCache
     * @param MagentoTaxFallback $magentoTaxFallback
     * @param GatewayEventDispatcher $eventDispatcher
     * @param RetryPolicy $retryPolicy
     */
    public function __construct(
        GatewayLogger $logger,
        TaxcloudConfig $config,
        RestClient $restClient,
        RestRequestBuilder $restRequestBuilder,
        RestResponseMapper $restResponseMapper,
        RequestBuilder $requestBuilder,
        \Taxcloud\Magento2\Model\Certificate\RestCertificateGateway $certificateGateway,
        \Taxcloud\Magento2\Model\Certificate\CertificateResolver $certificateResolver,
        ResultCache $resultCache,
        MagentoTaxFallback $magentoTaxFallback,
        GatewayEventDispatcher $eventDispatcher,
        RetryPolicy $retryPolicy
    ) {
        $this->tclogger = $logger;
        $this->config = $config;
        $this->restClient = $restClient;
        $this->restRequestBuilder = $restRequestBuilder;
        $this->restResponseMapper = $restResponseMapper;
        $this->requestBuilder = $requestBuilder;
        $this->certificateGateway = $certificateGateway;
        $this->certificateResolver = $certificateResolver;
        $this->resultCache = $resultCache;
        $this->magentoTaxFallback = $magentoTaxFallback;
        $this->eventDispatcher = $eventDispatcher;
        $this->retryPolicy = $retryPolicy;
    }

    /**
     * @inheritDoc
     */
    public function listCertificates($customerIdentity, $store = null)
    {
        return $this->certificateGateway->listCertificates($customerIdentity, $store);
    }

    /**
     * @inheritDoc
     */
    public function createCertificate($customerIdentity, array $data, $store = null)
    {
        return $this->certificateGateway->createCertificate($customerIdentity, $data, $store);
    }

    /**
     * @inheritDoc
     */
    public function deleteCertificate($certificateId, $customerIdentity, $store = null)
    {
        $this->certificateGateway->deleteCertificate($certificateId, $customerIdentity, $store);
    }

    /**
     * @inheritDoc
     */
    public function lookupTaxes($itemsByType, $shippingAssignment, $quote)
    {
        // All config (credentials, TICs, cache, fallback, logging) resolves
        // against the quote's store, not the ambient request store.
        $storeId = $quote->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling lookupTaxes (v3 REST)');
        $this->tclogger->debug(
            'lookupTaxes context: store=' . $storeId . ', quote=' . ($quote->getId() ?: '(new)')
        );

        $result = [Api::ITEM_TYPE_PRODUCT => [], Api::ITEM_TYPE_SHIPPING => 0];

        /** @var \Magento\Customer\Api\Data\CustomerInterface|null $customer */
        $customer = $quote->getCustomer();

        /** @var \Magento\Quote\Model\Quote\Address|null $address */
        $address = $shippingAssignment->getShipping()->getAddress();
        if (!$address || !$address->getPostcode()) {
            $this->tclogger->info('No address, returning 0');
            return $result;
        }
        $parsedZip = PostalCodeParser::parse($address->getPostcode());
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->tclogger->warning('Invalid ZIP code format: ' . $address->getPostcode());
            return $result;
        }

        if ($address->getCountryId() !== 'US') {
            $this->tclogger->info('Not US, returning 0');
            return $result;
        }
        if ($address->getRegionId() == 0) {
            $this->tclogger->info('No region, returning 0');
            return $result;
        }
        if (!$address->getCity()) {
            $this->tclogger->info('No city, returning 0');
            return $result;
        }

        $destination = $this->requestBuilder->buildLookupDestination($address, $parsedZip);

        $keyedAddressItems = [];
        /** @var \Magento\Quote\Model\Quote\Item\AbstractItem $item */
        foreach ($shippingAssignment->getItems() as $item) {
            // Skip composite child lines with no tax calculation id (null array
            // key is a PHP 8 deprecation, fatal in developer mode).
            $taxCalculationItemId = $item->getData('tax_calculation_item_id');
            if ($taxCalculationItemId === null) {
                continue;
            }
            $keyedAddressItems[$taxCalculationItemId] = $item;
        }

        $built = $this->restRequestBuilder->buildCartLineItems($itemsByType, $keyedAddressItems, $address, $storeId);
        $lineItems = $built['lineItems'];
        $indexedItems = $built['indexedItems'];

        if (count($lineItems) === 0) {
            $this->tclogger->info('No cart items, returning 0');
            return $result;
        }

        // One resolver for both transports: eligibility, precedence and the
        // ownership check that TaxCloud does not perform live here, not twice
        // over in two lookup paths. `taxcloud_cert` is the explicitly attached
        // certificate — untrusted like any other inbound identifier, and
        // honoured only if it turns out to be this customer's.
        $resolvedCertificate = $this->certificateResolver->resolve(
            $customer,
            $destination['State'],
            null,
            $storeId
        );
        $certificateID = $resolvedCertificate ? $resolvedCertificate->getCertificateId() : null;

        $origin = $this->requestBuilder->buildOrigin($storeId);
        if ($origin === null) {
            $this->tclogger->error('Invalid origin address configuration - cannot proceed with tax calculation');
            return $result;
        }

        $payload = $this->restRequestBuilder->buildCartPayload(
            $customer,
            $quote,
            $lineItems,
            $origin,
            $destination,
            $certificateID
        );

        // Call before event (observers may modify $payload, e.g. address verification)
        $payload = $this->eventDispatcher->dispatchBefore('taxcloud_rest_lookup_before', $payload, [
            'customer' => $customer,
            'address' => $address,
            'quote' => $quote,
            'itemsByType' => $itemsByType,
            'shippingAssignment' => $shippingAssignment,
        ]);

        // check cache (use post-observer payload so cache key matches what we send to TaxCloud)
        $cacheResult = $this->resultCache->getLookup($payload, $storeId);
        if ($cacheResult) {
            $this->tclogger->info('Using Cache');
            return $cacheResult;
        }

        $this->tclogger->info('Calling lookupTaxes LIVE API (v3 carts)');
        $this->tclogger->debug('lookupTaxes PAYLOAD:');
        $this->tclogger->debug((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $response = $this->retryPolicy->executeForResponse(function () use ($payload, $storeId) {
                return $this->restClient->request('POST', '/carts', $payload, $storeId);
            });
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during lookupTaxes: ' . $e->getMessage());
            return $this->lookupFallback($itemsByType, $shippingAssignment, $quote, $storeId, $result);
        }

        $this->logResponse('lookupTaxes', $response, $storeId);

        if (!$response->isSuccess()) {
            $this->tclogger->error('Error encountered during lookupTaxes: ' . $response->errorDetail());
            return $this->lookupFallback($itemsByType, $shippingAssignment, $quote, $storeId, $result);
        }

        $cart = $this->restResponseMapper->extractCart($response->getBody());

        // Call after event
        $cart = $this->eventDispatcher->dispatchAfter('taxcloud_rest_lookup_after', $cart, [
            'customer' => $customer,
            'address' => $address,
            'quote' => $quote,
            'itemsByType' => $itemsByType,
            'shippingAssignment' => $shippingAssignment,
        ]);

        if (!is_array($cart) || empty($cart['lineItems']) || !is_array($cart['lineItems'])) {
            $this->tclogger->warning('v3 cart response has no line items, skipping tax calculation');
            return $result;
        }

        $this->restResponseMapper->applyCartTax($cart['lineItems'], $indexedItems, $result);

        $this->tclogger->info(
            'Caching lookupTaxes result for ' . $this->resultCache->getLookupLifetime($storeId)
            . 's (capped at the store\'s next local midnight)'
        );
        $this->resultCache->saveLookup($payload, $result, $storeId);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function authorizeCapture($order)
    {
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling authorizeCapture (v3 REST) for order ' . $order->getIncrementId());

        $payload = $this->restRequestBuilder->buildOrderPayload($order);
        if ($payload === null) {
            return false;
        }

        return $this->submitOrder($payload, $order, 'authorizeCapture');
    }

    /**
     * @inheritDoc
     */
    public function returnOrder($creditmemo)
    {
        $order = $creditmemo->getOrder();
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling returnOrder (v3 REST) for creditmemo ' . $creditmemo->getIncrementId());

        $built = $this->restRequestBuilder->buildRefundItems($creditmemo);
        if ($built['skip']) {
            return true;
        }

        $submitted = $this->submitRefund(
            $order,
            $built['items'],
            'returnOrder',
            [
                'order' => $order,
                'items' => $creditmemo->getAllItems(),
                'creditmemo' => $creditmemo,
            ]
        );
        if (!$submitted) {
            return false;
        }

        // Re-create the order as exempt in TaxCloud for nexus tracking.
        if ($built['wasTaxOnlyRefund']) {
            $exemptPayload = $this->restRequestBuilder->buildOrderPayload(
                $order,
                $order->getIncrementId() . '-exempt',
                true
            );
            if ($exemptPayload === null
                || !$this->submitOrder($exemptPayload, $order, 'returnOrder exempt re-create')) {
                $this->tclogger->warning('returnOrder: re-create as exempt capture failed; return was successful');
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function returnOrderCancellation($order)
    {
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info(
            'Calling returnOrderCancellation (v3 REST) for order ' . $order->getIncrementId()
        );

        // Empty items = full-order refund in v3, which also keeps
        // TaxCloud-owned fee lines (e.g. the CO retail delivery fee) included.
        return $this->submitRefund(
            $order,
            [],
            'returnOrderCancellation',
            [
                'order' => $order,
                'items' => $order->getAllVisibleItems(),
                'creditmemo' => null,
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function getOrderDetails($order)
    {
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling getOrderDetails (v3 REST) for order ' . $order->getIncrementId());

        try {
            $response = $this->retryPolicy->executeForResponse(function () use ($order, $storeId) {
                return $this->restClient->request(
                    'GET',
                    '/orders/' . rawurlencode((string) $order->getIncrementId()) . '?expand=refunds',
                    null,
                    $storeId
                );
            });
        } catch (Throwable $e) {
            $this->tclogger->error('getOrderDetails failed: ' . $e->getMessage());
            return null;
        }

        $this->logResponse('getOrderDetails', $response, $storeId);

        if ($response->isNotFound()) {
            $this->tclogger->info(
                'getOrderDetails: order ' . $order->getIncrementId() . ' not found in TaxCloud (v3)'
            );
            return null;
        }

        if (!$response->isSuccess()) {
            // Report what TaxCloud actually said. A caller that cannot confirm
            // the capture skips the refund call entirely, so without the detail
            // a silently unreversed order looks identical to one that was never
            // captured in the first place.
            $this->tclogger->error(
                'getOrderDetails returned non-OK for order ' . $order->getIncrementId() . ': '
                . $response->errorDetail()
            );
            return null;
        }

        return $this->restResponseMapper->mapOrderDetails($response->getBody());
    }

    /**
     * @inheritDoc
     */
    public function verifyAddress($address, $store = null)
    {
        $this->tclogger->setStore($store);

        $this->tclogger->info('Calling verifyAddress (v3 REST)');

        $payload = $this->restRequestBuilder->buildVerifyAddressPayload($address);

        // check cache
        $cacheResult = $this->resultCache->getAddress($payload, $store);
        if ($cacheResult) {
            $this->tclogger->info('Using Cache');
            return $cacheResult;
        }

        // Call before event
        $payload = $this->eventDispatcher->dispatchBefore('taxcloud_rest_verify_address_before', $payload);

        $this->tclogger->info('Calling verifyAddress LIVE API (v3)');
        $this->tclogger->debug('verifyAddress PAYLOAD:');
        $this->tclogger->debug((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $response = $this->retryPolicy->executeForResponse(function () use ($payload, $store) {
                return $this->restClient->request('POST', '/tax/verify-address', $payload, $store, false);
            });
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during verifyAddress: ' . $e->getMessage());
            return false;
        }

        $this->logResponse('verifyAddress', $response, $store);

        if (!$response->isSuccess()) {
            $this->tclogger->warning('Error encountered during verifyAddress: ' . $response->errorDetail());
            return false;
        }

        $verified = $this->restResponseMapper->mapVerifiedAddress($response->getBody());

        // Call after event
        $verified = $this->eventDispatcher->dispatchAfter('taxcloud_rest_verify_address_after', $verified);

        if ($verified === false || !is_array($verified)) {
            $this->tclogger->warning('verifyAddress: v3 response missing address fields');
            return false;
        }

        $this->tclogger->info('Caching verifyAddress result for ' . $this->config->getCacheLifetime($store));
        $this->resultCache->saveAddress($payload, $verified, $store);

        return $verified;
    }

    /**
     * Resolve a failed lookup: Magento's native tax rates when the store has
     * fallback enabled, the zero result otherwise (SOAP parity).
     *
     * @param array $itemsByType
     * @param \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment
     * @param \Magento\Quote\Model\Quote $quote
     * @param int|string|null $storeId
     * @param array $zeroResult
     * @return array
     */
    private function lookupFallback($itemsByType, $shippingAssignment, $quote, $storeId, array $zeroResult)
    {
        if ($this->config->isFallbackToMagentoEnabled($storeId)) {
            $this->tclogger->warning('TaxCloud lookup failed, falling back to Magento tax rates');
            return $this->magentoTaxFallback->calculate($itemsByType, $shippingAssignment, $quote);
        }

        return $zeroResult;
    }

    /**
     * Submit a v3 order (capture or exempt re-create) with capture events and
     * benign-duplicate tolerance.
     *
     * @param array $payload
     * @param \Magento\Sales\Model\Order $order
     * @param string $operation Label for log lines
     * @return bool
     */
    private function submitOrder(array $payload, $order, $operation)
    {
        $storeId = $order->getStoreId();

        // Call before event
        $payload = $this->eventDispatcher->dispatchBefore('taxcloud_rest_capture_before', $payload, [
            'order' => $order,
        ]);

        $this->tclogger->debug($operation . ' PAYLOAD:');
        $this->tclogger->debug((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            // Order creation is not idempotent in a way we can prove — only a
            // failure that never reached TaxCloud is retried, and a retryable
            // HTTP status is never retried (the request was processed enough
            // to possibly have been recorded).
            $response = $this->retryPolicy->executeForResponse(
                function () use ($payload, $storeId) {
                    return $this->restClient->request('POST', '/orders', $payload, $storeId);
                },
                1,
                [$this->retryPolicy, 'isRetryableForNonIdempotent'],
                false
            );
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during ' . $operation . ': ' . $e->getMessage());
            return false;
        }

        $this->logResponse($operation, $response, $storeId);

        // Call after event
        $this->eventDispatcher->dispatchAfter(
            'taxcloud_rest_capture_after',
            $response->getBody() ?? [],
            ['order' => $order]
        );

        if ($response->isSuccess()) {
            return true;
        }

        if ($this->isDuplicateOrder($response)) {
            // Duplicate means the previous call was good. Therefore, consider this to be good
            $this->tclogger->warning('Warning encountered during ' . $operation . ': Duplicate transaction');
            return true;
        }

        $this->tclogger->error('Error encountered during ' . $operation . ': ' . $response->errorDetail());
        return false;
    }

    /**
     * Submit a v3 refund with refund events; empty $items refunds the whole
     * order.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $items
     * @param string $operation Label for log lines
     * @param array $eventContext
     * @return bool
     */
    private function submitRefund($order, array $items, $operation, array $eventContext)
    {
        $storeId = $order->getStoreId();

        $payload = ['items' => $items];

        // Call before event
        $payload = $this->eventDispatcher->dispatchBefore('taxcloud_rest_refund_before', $payload, $eventContext);
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            $payload['items'] = [];
        }

        $this->tclogger->debug($operation . ' PAYLOAD:');
        $this->tclogger->debug((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            // Refunds are not idempotent — only retry a failure that never
            // reached TaxCloud, or the refund gets booked twice.
            $response = $this->retryPolicy->executeForResponse(
                function () use ($order, $payload, $storeId) {
                    return $this->restClient->request(
                        'POST',
                        '/orders/refunds/' . rawurlencode((string) $order->getIncrementId()),
                        $payload,
                        $storeId
                    );
                },
                1,
                [$this->retryPolicy, 'isRetryableForNonIdempotent'],
                false
            );
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during ' . $operation . ': ' . $e->getMessage());
            $this->tclogger->debug('Payload that failed: ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
            return false;
        }

        $this->logResponse($operation, $response, $storeId);

        // Call after event
        $this->eventDispatcher->dispatchAfter(
            'taxcloud_rest_refund_after',
            $response->getBody() ?? [],
            $eventContext
        );

        if (!$response->isSuccess()) {
            $detail = $response->errorDetail();
            if ($response->isNotFound()) {
                // Cross-transport case: the order was filed via SOAP v1, so v3
                // has nothing to refund against. Surface it clearly.
                $detail .= ' (order not found in v3 — was it captured over SOAP? '
                    . 'Switch the store back to SOAP to refund it)';
            }
            $this->tclogger->error('Error encountered during ' . $operation . ': ' . $detail);
            return false;
        }

        return true;
    }

    /**
     * Whether a failed order submission means the order already exists in
     * TaxCloud (benign duplicate, v1 parity: "already marked as authorized" was
     * treated as success).
     *
     * Deliberately tolerant on status (409 or 422 both plausible) but strict
     * on wording, and fail-safe: an unrecognized failure stays a failure — a
     * missed capture can be retried, a double-filed order cannot be silently
     * undone.
     *
     * @param RestResponse $response
     * @return bool
     */
    private function isDuplicateOrder(RestResponse $response)
    {
        if ($response->getStatus() !== 409 && $response->getStatus() !== 422 && $response->getStatus() !== 400) {
            return false;
        }

        return (bool) preg_match('/already exist|duplicate/i', $response->errorDetail());
    }

    /**
     * Log a v3 response at debug level; the raw body only under Advanced
     * logging (parity with the SOAP wire-trace logging, which is also
     * Advanced-gated). v3 bodies carry no credentials — auth is in headers.
     *
     * @param string $operation
     * @param RestResponse $response
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    private function logResponse($operation, RestResponse $response, $store)
    {
        $this->tclogger->debug($operation . ' RESPONSE: HTTP ' . $response->getStatus());
        if ($this->config->isAdvancedLoggingEnabled($store)) {
            $this->tclogger->debug($operation . ' response body: ' . $response->getRawBody());
        }
    }
}
