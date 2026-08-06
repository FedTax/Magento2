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

use Magento\Framework\HTTP\Client\CurlFactory;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Throwable;

/**
 * Transport for the TaxCloud v3 REST API.
 *
 * {@see request()} is the general entry point every REST tax operation goes
 * through: it owns the HTTP/auth plumbing — base URL, store-resolved auth
 * headers via {@see AuthProvider}, connection-id path prefixing, timeout, the
 * Bearer 401-invalidate-retry-once rule — and returns the HTTP outcome as a
 * {@see RestResponse}. Failures before an HTTP response exist surface as
 * {@see RestTransportException} with credential values scrubbed.
 *
 * The admin "Test Connection" flows remain as thin wrappers: {@see ping()}
 * verifies an explicit credential pair (unsaved form input) and
 * {@see pingForScope()} verifies the store's resolved configuration, both
 * mapping onto {@see PingResult}.
 */
class RestClient
{
    /**
     * Path template of the connection ping endpoint, relative to the base URL.
     */
    private const PING_PATH = '/tax/connections/%s/ping';

    /**
     * Path prefix of connection-scoped operations, relative to the base URL.
     */
    private const CONNECTION_PATH = '/tax/connections/%s';

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var AuthProvider
     */
    private $authProvider;

    /**
     * @param CurlFactory    $curlFactory
     * @param TaxcloudConfig $config
     * @param AuthProvider   $authProvider
     */
    public function __construct(CurlFactory $curlFactory, TaxcloudConfig $config, AuthProvider $authProvider)
    {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
        $this->authProvider = $authProvider;
    }

    /**
     * Execute one authenticated v3 REST call for a store.
     *
     * $path is relative to the store's connection
     * (`/tax/connections/{id}<path>`) when $connectionScoped, or to the bare
     * base URL otherwise (account-level endpoints like /tax/verify-address).
     * Query strings belong in $path; path segments the caller interpolates
     * (order ids) must already be rawurlencoded.
     *
     * A 401 on a cached Bearer token invalidates it and retries exactly once
     * with a fresh exchange — the same rule the connection test applies — so
     * a revoked token never fails an operation that valid V1 credentials
     * could have served. The second answer, whatever it is, is returned.
     *
     * The store must be the store of the entity being processed, never the
     * ambient one; it scopes the endpoint, credentials, auth mode and timeout.
     *
     * @param string $method 'GET' or 'POST'
     * @param string $path Leading-slash path, with query string if any
     * @param array<string, mixed>|null $body JSON-encoded for POST; null for GET
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @param bool $connectionScoped Prefix the path with /tax/connections/{id}
     * @return RestResponse
     * @throws RestConfigurationException Connection id missing (when required) or no usable credentials
     * @throws TokenExchangeException When the Bearer exchange fails
     * @throws RestTransportException When no HTTP response was received
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        $store = null,
        bool $connectionScoped = true
    ): RestResponse {
        $connectionId = '';
        $prefix = '';
        if ($connectionScoped) {
            $connectionId = (string) $this->config->getRestConnectionId($store);
            if ($connectionId === '') {
                throw new RestConfigurationException(
                    'No Connection ID configured for this scope (Integrations → Custom API in TaxCloud).'
                );
            }
            $prefix = sprintf(self::CONNECTION_PATH, rawurlencode($connectionId));
        }

        $url = $this->config->getRestEndpoint($store) . $prefix . $path;
        $auth = $this->authProvider->resolve($store);

        $response = $this->send($method, $url, $body, $auth->getHeaders(), $store, [$connectionId]);

        if ($response->isUnauthorized() && $auth->isBearer()) {
            // The cached token may have been revoked: exchange fresh, retry once.
            $this->authProvider->invalidate($store);
            $auth = $this->authProvider->resolve($store);
            $response = $this->send($method, $url, $body, $auth->getHeaders(), $store, [$connectionId]);
        }

        return $response;
    }

    /**
     * Verify an explicit credential pair against GET /tax/connections/{id}/ping.
     *
     * The store argument scopes the endpoint and timeout (not the credentials,
     * which the caller already resolved): per project convention it must be
     * the store of the entity/scope being processed, never the ambient store.
     *
     * @param RestCredentials $credentials
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return PingResult
     */
    public function ping(RestCredentials $credentials, $store = null): PingResult
    {
        $url = $this->config->getRestEndpoint($store)
            . sprintf(self::PING_PATH, rawurlencode($credentials->getConnectionId()));

        try {
            $response = $this->send(
                'GET',
                $url,
                null,
                ['X-API-KEY' => $credentials->getApiKey()],
                $store,
                [$credentials->getApiKey(), $credentials->getConnectionId()]
            );
        } catch (RestTransportException $e) {
            return new PingResult(PingResult::TRANSPORT_ERROR, $e->getMessage());
        }

        return $this->mapStatus($response->getStatus());
    }

