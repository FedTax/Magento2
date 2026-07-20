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
use Taxcloud\Magento2\Model\Gateway\ExemptionValidator;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\ResponseMapper;
use Taxcloud\Magento2\Model\Gateway\RetryPolicy;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
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
     * TaxCloud logger (gated by the logging setting).
     *
     * @var \Psr\Log\LoggerInterface
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
     * Exemption-certificate validation.
     *
     * @var \Taxcloud\Magento2\Model\Gateway\ExemptionValidator
     */
    private $exemptionValidator;

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
     * @param \Taxcloud\Magento2\Model\Gateway\ExemptionValidator $exemptionValidator
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
        ExemptionValidator $exemptionValidator,
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
        $this->exemptionValidator = $exemptionValidator;
        $this->magentoTaxFallback = $magentoTaxFallback;
        $this->eventDispatcher = $eventDispatcher;
        $this->retryPolicy = $retryPolicy;
        $this->tclogger = $logger;
    }

    /**
     * Check whether an exemption certificate covers the destination state.
     *
     * Calls GetExemptCertificates via SOAP, caches the result, and returns
     * the certificate ID only when the destination state appears in the
     * certificate's ExemptStates list.  Returns null otherwise, so the
     * lookup proceeds without an exemption.
     *
     * @param string $certificateID
     * @param string $customerID
     * @param string $destinationState  Two-letter state abbreviation
     * @return string|null  The certificate ID if it covers the state, null otherwise
     */
    public function getValidatedCertificateID($certificateID, $customerID, $destinationState)
    {
        return $this->exemptionValidator->validate($certificateID, $customerID, $destinationState);
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
     * @return array
     */
    public function buildSoapOptions()
    {
        return $this->soapGateway->buildSoapOptions();
    }

    /**
     * Get SoapClient
     * @return \SoapClient
     */
    public function getClient()
    {
        return $this->soapGateway->getClient();
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
     * @param callable $call
     * @param int      $maxRetries Retries after the initial attempt (default 1)
     * @return mixed
     */
    public function callSoapWithRetry(callable $call, $maxRetries = 1)
    {
        return $this->retryPolicy->execute($call, $maxRetries);
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
        $this->tclogger->info('Calling lookupTaxes');

        $result = [self::ITEM_TYPE_PRODUCT => [], self::ITEM_TYPE_SHIPPING => 0];

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
            $this->tclogger->info('Invalid ZIP code format: ' . $destinationPostcode);
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

        $built = $this->requestBuilder->buildLookupCartItems($itemsByType, $keyedAddressItems, $address);
        $cartItems = $built['cartItems'];
        $indexedItems = $built['indexedItems'];

        if (count($cartItems) === 0) {
            $this->tclogger->info('No cart items, returning 0');
            return $result;
        }

        $certificateID = null;
        if ($customer) {
            $certificate = $customer->getCustomAttribute('taxcloud_cert');
            if ($certificate && $certificate->getValue()) {
                // Only apply the exemption when the cert actually covers the destination state
                $certificateID = $this->getValidatedCertificateID(
                    $certificate->getValue(),
                    $customer->getId(),
                    $destination['State']
                );
            }
        }

        $origin = $this->requestBuilder->buildOrigin();
        if ($origin === null) {
            $this->tclogger->info('Invalid origin address configuration - cannot proceed with tax calculation');
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
        $cacheResult = $this->resultCache->getLookup($params);
        if ($cacheResult) {
            $this->tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient();

        if (!$client) {
            $this->tclogger->info('Error encountered during lookupTaxes: Cannot get SoapClient');
            return $result;
        }

        // Call the TaxCloud web service

        $this->tclogger->info('Calling lookupTaxes LIVE API');
        $this->tclogger->info('lookupTaxes PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($params), true));

        try {
            $lookupResponse = $this->callSoapWithRetry(function () use ($client, $params) {
                return $client->lookup($params);
            });
        } catch (Throwable $e) {
            $this->tclogger->info('Error encountered during lookupTaxes: ' . $e->getMessage());

            // Check if fallback to Magento is enabled
            if ($this->config->isFallbackToMagentoEnabled()) {
                $this->tclogger->info('TaxCloud lookup failed, falling back to Magento tax rates');
                return $this->magentoTaxFallback->calculate($itemsByType, $shippingAssignment, $quote);
            }

            return $result;
        }

        // Force into array
        $lookupResponse = $this->responseMapper->toArray($lookupResponse);

        $this->tclogger->info('lookupTaxes RESPONSE:');
        $this->tclogger->info(print_r($lookupResponse, true));

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
                $this->tclogger->info('CartItemResponse is empty, skipping tax calculation');
                return $result;
            }
            $this->responseMapper->applyCartItemResponses(
                $cartItemResponse,
                $cartItems,
                $indexedItems,
                $result
            );

            $this->tclogger->info('Caching lookupTaxes result for ' . $this->config->getCacheLifetime());
            $this->resultCache->saveLookup($params, $result);

            return $result;
        } else {
            $this->tclogger->info('Error encountered during lookupTaxes: ');
            $this->tclogger->info(print_r($lookupResult, true));
            
            // Check if fallback to Magento is enabled
            if ($this->config->isFallbackToMagentoEnabled()) {
                $this->tclogger->info('TaxCloud lookup returned error response, falling back to Magento tax rates');
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
        $this->tclogger->info('Calling authorizeCapture');

        $client = $this->getClient();

        if (!$client) {
            $this->tclogger->info('Error encountered during authorizeCapture: Cannot get SoapClient');
            return false;
        }

        $dup = 'This transaction has already been marked as authorized';

        $params = $this->requestBuilder->buildAuthorizeCaptureParams($order);

        // Call before event
        $params = $this->eventDispatcher->dispatchBefore('taxcloud_authorized_with_capture_before', $params, [
            'order' => $order,
        ]);

        $this->tclogger->info('authorizedWithCapture PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($params), true));

        try {
            $authorizedResponse = $this->callSoapWithRetry(function () use ($client, $params) {
                return $client->authorizedWithCapture($params);
            });
        } catch (Throwable $e) {
            $this->tclogger->info('Error encountered during authorizeCapture: ' . $e->getMessage());
            return false;
        }

        // Force into array
        $authorizedResponse = $this->responseMapper->toArray($authorizedResponse);

        $this->tclogger->info('authorizedWithCapture RESPONSE:');
        $this->tclogger->info(print_r($authorizedResponse, true));

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
                $this->tclogger->info('Warning encountered during authorizeCapture: Duplicate transaction');
                return true;
            } else {
                $this->tclogger->info('Error encountered during authorizeCapture: ' . $respMsg);
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
        $this->tclogger->info('Calling returnOrder');

        $client = $this->getClient();

        if (!$client) {
            $this->tclogger->info('Error encountered during returnOrder: Cannot get SoapClient');
            return false;
        }

        $order = $creditmemo->getOrder();

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

        $this->tclogger->info('returnOrder PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($params), true));

        // Ensure all required parameters are properly set for SOAP call
        $soapParams = [
            'apiLoginID' => $params['apiLoginID'],
            'apiKey' => $params['apiKey'],
            'orderID' => $params['orderID'],
            'cartItems' => $params['cartItems'],
            'returnedDate' => $params['returnedDate'],
            'returnCoDeliveryFeeWhenNoCartItems' => $params['returnCoDeliveryFeeWhenNoCartItems']
        ];

        $this->tclogger->info('returnOrder SOAP PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($soapParams), true));

        try {
            $returnResponse = $this->callSoapWithRetry(function () use ($client, $soapParams) {
                return $client->Returned($soapParams);
            });
        } catch (Throwable $e) {
            $this->tclogger->info('Error encountered during returnOrder: ' . $e->getMessage());
            $this->tclogger->info(
                'SOAP parameters that failed: ' . print_r($this->redactParamsForLog($soapParams), true)
            );
            return false;
        }

        // Force into array
        $returnResponse = $this->responseMapper->toArray($returnResponse);

        $this->tclogger->info('returnOrder RESPONSE:');
        $this->tclogger->info(print_r($returnResponse, true));

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
            $this->tclogger->info('Error encountered during returnOrder: ' . $errorMessage);
            return false;
        }

        // Re-create order as exempt in TaxCloud for nexus tracking (isExempt=true).
        if ($wasTaxOnlyRefund) {
            if ($this->lookupForOrderExempt($order, $client)) {
                $exemptCartId = $order->getIncrementId() . '-exempt';
                if (!$this->authorizeCaptureWithCartId($order, $exemptCartId, $client)) {
                    $this->tclogger->info('returnOrder: re-create as exempt capture failed; return was successful');
                }
            } else {
                $this->tclogger->info('returnOrder: re-create as exempt lookup failed; return was successful');
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
        $this->tclogger->info('Calling getOrderDetails for order ' . $order->getIncrementId());

        $client = $this->getClient();
        if (!$client) {
            $this->tclogger->info('Error in getOrderDetails: Cannot get SoapClient');
            return null;
        }

        $params = $this->requestBuilder->buildOrderDetailsParams($order);

        try {
            $response = $client->OrderDetails($params);
        } catch (Throwable $e) {
            $this->tclogger->info('getOrderDetails failed: ' . $e->getMessage());
            return null;
        }

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
            $this->tclogger->info(
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
        $this->tclogger->info('Calling returnOrderCancellation');

        $client = $this->getClient();

        if (!$client) {
            $this->tclogger->info('Error encountered during returnOrderCancellation: Cannot get SoapClient');
            return false;
        }

        $cartItems = $this->requestBuilder->buildCartItemsFromOrder($order);

        if (empty($cartItems)) {
            $this->tclogger->info('returnOrderCancellation: no cart items for order ' . $order->getIncrementId());
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

        $this->tclogger->info('returnOrderCancellation PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($params), true));

        // Ensure all required parameters are properly set for SOAP call
        $soapParams = [
            'apiLoginID' => $params['apiLoginID'],
            'apiKey' => $params['apiKey'],
            'orderID' => $params['orderID'],
            'cartItems' => $params['cartItems'],
            'returnedDate' => $params['returnedDate'],
            'returnCoDeliveryFeeWhenNoCartItems' => $params['returnCoDeliveryFeeWhenNoCartItems']
        ];

        $this->tclogger->info('returnOrderCancellation SOAP PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($soapParams), true));

        try {
            $returnResponse = $this->callSoapWithRetry(function () use ($client, $soapParams) {
                return $client->Returned($soapParams);
            });
        } catch (Throwable $e) {
            $this->tclogger->info('Error encountered during returnOrderCancellation: ' . $e->getMessage());
            $this->tclogger->info(
                'SOAP parameters that failed: ' . print_r($this->redactParamsForLog($soapParams), true)
            );
            return false;
        }

        // Force into array
        $returnResponse = $this->responseMapper->toArray($returnResponse);

        $this->tclogger->info('returnOrderCancellation RESPONSE:');
        $this->tclogger->info(print_r($returnResponse, true));

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
            $this->tclogger->info('Error encountered during returnOrderCancellation: ' . $errorMessage);
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
        $cartItems = $this->requestBuilder->buildCartItemsFromOrder($order);
        if (empty($cartItems)) {
            return false;
        }
        $destination = $this->requestBuilder->buildDestinationFromOrder($order);
        if ($destination === null) {
            $this->tclogger->info('returnOrder: no valid shipping address for exempt lookup');
            return false;
        }
        $origin = $this->requestBuilder->buildOrigin();
        if ($origin === null) {
            return false;
        }
        $params = $this->requestBuilder->buildExemptLookupParams($order, $cartItems, $destination, $origin);
        try {
            $lookupResponse = $client->lookup($params);
        } catch (Throwable $e) {
            $this->tclogger->info('returnOrder: exempt lookup failed: ' . $e->getMessage());
            return false;
        }
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
        $params = $this->requestBuilder->buildAuthorizeCaptureParams($order, $cartId);
        try {
            $response = $client->authorizedWithCapture($params);
        } catch (Throwable $e) {
            $this->tclogger->info('returnOrder: authorizeCapture failed: ' . $e->getMessage());
            return false;
        }
        $response = $this->responseMapper->toArray($response);
        $result = isset($response['AuthorizedWithCaptureResult']) ? $response['AuthorizedWithCaptureResult'] : [];
        return (isset($result['ResponseType']) ? $result['ResponseType'] : '') === 'OK';
    }

    /**
     * Verify address using TaxCloud web services
     * @param $creditmemo
     * @return bool|array
     */
    public function verifyAddress($address)
    {
        $this->tclogger->info('Calling verifyAddress');

        $params = $this->requestBuilder->buildVerifyAddressParams($address);

        // check cache
        $cacheResult = $this->resultCache->getAddress($params);
        if ($cacheResult) {
            $this->tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient();

        if (!$client) {
            $this->tclogger->info('Error encountered during verifyAddress: Cannot get SoapClient');
            return false;
        }

        // Call before event

        $params = $this->eventDispatcher->dispatchBefore('taxcloud_verify_address_before', $params);

        // Call the TaxCloud web service

        $this->tclogger->info('Calling verifyAddress LIVE API');
        $this->tclogger->info('verifyAddress PARAMS:');
        $this->tclogger->info(print_r($this->redactParamsForLog($params), true));

        try {
            $verifyResponse = $this->callSoapWithRetry(function () use ($client, $params) {
                return $client->verifyAddress($params);
            });
        } catch (Throwable $e) {
            $this->tclogger->info('Error encountered during verifyAddress: ' . $e->getMessage());
            return false;
        }

        // Force into array
        $verifyResponse = $this->responseMapper->toArray($verifyResponse);

        $this->tclogger->info('verifyAddress RESPONSE:');
        $this->tclogger->info(print_r($verifyResponse, true));

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

            $this->tclogger->info('Caching verifyAddress result for ' . $this->config->getCacheLifetime());
            $this->resultCache->saveAddress($params, $result);

            return $result;
        } else {
            $this->tclogger->info('Error encountered during verifyAddress: ' . $verifyResult['ErrDescription']);
            return false;
        }
    }

    /**
     * Placeholder substituted for credential values in log output.
     */
    public const REDACTED_PLACEHOLDER = '***REDACTED***';

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
        if (array_key_exists('apiLoginID', $params)) {
            $params['apiLoginID'] = self::REDACTED_PLACEHOLDER;
        }
        if (array_key_exists('apiKey', $params)) {
            $params['apiKey'] = self::REDACTED_PLACEHOLDER;
        }
        return $params;
    }
}
