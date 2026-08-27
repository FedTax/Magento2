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

namespace Taxcloud\Magento2\Model;

use Taxcloud\Magento2\Api\GatewayInterface;
use Taxcloud\Magento2\Model\Cache\ResultCache;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Event\GatewayEventDispatcher;
use Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\ResponseMapper;
use Taxcloud\Magento2\Model\Gateway\RetryPolicy;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Model\Logging\LogRedactor;
use Taxcloud\Magento2\Model\PostalCodeParser;
use Throwable;

/**
 * Tax calculation gateway — thin orchestrator over focused collaborators.
 *
 * SOAP implementation of the TaxCloud gateway contract. Consumers depend on the
 * finer-grained interfaces under {@see \Taxcloud\Magento2\Api}; this concrete
 * class is what di.xml binds them to. Each responsibility (transport, request
 * building, response mapping, caching, exemption validation, native-tax
 * fallback, event dispatch, retry) lives in its own collaborator; this class
 * wires them together and owns the per-operation flow.
 */
class Api implements GatewayInterface
{

    /**#@+
     * Constants defined for type of items
     */
    public const ITEM_TYPE_SHIPPING = 'shipping';
    public const ITEM_TYPE_PRODUCT = 'product';
    public const ITEM_CODE_SHIPPING = 'shipping';
    /**#@+
     * Constants for array keys
     */
    public const KEY_ITEM = 'item';
    public const KEY_BASE_ITEM = 'base_item';

    /**
     * Default SOAP connection/read timeout in seconds.
     */
    public const DEFAULT_SOAP_TIMEOUT = 10;

    /**
     * Backoff between SOAP retry attempts, in microseconds.
     */
    public const SOAP_RETRY_BACKOFF_US = 250000;

    /**
     * TaxCloud logger (gated by the logging setting, store-scoped per
     * operation via setStore()).
     *
     * @var \Taxcloud\Magento2\Model\Logging\GatewayLogger
     */
    protected $tclogger;

    /**
     * Store-scoped configuration reader.
     *
     * @var \Taxcloud\Magento2\Model\Config\TaxcloudConfig
     */
    private $config;

    /**
     * SOAP transport (client provisioning).
     *
     * @var \Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway
     */
    private $soapGateway;

    /**
     * Request payload construction.
     *
     * @var \Taxcloud\Magento2\Model\Gateway\RequestBuilder
     */
    private $requestBuilder;

    /**
     * Wire-format normalization and extraction.
     *
     * @var \Taxcloud\Magento2\Model\Gateway\ResponseMapper
     */
    private $responseMapper;

    /**
     * Serialize-and-store response cache.
     *
     * @var \Taxcloud\Magento2\Model\Cache\ResultCache
     */
    private $resultCache;

    /**
     * Certificate reads and writes over v1.
     *
     * @var \Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway
     */
    private $certificateGateway;

    /**
     * Which certificate exempts an order, and whether it may.
     *
     * @var \Taxcloud\Magento2\Model\Certificate\CertificateResolver
     */
    private $certificateResolver;

    /**
     * Magento-native tax fallback.
     *
     * @var \Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback
     */
    private $magentoTaxFallback;

    /**
     * Before/after gateway event dispatch.
     *
     * @var \Taxcloud\Magento2\Model\Event\GatewayEventDispatcher
     */
    private $eventDispatcher;

    /**
     * Retry discipline for SOAP calls.
     *
     * @var \Taxcloud\Magento2\Model\Gateway\RetryPolicy
     */
    private $retryPolicy;