    /**
     * Verify the store's own resolved v3 configuration (X-API-KEY or Bearer).
     *
     * Bearer specifics: a 401 on a possibly-stale cached token invalidates it
     * and retries exactly once with a fresh exchange; a second 401 is a real
     * authentication failure. Exchange rejection maps to AUTH_FAILED so the
     * caller can point at the V1 pair.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @param string|null $connectionIdOverride Unsaved form value taking precedence over config
     * @return PingResult
     * @throws RestConfigurationException When the scope has no usable credentials
     */
    public function pingForScope($store = null, ?string $connectionIdOverride = null): PingResult
    {
        $connectionId = $connectionIdOverride !== null && $connectionIdOverride !== ''
            ? $connectionIdOverride
            : (string) $this->config->getRestConnectionId($store);
        if ($connectionId === '') {
            throw new RestConfigurationException(
                'No Connection ID configured for this scope (Integrations → Custom API in TaxCloud).'
            );
        }

        $url = $this->config->getRestEndpoint($store)
            . sprintf(self::PING_PATH, rawurlencode($connectionId));

        try {
            $auth = $this->authProvider->resolve($store);
        } catch (TokenExchangeException $e) {
            return $e->isRejected()
                ? new PingResult(PingResult::AUTH_FAILED)
                : new PingResult(PingResult::TRANSPORT_ERROR, $e->getMessage());
        }

        try {
            $response = $this->send('GET', $url, null, $auth->getHeaders(), $store, [$connectionId]);

            if ($response->isUnauthorized() && $auth->isBearer()) {
                // The cached token may have been revoked: exchange fresh, retry once.
                $this->authProvider->invalidate($store);
                try {
                    $auth = $this->authProvider->resolve($store);
                } catch (TokenExchangeException $e) {
                    return $e->isRejected()
                        ? new PingResult(PingResult::AUTH_FAILED)
                        : new PingResult(PingResult::TRANSPORT_ERROR, $e->getMessage());
                }
                $response = $this->send('GET', $url, null, $auth->getHeaders(), $store, [$connectionId]);
            }
        } catch (RestTransportException $e) {
            return new PingResult(PingResult::TRANSPORT_ERROR, $e->getMessage());
        }

        return $this->mapStatus($response->getStatus());
    }

    /**
     * Perform one HTTP exchange and wrap the outcome.
     *
     * @param string $method
     * @param string $url
     * @param array<string, mixed>|null $body
     * @param array<string, string> $authHeaders
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @param string[] $secrets Values to scrub from transport error messages
     * @return RestResponse
     * @throws RestTransportException
     */
    private function send(string $method, string $url, ?array $body, array $authHeaders, $store, array $secrets)
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getSoapTimeout($store));

        $headers = $authHeaders + ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }
        foreach ($headers as $name => $value) {
            $curl->addHeader($name, $value);
        }

        try {
            if ($method === 'POST') {
                $curl->post($url, $body !== null ? (string) json_encode($body) : '');
            } elseif ($method === 'GET') {
                $curl->get($url);
            } else {
                throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method);
            }

            return new RestResponse((int) $curl->getStatus(), (string) $curl->getBody());
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RestTransportException($this->scrub($e->getMessage(), $secrets), 0, $e);
        }
    }

    /**
     * @param int $status
     * @return PingResult
     */
    private function mapStatus(int $status): PingResult
    {
        if ($status >= 200 && $status < 300) {
            return new PingResult(PingResult::OK);
        }
        if ($status === 401) {
            return new PingResult(PingResult::AUTH_FAILED);
        }
        // 404 per TaxCloud's docs; observed live behavior (2026-08-04) is 400
        // for a well-formed connection id the account doesn't own and 422 for
        // a malformed one. All three mean "this Connection ID is not usable".
        if ($status === 400 || $status === 404 || $status === 422) {
            return new PingResult(PingResult::UNKNOWN_CONNECTION);
        }

        return new PingResult(PingResult::TRANSPORT_ERROR, 'HTTP ' . $status);
    }

    /**
     * Strip credential values out of a transport error message before it can
     * reach admin messages or logs (curl errors may echo the request URL,
     * which carries the connection id).
     *
     * @param string $message
     * @param string[] $secrets
     * @return string
     */
    private function scrub(string $message, array $secrets): string
    {
        return str_replace(array_values(array_filter($secrets)), '***', $message);
    }
}
