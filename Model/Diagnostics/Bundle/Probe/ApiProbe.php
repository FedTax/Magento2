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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Probe;

use Magento\Store\Api\Data\StoreInterface;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;

/**
 * Live, read-only calls against TaxCloud from the store's own network.
 *
 * Proves at the moment of capture whether the store's credentials and outbound
 * connectivity work — frequently the entire answer to a ticket. For each
 * distinct configuration in scope it performs a canned tax Lookup and an
 * address verification against a fixed, well-known address. It never uses
 * customer data, and never captures, returns or otherwise files anything:
 * the Lookup prices a one-line cart under a fixed diagnostics cart id, which
 * TaxCloud does not file unless it is captured.
 *
 * Everything fails fast and nothing throws: the store's own API timeout
 * applies, no retries are made, a network failure skips the API calls behind
 * it, and every failure is recorded as a finding.
 */
class ApiProbe
{
    /**
     * TaxCloud's published business address, used as the probe destination
     * (and as the origin when the store has no valid shipping origin).
     */
    public const TEST_ADDRESS = [
        'Address1' => '162 East Avenue',
        'Address2' => '',
        'City' => 'Norwalk',
        'State' => 'CT',
        'Zip5' => '06851',
        'Zip4' => '',
    ];

    /**
     * Cart and customer identifiers the probe uses, recognisable in TaxCloud.
     */
    public const PROBE_CART_ID = 'taxcloud-diagnostics-probe';
    public const PROBE_CUSTOMER_ID = 'taxcloud-diagnostics';

    /**
     * General tangible personal property.
     */
    private const PROBE_TIC = '00000';

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var AuthProvider
     */
    private $authProvider;

    /**
     * @var TokenCache
     */
    private $tokenCache;

    /**
     * @var SoapGateway
     */
    private $soapGateway;

    /**
     * @var RequestBuilder
     */
    private $requestBuilder;

    /**
     * @var EndpointCheck
     */
    private $endpointCheck;

    /**
     * @var CanadaAccessChecker
     */
    private $canadaAccessChecker;

    /**
     * @param TaxcloudConfig $config
     * @param RestClient     $restClient
     * @param AuthProvider   $authProvider
     * @param TokenCache     $tokenCache
     * @param SoapGateway    $soapGateway
     * @param RequestBuilder $requestBuilder
     * @param EndpointCheck  $endpointCheck
     * @param CanadaAccessChecker $canadaAccessChecker
     */
    public function __construct(
        TaxcloudConfig $config,
        RestClient $restClient,
        AuthProvider $authProvider,
        TokenCache $tokenCache,
        SoapGateway $soapGateway,
        RequestBuilder $requestBuilder,
        EndpointCheck $endpointCheck,
        CanadaAccessChecker $canadaAccessChecker
    ) {
        $this->config = $config;
        $this->restClient = $restClient;
        $this->authProvider = $authProvider;
        $this->tokenCache = $tokenCache;
        $this->soapGateway = $soapGateway;
        $this->requestBuilder = $requestBuilder;
        $this->endpointCheck = $endpointCheck;
        $this->canadaAccessChecker = $canadaAccessChecker;
    }