    /**
     * @param \Taxcloud\Magento2\Model\Config\TaxcloudConfig $config
     * @param \Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway $soapGateway
     * @param \Taxcloud\Magento2\Model\Gateway\RequestBuilder $requestBuilder
     * @param \Taxcloud\Magento2\Model\Gateway\ResponseMapper $responseMapper
     * @param \Taxcloud\Magento2\Model\Cache\ResultCache $resultCache
     * @param \Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback $magentoTaxFallback
     * @param \Taxcloud\Magento2\Model\Event\GatewayEventDispatcher $eventDispatcher
     * @param \Taxcloud\Magento2\Model\Gateway\RetryPolicy $retryPolicy
     * @param \Taxcloud\Magento2\Model\Logging\GatewayLogger $logger
     */
    public function __construct(
        TaxcloudConfig $config,
        SoapGateway $soapGateway,
        RequestBuilder $requestBuilder,
        ResponseMapper $responseMapper,
        ResultCache $resultCache,
        \Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway $certificateGateway,
        \Taxcloud\Magento2\Model\Certificate\CertificateResolver $certificateResolver,
        MagentoTaxFallback $magentoTaxFallback,
        GatewayEventDispatcher $eventDispatcher,
        RetryPolicy $retryPolicy,
        GatewayLogger $logger
    ) {
        $this->config = $config;
        $this->soapGateway = $soapGateway;
        $this->requestBuilder = $requestBuilder;
        $this->responseMapper = $responseMapper;
        $this->resultCache = $resultCache;
        $this->certificateGateway = $certificateGateway;
        $this->certificateResolver = $certificateResolver;
        $this->magentoTaxFallback = $magentoTaxFallback;
        $this->eventDispatcher = $eventDispatcher;
        $this->retryPolicy = $retryPolicy;
        $this->tclogger = $logger;
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
     * Build the option array passed to the SoapClient constructor.
     *
     * - connection_timeout: caps how long we wait to establish the connection.
     * - stream_context http/ssl timeout: caps the read so a slow response
     *   doesn't hang the checkout thread for default_socket_timeout (~60s).
     * - cache_wsdl => WSDL_CACHE_BOTH: cache the WSDL in memory and on disk so
     *   we don't refetch api.taxcloud.net's WSDL on every client construction.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return array
     */
    public function buildSoapOptions($store = null)
    {
        return $this->soapGateway->buildSoapOptions($store);
    }

    /**
     * Get SoapClient for a store's transport configuration
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return \SoapClient
     */
    public function getClient($store = null)
    {
        return $this->soapGateway->getClient($store);
    }

    /**
     * Return whether a SOAP failure represents a connection or read timeout,
     * based on its fault code and message.
     *
     * @param Throwable $e
     * @return bool
     */
    public function isTimeoutError(Throwable $e)
    {
        return $this->retryPolicy->isTimeoutError($e);
    }

    /**
     * Execute a SOAP call, retrying up to $maxRetries times on transient faults.
     *
     * Timeouts are rethrown immediately and never retried. Any other fault is
     * retried after a short backoff until $maxRetries is exhausted, then the
     * final exception is rethrown so each call site's existing error handling
     * (Magento fallback / return false) still applies.
     *
     * Non-idempotent operations pass
     * {@see \Taxcloud\Magento2\Model\Gateway\RetryPolicy::isRetryableForNonIdempotent()}
     * as $isRetryable to narrow that to failures that never reached TaxCloud.
     *
     * @param callable      $call
     * @param int           $maxRetries  Retries after the initial attempt (default 1)
     * @param string        $operation   Label for the Advanced-mode timing record
     * @param callable|null $isRetryable fn(Throwable): bool, overriding the default rule
     * @return mixed
     */
    public function callSoapWithRetry(
        callable $call,
        $maxRetries = 1,
        $operation = 'SOAP',
        ?callable $isRetryable = null
    ) {
        $start = microtime(true);
        try {
            return $this->retryPolicy->execute($call, $maxRetries, $isRetryable);
        } finally {
            $this->tclogger->debug(
                sprintf('%s round-trip took %.0f ms (including retries)', $operation, (microtime(true) - $start) * 1000)
            );
        }
    }

    /**
     * Look up tax using TaxCloud web services
     * @param $items
     * @param $shipping
     * @param $quote
     * @return array
     */
    public function lookupTaxes($itemsByType, $shippingAssignment, $quote)
    {
        // All config (credentials, TICs, cache, fallback, logging) resolves
        // against the quote's store, not the ambient request store.
        $storeId = $quote->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling lookupTaxes');
        $this->tclogger->debug(
            'lookupTaxes context: store=' . $storeId . ', quote=' . ($quote->getId() ?: '(new)')
        );

        $result = [self::ITEM_TYPE_PRODUCT => [], self::ITEM_TYPE_SHIPPING => 0];

        // Quote::getCustomer() is declared as ExtensibleDataInterface but always
        // returns a customer here; narrowing it once keeps the certificate and
        // request-building calls below honestly typed.
        /** @var \Magento\Customer\Api\Data\CustomerInterface|null $customer */
        $customer = $quote->getCustomer();

        $address = $shippingAssignment->getShipping()->getAddress();
        if (!$address || !$address->getPostcode()) {
            $this->tclogger->info('No address, returning 0');
            return $result;
        }
        $destinationPostcode = $address->getPostcode();
        $parsedZip = PostalCodeParser::parse($destinationPostcode);
        
        // Validate the parsed ZIP code
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->tclogger->warning('Invalid ZIP code format: ' . $destinationPostcode);
            return $result;
        }
        
        $destination = $this->requestBuilder->buildLookupDestination($address, $parsedZip);

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

        $keyedAddressItems = [];
        foreach ($shippingAssignment->getItems() as $item) {
            // Skip composite child lines with no tax calculation id (null array
            // key is a PHP 8 deprecation, fatal in developer mode).
            $taxCalculationItemId = $item->getTaxCalculationItemId();
            if ($taxCalculationItemId === null) {
                continue;
            }
            $keyedAddressItems[$taxCalculationItemId] = $item;
        }

        $built = $this->requestBuilder->buildLookupCartItems($itemsByType, $keyedAddressItems, $address, $storeId);
        $cartItems = $built['cartItems'];
        $indexedItems = $built['indexedItems'];

        if (count($cartItems) === 0) {
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
            $storeId
        );
        $certificateID = $resolvedCertificate ? $resolvedCertificate->getCertificateId() : null;

        $origin = $this->requestBuilder->buildOrigin($storeId);
        if ($origin === null) {
            $this->tclogger->error('Invalid origin address configuration - cannot proceed with tax calculation');
            return $result;
        }

        $params = $this->requestBuilder->buildLookupParams(
            $customer,
            $quote,
            $cartItems,
            $origin,
            $destination,
            $certificateID
        );

        // Call before event (observers may modify $params, e.g. address verification)
        $params = $this->eventDispatcher->dispatchBefore('taxcloud_lookup_before', $params, [
            'customer' => $customer,
            'address' => $address,
            'quote' => $quote,
            'itemsByType' => $itemsByType,
            'shippingAssignment' => $shippingAssignment,
        ]);

        // check cache (use post-observer params so cache key matches what we send to TaxCloud)
        $cacheResult = $this->resultCache->getLookup($params, $storeId);
        if ($cacheResult) {
            $this->tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient($storeId);

        if (!$client) {
            $this->tclogger->error('Error encountered during lookupTaxes: Cannot get SoapClient');
            return $result;
        }

        // Call the TaxCloud web service

        $this->tclogger->info('Calling lookupTaxes LIVE API');
        $this->tclogger->debug('lookupTaxes PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));

        try {
            $lookupResponse = $this->callSoapWithRetry(function () use ($client, $params) {
                return $client->lookup($params);
            }, 1, 'lookupTaxes');
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during lookupTaxes: ' . $e->getMessage());
            $this->logSoapTrace($client, 'lookupTaxes', $storeId);

            // Check if fallback to Magento is enabled
            if ($this->config->isFallbackToMagentoEnabled($storeId)) {
                $this->tclogger->warning('TaxCloud lookup failed, falling back to Magento tax rates');
                return $this->magentoTaxFallback->calculate($itemsByType, $shippingAssignment, $quote);
            }

            return $result;
        }

        $this->logSoapTrace($client, 'lookupTaxes', $storeId);

        // Force into array
        $lookupResponse = $this->responseMapper->toArray($lookupResponse);

        $this->tclogger->debug('lookupTaxes RESPONSE:');
        $this->tclogger->debug(print_r($lookupResponse, true));

        $lookupResult = $lookupResponse['LookupResult'];

        // Call after event
        $lookupResult = $this->eventDispatcher->dispatchAfter('taxcloud_lookup_after', $lookupResult, [
            'customer' => $customer,
            'address' => $address,
            'quote' => $quote,
            'itemsByType' => $itemsByType,
            'shippingAssignment' => $shippingAssignment,
        ]);

        if ($lookupResult['ResponseType'] == 'OK' || $lookupResult['ResponseType'] == 'Informational') {
            $cartItemResponse = $lookupResult['CartItemsResponse']['CartItemResponse'];
            
            if (empty($cartItemResponse)) {
                $this->tclogger->warning('CartItemResponse is empty, skipping tax calculation');
                return $result;
            }
            $this->responseMapper->applyCartItemResponses(
                $cartItemResponse,
                $cartItems,
                $indexedItems,
                $result
            );

            $this->tclogger->info(
                'Caching lookupTaxes result for ' . $this->resultCache->getLookupLifetime($storeId)
                . 's (capped at the store\'s next local midnight)'
            );
            $this->resultCache->saveLookup($params, $result, $storeId);

            return $result;
        } else {
            $this->tclogger->error(
                'Error encountered during lookupTaxes: '
                . ($lookupResult['Messages']['ResponseMessage']['Message'] ?? 'non-OK response')
            );
            $this->tclogger->debug(print_r($lookupResult, true));

            // Check if fallback to Magento is enabled
            if ($this->config->isFallbackToMagentoEnabled($storeId)) {
                $this->tclogger->warning('TaxCloud lookup returned error response, falling back to Magento tax rates');
                return $this->magentoTaxFallback->calculate($itemsByType, $shippingAssignment, $quote);
            }
            
            return $result;
        }
    }

    /**
     * Authorized with capture using TaxCloud web services
     * This represents the combination of the Authorized and Captured process in one step. You can
     * also make these calls separately if you use a two stepped commit.
     * @param $order
     * @return bool
     */
    public function authorizeCapture($order)
    {
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling authorizeCapture for order ' . $order->getIncrementId());

        $client = $this->getClient($storeId);

        if (!$client) {
            $this->tclogger->error('Error encountered during authorizeCapture: Cannot get SoapClient');
            return false;
        }

        $dup = 'This transaction has already been marked as authorized';

        $params = $this->requestBuilder->buildAuthorizeCaptureParams($order);

        // Call before event
        $params = $this->eventDispatcher->dispatchBefore('taxcloud_authorized_with_capture_before', $params, [
            'order' => $order,
        ]);

        $this->tclogger->debug('authorizedWithCapture PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));

        try {
            $authorizedResponse = $this->callSoapWithRetry(function () use ($client, $params) {
                return $client->authorizedWithCapture($params);
            }, 1, 'authorizedWithCapture');
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during authorizeCapture: ' . $e->getMessage());
            $this->logSoapTrace($client, 'authorizedWithCapture', $storeId);
            return false;
        }

        $this->logSoapTrace($client, 'authorizedWithCapture', $storeId);

        // Force into array
        $authorizedResponse = $this->responseMapper->toArray($authorizedResponse);

        $this->tclogger->debug('authorizedWithCapture RESPONSE:');
        $this->tclogger->debug(print_r($authorizedResponse, true));

        $authorizedResult = $authorizedResponse['AuthorizedWithCaptureResult'];

        // Call after event
        $authorizedResult = $this->eventDispatcher->dispatchAfter(
            'taxcloud_authorized_with_capture_after',
            $authorizedResult,
            ['order' => $order]
        );

        if ($authorizedResult['ResponseType'] != 'OK') {
            $respMsg = $authorizedResult['Messages']['ResponseMessage']['Message'];
            if (trim(substr($respMsg, 0, strlen($dup))) === $dup) {
                // Duplicate means the the previous call was good. Therefore, consider this to be good
                $this->tclogger->warning('Warning encountered during authorizeCapture: Duplicate transaction');
                return true;
            } else {
                $this->tclogger->error('Error encountered during authorizeCapture: ' . $respMsg);
                return false;
            }
        }

        return true;
    }

    /**
     * Return order using TaxCloud web services
     * @param $creditmemo
     * @return bool
     */
    public function returnOrder($creditmemo)
    {
        $order = $creditmemo->getOrder();
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling returnOrder for creditmemo ' . $creditmemo->getIncrementId());

        $client = $this->getClient($storeId);

        if (!$client) {
            $this->tclogger->error('Error encountered during returnOrder: Cannot get SoapClient');
            return false;
        }

        $returnCart = $this->requestBuilder->buildReturnCartItems($creditmemo);
        if ($returnCart['skip']) {
            return true;
        }
        $cartItems = $returnCart['cartItems'];
        $wasTaxOnlyRefund = $returnCart['wasTaxOnlyRefund'];

        $params = $this->requestBuilder->buildReturnParams($order, $cartItems);

        // Call before event
        $params = $this->eventDispatcher->dispatchBefore('taxcloud_returned_before', $params, [
            'order' => $order,
            'items' => $creditmemo->getAllItems(),
            'creditmemo' => $creditmemo,
        ]);

        // Ensure returnCoDeliveryFeeWhenNoCartItems is always present
        if (!isset($params['returnCoDeliveryFeeWhenNoCartItems'])) {
            $params['returnCoDeliveryFeeWhenNoCartItems'] = false;
        }

        $this->tclogger->debug('returnOrder PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));

        // Ensure all required parameters are properly set for SOAP call
        $soapParams = [
            'apiLoginID' => $params['apiLoginID'],
            'apiKey' => $params['apiKey'],
            'orderID' => $params['orderID'],
            'cartItems' => $params['cartItems'],
            'returnedDate' => $params['returnedDate'],
            'returnCoDeliveryFeeWhenNoCartItems' => $params['returnCoDeliveryFeeWhenNoCartItems']
        ];

        $this->tclogger->debug('returnOrder SOAP PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($soapParams), true));

        try {
            // Returned is not idempotent in SOAP v1 — only retry a failure that
            // never reached TaxCloud, or the refund gets booked twice.
            $returnResponse = $this->callSoapWithRetry(function () use ($client, $soapParams) {
                return $client->Returned($soapParams);
            }, 1, 'Returned', [$this->retryPolicy, 'isRetryableForNonIdempotent']);
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during returnOrder: ' . $e->getMessage());
            $this->tclogger->debug(
                'SOAP parameters that failed: ' . print_r($this->redactParamsForLog($soapParams), true)
            );
            $this->logSoapTrace($client, 'Returned', $storeId);
            return false;
        }

        $this->logSoapTrace($client, 'Returned', $storeId);

        // Force into array
        $returnResponse = $this->responseMapper->toArray($returnResponse);

        $this->tclogger->debug('returnOrder RESPONSE:');
        $this->tclogger->debug(print_r($returnResponse, true));

        $returnResult = $returnResponse['ReturnedResult'];

        // Call after event
        $returnResult = $this->eventDispatcher->dispatchAfter('taxcloud_returned_after', $returnResult, [
            'order' => $order,
            'items' => $creditmemo->getAllItems(),
            'creditmemo' => $creditmemo,
        ]);

        if (!$returnResult || $returnResult['ResponseType'] != 'OK') {
            $errorMessage = 'Unknown error';
            if ($returnResult && isset($returnResult['Messages']['ResponseMessage']['Message'])) {
                $errorMessage = $returnResult['Messages']['ResponseMessage']['Message'];
            }
            $this->tclogger->error('Error encountered during returnOrder: ' . $errorMessage);
            return false;
        }

        // Re-create order as exempt in TaxCloud for nexus tracking (isExempt=true).
        if ($wasTaxOnlyRefund) {
            if ($this->lookupForOrderExempt($order, $client)) {
                $exemptCartId = $order->getIncrementId() . '-exempt';
                if (!$this->authorizeCaptureWithCartId($order, $exemptCartId, $client)) {
                    $this->tclogger->warning('returnOrder: re-create as exempt capture failed; return was successful');
                }
            } else {
                $this->tclogger->warning('returnOrder: re-create as exempt lookup failed; return was successful');
            }
        }

        return true;
    }

    /**
     * Get order details from TaxCloud (OrderDetails API).
     * Returns OrderDetailsResult with LookupDate, AuthorizedDate, CapturedDate, ReturnedDate, etc.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array|null OrderDetailsResult as array, or null on failure / order not found
     */
    public function getOrderDetails($order)
    {
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling getOrderDetails for order ' . $order->getIncrementId());

        $client = $this->getClient($storeId);
        if (!$client) {
            $this->tclogger->error('Error in getOrderDetails: Cannot get SoapClient');
            return null;
        }

        $params = $this->requestBuilder->buildOrderDetailsParams($order);
        $this->tclogger->debug('getOrderDetails PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));

        try {
            $response = $client->OrderDetails($params);
        } catch (Throwable $e) {
            $this->tclogger->error('getOrderDetails failed: ' . $e->getMessage());
            $this->logSoapTrace($client, 'OrderDetails', $storeId);
            return null;
        }

        $this->logSoapTrace($client, 'OrderDetails', $storeId);
        $this->tclogger->debug('getOrderDetails RESPONSE:');
        $this->tclogger->debug(print_r($this->responseMapper->toArray($response), true));

        $response = $this->responseMapper->toArray($response);
        if (empty($response['OrderDetailsResult'])) {
            return null;
        }

        $result = $response['OrderDetailsResult'];
        if (isset($result['ResponseType']) && $result['ResponseType'] !== 'OK') {
            // Report what TaxCloud actually said. A caller that cannot confirm the
            // capture skips the Returned call entirely, so without the message a
            // silently unreversed order looks identical to one that was never
            // captured in the first place.
            $errorMessage = 'Unknown error';
            if (isset($result['Messages']['ResponseMessage']['Message'])) {
                $errorMessage = $result['Messages']['ResponseMessage']['Message'];
            }
            $this->tclogger->error(
                'getOrderDetails returned non-OK for order ' . $order->getIncrementId() . ': '
                . ($result['ResponseType'] ?? 'unknown') . ' - ' . $errorMessage
            );
            return null;
        }

        return $result;
    }

    /**
     * Return canceled order using TaxCloud web services (no invoice; reverses capture)
     * @param $order
     * @return bool
     */
    public function returnOrderCancellation($order)
    {
        $storeId = $order->getStoreId();
        $this->tclogger->setStore($storeId);

        $this->tclogger->info('Calling returnOrderCancellation for order ' . $order->getIncrementId());

        $client = $this->getClient($storeId);

        if (!$client) {
            $this->tclogger->error('Error encountered during returnOrderCancellation: Cannot get SoapClient');
            return false;
        }

        $cartItems = $this->requestBuilder->buildCartItemsFromOrder($order);

        if (empty($cartItems)) {
            $this->tclogger->warning('returnOrderCancellation: no cart items for order ' . $order->getIncrementId());
            return false;
        }

        $params = $this->requestBuilder->buildReturnParams($order, $cartItems);

        // Call before event
        $params = $this->eventDispatcher->dispatchBefore('taxcloud_returned_before', $params, [
            'order' => $order,
            'items' => $order->getAllVisibleItems(),
            'creditmemo' => null,
        ]);

        // Ensure returnCoDeliveryFeeWhenNoCartItems is always present
        if (!isset($params['returnCoDeliveryFeeWhenNoCartItems'])) {
            $params['returnCoDeliveryFeeWhenNoCartItems'] = false;
        }

        $this->tclogger->debug('returnOrderCancellation PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));

        // Ensure all required parameters are properly set for SOAP call
        $soapParams = [
            'apiLoginID' => $params['apiLoginID'],
            'apiKey' => $params['apiKey'],
            'orderID' => $params['orderID'],
            'cartItems' => $params['cartItems'],
            'returnedDate' => $params['returnedDate'],
            'returnCoDeliveryFeeWhenNoCartItems' => $params['returnCoDeliveryFeeWhenNoCartItems']
        ];

        $this->tclogger->debug('returnOrderCancellation SOAP PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($soapParams), true));

        try {
            // Returned is not idempotent in SOAP v1 — see returnOrder().
            $returnResponse = $this->callSoapWithRetry(function () use ($client, $soapParams) {
                return $client->Returned($soapParams);
            }, 1, 'Returned', [$this->retryPolicy, 'isRetryableForNonIdempotent']);
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during returnOrderCancellation: ' . $e->getMessage());
            $this->tclogger->debug(
                'SOAP parameters that failed: ' . print_r($this->redactParamsForLog($soapParams), true)
            );
            $this->logSoapTrace($client, 'Returned', $storeId);
            return false;
        }

        $this->logSoapTrace($client, 'Returned', $storeId);

        // Force into array
        $returnResponse = $this->responseMapper->toArray($returnResponse);

        $this->tclogger->debug('returnOrderCancellation RESPONSE:');
        $this->tclogger->debug(print_r($returnResponse, true));

        $returnResult = $returnResponse['ReturnedResult'];

        // Call after event
        $returnResult = $this->eventDispatcher->dispatchAfter('taxcloud_returned_after', $returnResult, [
            'order' => $order,
            'items' => $order->getAllVisibleItems(),
            'creditmemo' => null,
        ]);

        if (!$returnResult || $returnResult['ResponseType'] != 'OK') {
            $errorMessage = 'Unknown error';
            if ($returnResult && isset($returnResult['Messages']['ResponseMessage']['Message'])) {
                $errorMessage = $returnResult['Messages']['ResponseMessage']['Message'];
            }
            $this->tclogger->error('Error encountered during returnOrderCancellation: ' . $errorMessage);
            return false;
        }

        return true;
    }

    /**
     * Look up order as exempt using a new cart ID in preparation for exempt re-create.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \SoapClient $client
     * @return bool
     */
    private function lookupForOrderExempt($order, $client)
    {
        $storeId = $order->getStoreId();
        $cartItems = $this->requestBuilder->buildCartItemsFromOrder($order);
        if (empty($cartItems)) {
            return false;
        }
        $destination = $this->requestBuilder->buildDestinationFromOrder($order);
        if ($destination === null) {
            $this->tclogger->warning('returnOrder: no valid shipping address for exempt lookup');
            return false;
        }
        $origin = $this->requestBuilder->buildOrigin($storeId);
        if ($origin === null) {
            return false;
        }
        $params = $this->requestBuilder->buildExemptLookupParams($order, $cartItems, $destination, $origin);
        $this->tclogger->debug('exempt lookup PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));
        try {
            $lookupResponse = $client->lookup($params);
        } catch (Throwable $e) {
            $this->tclogger->error('returnOrder: exempt lookup failed: ' . $e->getMessage());
            $this->logSoapTrace($client, 'exempt lookup', $storeId);
            return false;
        }
        $this->logSoapTrace($client, 'exempt lookup', $storeId);
        $lookupResponse = $this->responseMapper->toArray($lookupResponse);
        $result = isset($lookupResponse['LookupResult']) ? $lookupResponse['LookupResult'] : [];
        $responseType = isset($result['ResponseType']) ? $result['ResponseType'] : '';
        return $responseType === 'OK' || $responseType === 'Informational';
    }

    /**
     * Call AuthorizedWithCapture for the order using the given cart ID.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $cartId
     * @param \SoapClient $client
     * @return bool
     */
    private function authorizeCaptureWithCartId($order, $cartId, $client)
    {
        $storeId = $order->getStoreId();
        $params = $this->requestBuilder->buildAuthorizeCaptureParams($order, $cartId);
        $this->tclogger->debug('exempt authorizedWithCapture PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));
        try {
            $response = $client->authorizedWithCapture($params);
        } catch (Throwable $e) {
            $this->tclogger->error('returnOrder: authorizeCapture failed: ' . $e->getMessage());
            $this->logSoapTrace($client, 'exempt authorizedWithCapture', $storeId);
            return false;
        }
        $this->logSoapTrace($client, 'exempt authorizedWithCapture', $storeId);
        $response = $this->responseMapper->toArray($response);
        $result = isset($response['AuthorizedWithCaptureResult']) ? $response['AuthorizedWithCaptureResult'] : [];
        return (isset($result['ResponseType']) ? $result['ResponseType'] : '') === 'OK';
    }

    /**
     * Verify address using TaxCloud web services
     * @param array $address
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose TaxCloud config applies
     * @return bool|array
     */
    public function verifyAddress($address, $store = null)
    {
        $this->tclogger->setStore($store);

        $this->tclogger->info('Calling verifyAddress');

        $params = $this->requestBuilder->buildVerifyAddressParams($address, $store);

        // check cache
        $cacheResult = $this->resultCache->getAddress($params, $store);
        if ($cacheResult) {
            $this->tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient($store);

        if (!$client) {
            $this->tclogger->error('Error encountered during verifyAddress: Cannot get SoapClient');
            return false;
        }

        // Call before event

        $params = $this->eventDispatcher->dispatchBefore('taxcloud_verify_address_before', $params);

        // Call the TaxCloud web service

        $this->tclogger->info('Calling verifyAddress LIVE API');
        $this->tclogger->debug('verifyAddress PARAMS:');
        $this->tclogger->debug(print_r($this->redactParamsForLog($params), true));

        try {
            $verifyResponse = $this->callSoapWithRetry(function () use ($client, $params) {
                return $client->verifyAddress($params);
            }, 1, 'verifyAddress');
        } catch (Throwable $e) {
            $this->tclogger->error('Error encountered during verifyAddress: ' . $e->getMessage());
            $this->logSoapTrace($client, 'verifyAddress', $store);
            return false;
        }

        $this->logSoapTrace($client, 'verifyAddress', $store);

        // Force into array
        $verifyResponse = $this->responseMapper->toArray($verifyResponse);

        $this->tclogger->debug('verifyAddress RESPONSE:');
        $this->tclogger->debug(print_r($verifyResponse, true));

        $verifyResult = $verifyResponse['VerifyAddressResult'];

        // Call after event
        $verifyResult = $this->eventDispatcher->dispatchAfter('taxcloud_verify_address_after', $verifyResult);

        if ($verifyResult['ErrNumber'] == 0) {
            $result = [
                'Address1' => $verifyResult['Address1'] ?? '',
                'Address2' => $verifyResult['Address2'] ?? '',
                'City' => $verifyResult['City'],
                'State' => $verifyResult['State'],
                'Zip5' => $verifyResult['Zip5'] ?? '',
                'Zip4' => $verifyResult['Zip4'] ?? '',
            ];

            $this->tclogger->info('Caching verifyAddress result for ' . $this->config->getCacheLifetime($store));
            $this->resultCache->saveAddress($params, $result, $store);

            return $result;
        } else {
            $this->tclogger->warning('Error encountered during verifyAddress: ' . $verifyResult['ErrDescription']);
            return false;
        }
    }

    /**
     * Placeholder substituted for credential values in log output.
     */
    public const REDACTED_PLACEHOLDER = LogRedactor::PLACEHOLDER;

    /**
     * Return a copy of a SOAP params array with TaxCloud credentials masked
     * so they are not written to var/log/taxcloud.log.
     *
     * Keys (apiLoginID, apiKey) are preserved so operators can still confirm
     * the fields were sent; their values are replaced with REDACTED_PLACEHOLDER.
     *
     * @param array $params
     * @return array
     */
    public function redactParamsForLog(array $params)
    {
        return LogRedactor::redactArray($params);
    }

    /**
     * Log the raw SOAP wire traffic of the client's most recent call at debug
     * level (Advanced mode only), credentials redacted.
     *
     * The client only buffers wire traffic when it was constructed with
     * trace=true (see SoapGateway::buildSoapOptions, also Advanced-gated);
     * otherwise the trace getters return null/empty and nothing is logged.
     * Safe to call in catch blocks — after a fault the buffers still hold
     * whatever was sent and received last.
     *
     * @param \SoapClient|object $client
     * @param string $operation
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    private function logSoapTrace($client, $operation, $store = null)
    {
        if (!$this->config->isAdvancedLoggingEnabled($store)) {
            return;
        }
        if (!$client instanceof \SoapClient) {
            return;
        }

        $requestHeaders = $client->__getLastRequestHeaders();
        if ($requestHeaders) {
            $this->tclogger->debug($operation . ' HTTP request headers: ' . trim($requestHeaders));
        }
        $request = $client->__getLastRequest();
        if ($request) {
            $this->tclogger->debug($operation . ' SOAP request XML: ' . LogRedactor::redactXml($request));
        }
        $responseHeaders = $client->__getLastResponseHeaders();
        if ($responseHeaders) {
            $this->tclogger->debug($operation . ' HTTP response headers: ' . trim($responseHeaders));
        }
        $response = $client->__getLastResponse();
        if ($response) {
            $this->tclogger->debug($operation . ' SOAP response XML: ' . LogRedactor::redactXml($response));
        }
    }
}
