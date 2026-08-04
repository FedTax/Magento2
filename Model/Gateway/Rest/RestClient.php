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
 * Minimal transport for the TaxCloud v3 REST API.
 *
 * Deliberately connection-scoped only: it owns the HTTP/auth plumbing (base
 * URL, auth headers, connection-id paths, timeout) that the REST migration
 * will extend with tax operations, but its sole operation today is ping —
 * either with explicit credentials ({@see ping()}, used by the admin test
 * button for unsaved input) or with the store's resolved configuration
 * ({@see pingForScope()}, X-API-KEY or Bearer via {@see AuthProvider}).
 */
class RestClient
{
    /**
     * Path template of the connection ping endpoint, relative to the base URL.
     */
    private const PING_PATH = '/tax/connections/%s/ping';

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
        try {
            $status = $this->sendPing(
                $credentials->getConnectionId(),
                ['X-API-KEY' => $credentials->getApiKey()],
                $store
            );
        } catch (Throwable $e) {
            return new PingResult(
                PingResult::TRANSPORT_ERROR,
                $this->scrub($e->getMessage(), [$credentials->getApiKey(), $credentials->getConnectionId()])
            );
        }

        return $this->mapStatus($status);
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

        try {
            $auth = $this->authProvider->resolve($store);
        } catch (TokenExchangeException $e) {
            return $e->isRejected()
                ? new PingResult(PingResult::AUTH_FAILED)
                : new PingResult(PingResult::TRANSPORT_ERROR, $e->getMessage());
        }

        try {
            $status = $this->sendPing($connectionId, $auth->getHeaders(), $store);

            if ($status === 401 && $auth->isBearer()) {
                // The cached token may have been revoked: exchange fresh, retry once.
                $this->authProvider->invalidate($store);
                try {
                    $auth = $this->authProvider->resolve($store);
                } catch (TokenExchangeException $e) {
                    return $e->isRejected()
                        ? new PingResult(PingResult::AUTH_FAILED)
                        : new PingResult(PingResult::TRANSPORT_ERROR, $e->getMessage());
                }
                $status = $this->sendPing($connectionId, $auth->getHeaders(), $store);
            }
        } catch (Throwable $e) {
            return new PingResult(
                PingResult::TRANSPORT_ERROR,
                $this->scrub($e->getMessage(), [$connectionId])
            );
        }

        return $this->mapStatus($status);
    }

    /**
     * @param string $connectionId
     * @param array<string, string> $authHeaders
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int HTTP status
     */
    private function sendPing(string $connectionId, array $authHeaders, $store): int
    {
        $url = $this->config->getRestEndpoint($store)
            . sprintf(self::PING_PATH, rawurlencode($connectionId));

        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getSoapTimeout($store));
        foreach ($authHeaders + ['Accept' => 'application/json'] as $name => $value) {
            $curl->addHeader($name, $value);
        }
        $curl->get($url);

        return (int) $curl->getStatus();
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
        if ($status === 404) {
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