    /**
     * Probe every distinct TaxCloud configuration among the given stores.
     *
     * Stores sharing API type, endpoint and credentials are probed once, so a
     * fifty-store-view install with one account makes one set of calls.
     *
     * @param StoreInterface[] $stores
     * @return array
     */
    public function probe(array $stores): array
    {
        $groups = [];
        $skipped = [];

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->isEnabled($storeId)) {
                $skipped[] = (string) $store->getCode();
                continue;
            }
            $key = $this->groupKey($storeId);
            if (!isset($groups[$key])) {
                $groups[$key] = ['store' => $store, 'store_codes' => []];
            }
            $groups[$key]['store_codes'][] = (string) $store->getCode();
        }

        $results = [];
        foreach ($groups as $group) {
            $storeId = (int) $group['store']->getId();
            try {
                $result = $this->config->getApiType($storeId) === ApiType::REST
                    ? $this->probeRest($storeId)
                    : $this->probeSoap($storeId);
            } catch (\Throwable $e) {
                $result = ['error' => 'Probe aborted: ' . $e->getMessage()];
            }
            $results[] = ['stores' => $group['store_codes']] + $result;
        }

        return [
            'test_address' => self::TEST_ADDRESS,
            'note' => 'Read-only: a canned Lookup and an address verification (plus a sample Canadian Lookup'
                . ' for stores with Canadian tax on). Nothing is captured or filed.',
            'skipped_stores_taxcloud_disabled' => $skipped,
            'configurations' => $results,
        ];
    }

    /**
     * @param int $storeId
     * @return array
     */
    private function probeRest(int $storeId): array
    {
        $endpoint = $this->config->getRestEndpoint($storeId);
        $timeout = $this->config->getSoapTimeout($storeId);
        $connectionId = (string) $this->config->getRestConnectionId($storeId);

        $result = [
            'api_type' => ApiType::REST,
            'endpoint' => $endpoint,
            'connection_id' => $connectionId !== '' ? $connectionId : null,
            'timeout_seconds' => $timeout,
            'network' => $this->endpointCheck->check($endpoint, $timeout),
            'authentication' => $this->restAuthentication($storeId, $timeout),
            'calls' => [],
        ];

        $blocked = $this->networkBlocker($result['network']);
        if ($blocked === null && !$result['authentication']['ok']) {
            $blocked = 'authentication failed';
        }
        if ($blocked === null && $connectionId === '') {
            $blocked = 'no Connection ID configured';
        }

        $origin = $this->origin($storeId);
        $result['origin_source'] = $origin['source'];
        $address = $this->toV3Address(self::TEST_ADDRESS);

        $lookupPayload = ['items' => [[
            'cartId' => self::PROBE_CART_ID,
            'customerId' => self::PROBE_CUSTOMER_ID,
            'currency' => ['currencyCode' => 'USD'],
            'origin' => $this->toV3Address($origin['address']),
            'destination' => $address,
            'deliveredBySeller' => false,
            'lineItems' => [[
                'index' => 0,
                'itemId' => self::PROBE_CART_ID,
                'tic' => (int) self::PROBE_TIC,
                'price' => 10.0,
                'quantity' => 1.0,
            ]],
        ]]];

        $result['calls']['lookup'] = $blocked !== null
            ? $this->skipped($endpoint . '/tax/connections/' . $connectionId . '/carts', $blocked)
            : $this->restCall(
                $endpoint . '/tax/connections/' . rawurlencode($connectionId) . '/carts',
                function () use ($lookupPayload, $storeId) {
                    return $this->restClient->request('POST', '/carts', $lookupPayload, $storeId);
                }
            );

        $result['calls']['verify_address'] = $blocked !== null && $blocked !== 'no Connection ID configured'
            ? $this->skipped($endpoint . '/tax/verify-address', $blocked)
            : $this->restCall($endpoint . '/tax/verify-address', function () use ($address, $storeId) {
                return $this->restClient->request('POST', '/tax/verify-address', $address, $storeId, false);
            });

        // Canadian tax needs Canada enabled on the account, which only a
        // Canadian lookup reveals — worth the call only where it is turned on.
        if ($this->config->isCanadaTaxEnabled($storeId)) {
            $result['calls']['canada_access'] = $blocked !== null
                ? $this->skipped($endpoint . '/tax/connections/' . $connectionId . '/carts', $blocked)
                : $this->canadaAccessCall(
                    $endpoint . '/tax/connections/' . rawurlencode($connectionId) . '/carts',
                    $storeId
                );
        }

        return $result;
    }

    /**
     * Run the Canada access check and record it in the probe's call shape.
     *
     * @param string $url
     * @param int $storeId
     * @return array
     */
    private function canadaAccessCall(string $url, int $storeId): array
    {
        $check = $this->canadaAccessChecker->check($storeId);
        $outcome = [
            'url' => $url,
            'http_status' => $check->getHttpStatus(),
            'duration_ms' => $check->getDurationMs(),
            'success' => $check->isEnabled(),
            'outcome' => $check->getOutcome(),
        ];
        if ($check->isEnabled()) {
            $outcome['sample_rate'] = $check->getRate();
        } else {
            $outcome['error_message'] = (string) $check->getMessage();
            $outcome['error_code'] = null;
        }

        return $outcome;
    }

    /**
     * How the store authenticates to v3, and whether it currently can.
     *
     * @param int $storeId
     * @param int $timeout
     * @return array
     */
    private function restAuthentication(int $storeId, int $timeout): array
    {
        $apiKey = (string) $this->config->getRestApiKey($storeId);
        if ($apiKey !== '') {
            return ['ok' => true, 'method' => 'api_key', 'detail' => 'X-API-KEY header (V3 API Key)'];
        }

        $apiId = (string) $this->config->getApiId($storeId);
        $v1Key = (string) $this->config->getApiKey($storeId);
        if ($apiId === '' || $v1Key === '') {
            return [
                'ok' => false,
                'method' => 'none',
                'error' => 'No V3 API Key and no V1 API ID/API Key pair to exchange.',
            ];
        }

        $authEndpoint = $this->config->getRestAuthEndpoint($storeId);
        $result = [
            'method' => 'v1_token_exchange',
            'auth_endpoint' => $authEndpoint,
            'auth_network' => $this->endpointCheck->check($authEndpoint, $timeout),
        ];

        $cached = null;
        try {
            $cached = $this->tokenCache->get($authEndpoint, $apiId, $v1Key);
        } catch (\Throwable $e) {
            $cached = null;
        }

        $start = microtime(true);
        try {
            $this->authProvider->resolve($storeId);
            $result += [
                'ok' => true,
                'token_acquired' => true,
                'token_source' => $cached !== null ? 'cache' : 'exchange',
                'duration_ms' => $this->elapsedMs($start),
            ];
        } catch (\Throwable $e) {
            $result += [
                'ok' => false,
                'token_acquired' => false,
                'duration_ms' => $this->elapsedMs($start),
                'error' => $e->getMessage(),
            ];
        }

        return $result;
    }

    /**
     * @param string   $url
     * @param callable $call Returns a RestResponse
     * @return array
     */
    private function restCall(string $url, callable $call): array
    {
        $start = microtime(true);
        try {
            /** @var RestResponse $response */
            $response = $call();
        } catch (\Throwable $e) {
            return [
                'url' => $url,
                'http_status' => null,
                'duration_ms' => $this->elapsedMs($start),
                'success' => false,
                'error_message' => $e->getMessage(),
                'error_code' => null,
                'error_type' => (new \ReflectionClass($e))->getShortName(),
            ];
        }

        $body = $response->getBody();
        $outcome = [
            'url' => $url,
            'http_status' => $response->getStatus(),
            'duration_ms' => $this->elapsedMs($start),
            'success' => $response->isSuccess(),
        ];
        if (!$response->isSuccess()) {
            $outcome['error_message'] = $response->errorDetail();
            $outcome['error_code'] = is_array($body)
                ? ($body['code'] ?? $body['errorCode'] ?? $body['type'] ?? null)
                : null;
        }

        return $outcome;
    }

    /**
     * @param int $storeId
     * @return array
     */
    private function probeSoap(int $storeId): array
    {
        $wsdl = $this->config->getWsdlUrl($storeId);
        $timeout = $this->config->getSoapTimeout($storeId);
        $apiId = (string) $this->config->getApiId($storeId);
        $apiKey = (string) $this->config->getApiKey($storeId);

        $result = [
            'api_type' => ApiType::SOAP,
            'endpoint' => $wsdl,
            'timeout_seconds' => $timeout,
            'network' => $this->endpointCheck->check($wsdl, $timeout),
            'authentication' => [
                'method' => 'v1_api_id_and_key',
                'ok' => $apiId !== '' && $apiKey !== '',
            ],
            'calls' => [],
        ];

        $blocked = $this->networkBlocker($result['network']);
        if ($blocked === null && !$result['authentication']['ok']) {
            $result['authentication']['error'] = 'API ID or API Key not configured.';
            $blocked = 'credentials not configured';
        }

        $client = null;
        if ($blocked === null) {
            $start = microtime(true);
            try {
                $client = $this->soapGateway->createClient($storeId, ['trace' => true, 'exceptions' => true]);
                $result['wsdl'] = ['ok' => true, 'duration_ms' => $this->elapsedMs($start)];
            } catch (\Throwable $e) {
                $result['wsdl'] = ['ok' => false, 'duration_ms' => $this->elapsedMs($start),
                    'error' => $e->getMessage()];
                $blocked = 'WSDL could not be loaded';
            }
        }

        $origin = $this->origin($storeId);
        $result['origin_source'] = $origin['source'];

        if ($blocked !== null || $client === null) {
            $result['calls']['lookup'] = $this->skipped($wsdl, (string) $blocked);
            $result['calls']['verify_address'] = $this->skipped($wsdl, (string) $blocked);
            return $result;
        }

        $result['calls']['lookup'] = $this->soapCall($client, $wsdl, function ($client) use ($apiId, $apiKey, $origin) {
            $response = $client->lookup([
                'apiLoginID' => $apiId,
                'apiKey' => $apiKey,
                'customerID' => self::PROBE_CUSTOMER_ID,
                'cartID' => self::PROBE_CART_ID,
                'cartItems' => [[
                    'Index' => 0,
                    'ItemID' => self::PROBE_CART_ID,
                    'TIC' => self::PROBE_TIC,
                    'Price' => 10.0,
                    'Qty' => 1,
                ]],
                'origin' => $origin['address'],
                'destination' => self::TEST_ADDRESS,
                'deliveredBySeller' => false,
                'exemptCert' => ['CertificateID' => null],
            ]);
            $lookup = $response->LookupResult ?? null;
            $ok = ($lookup->ResponseType ?? '') === 'OK';
            $message = $lookup->Messages->ResponseMessage->Message ?? null;

            return [$ok, $ok ? null : ($message ?? 'ResponseType ' . ($lookup->ResponseType ?? 'missing')),
                $ok ? null : ($lookup->ResponseType ?? null)];
        });

        $result['calls']['verify_address'] = $this->soapCall($client, $wsdl, function ($client) use ($apiId, $apiKey) {
            $response = $client->verifyAddress([
                'apiLoginID' => $apiId,
                'apiKey' => $apiKey,
                'address1' => self::TEST_ADDRESS['Address1'],
                'address2' => self::TEST_ADDRESS['Address2'],
                'city' => self::TEST_ADDRESS['City'],
                'state' => self::TEST_ADDRESS['State'],
                'zip5' => self::TEST_ADDRESS['Zip5'],
                'zip4' => self::TEST_ADDRESS['Zip4'],
            ]);
            $verify = $response->VerifyAddressResult ?? null;
            $errNumber = (string) ($verify->ErrNumber ?? '');
            $ok = $errNumber === '0';

            return [$ok, $ok ? null : ($verify->ErrDescription ?? 'No result returned'), $ok ? null : $errNumber];
        });

        return $result;
    }

    /**
     * @param \SoapClient $client
     * @param string      $url
     * @param callable    $call function (\SoapClient): array{0: bool, 1: string|null, 2: string|null}
     * @return array
     */
    private function soapCall($client, string $url, callable $call): array
    {
        $start = microtime(true);
        try {
            [$ok, $message, $code] = $call($client);
            $fault = null;
        } catch (\Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
            $code = $e instanceof \SoapFault ? ($e->faultcode ?? null) : null;
            $fault = (new \ReflectionClass($e))->getShortName();
        }

        $status = null;
        try {
            $headers = (string) $client->__getLastResponseHeaders();
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $headers, $m)) {
                $status = (int) $m[1];
            }
        } catch (\Throwable $e) {
            $status = null;
        }

        $result = [
            'url' => $url,
            'http_status' => $status,
            'duration_ms' => $this->elapsedMs($start),
            'success' => $ok,
        ];
        if (!$ok) {
            $result['error_message'] = $message;
            $result['error_code'] = $code;
            if ($fault !== null) {
                $result['error_type'] = $fault;
            }
        }

        return $result;
    }

    /**
     * @param int $storeId
     * @return array{source: string, address: array}
     */
    private function origin(int $storeId): array
    {
        try {
            $origin = $this->requestBuilder->buildOrigin($storeId);
        } catch (\Throwable $e) {
            $origin = null;
        }

        return $origin !== null && !empty($origin['State'])
            ? ['source' => 'store shipping origin', 'address' => $origin]
            : ['source' => 'test address (store shipping origin is not a valid US address)',
                'address' => self::TEST_ADDRESS];
    }

    /**
     * @param array $network
     * @return string|null
     */
    private function networkBlocker(array $network): ?string
    {
        if (empty($network['dns']['ok'])) {
            return 'DNS resolution failed';
        }
        if (empty($network['tls']['ok'])) {
            return 'TLS handshake failed';
        }

        return null;
    }

    /**
     * @param string $url
     * @param string $reason
     * @return array
     */
    private function skipped(string $url, string $reason): array
    {
        return ['url' => $url, 'success' => false, 'skipped' => $reason];
    }

    /**
     * @param array $address v1-shaped
     * @return array
     */
    private function toV3Address(array $address): array
    {
        $v3 = [
            'line1' => (string) ($address['Address1'] ?? ''),
            'city' => (string) ($address['City'] ?? ''),
            'state' => (string) ($address['State'] ?? ''),
            'zip' => (string) ($address['Zip5'] ?? '') . (!empty($address['Zip4']) ? '-' . $address['Zip4'] : ''),
        ];
        if (!empty($address['Address2'])) {
            $v3['line2'] = (string) $address['Address2'];
        }

        return $v3;
    }

    /**
     * Stores with identical settings produce identical probe results.
     *
     * @param int $storeId
     * @return string
     */
    private function groupKey(int $storeId): string
    {
        return hash('sha256', implode("\0", [
            $this->config->getApiType($storeId),
            $this->config->getRestEndpoint($storeId),
            $this->config->getRestAuthEndpoint($storeId),
            $this->config->getWsdlUrl($storeId),
            (string) $this->config->getRestConnectionId($storeId),
            (string) $this->config->getRestApiKey($storeId),
            (string) $this->config->getApiId($storeId),
            (string) $this->config->getApiKey($storeId),
            (string) $this->config->getSoapTimeout($storeId),
            $this->config->isCanadaTaxEnabled($storeId) ? 'canada' : '',
        ]));
    }

    /**
     * @param float $start
     * @return int
     */
    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
