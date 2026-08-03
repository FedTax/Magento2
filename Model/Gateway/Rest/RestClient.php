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
 * URL, X-API-KEY header, connection-id paths, timeout) that the REST
 * migration will extend with tax operations, but its sole operation today is
 * ping(). Credentials are handed in per call — see {@see RestCredentials} —
 * never read from config here.
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
     * @param CurlFactory    $curlFactory
     * @param TaxcloudConfig $config
     */
    public function __construct(CurlFactory $curlFactory, TaxcloudConfig $config)
    {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
    }

    /**
     * Verify a credential pair against GET /tax/connections/{id}/ping.
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

        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getSoapTimeout($store));
        $curl->addHeader('X-API-KEY', $credentials->getApiKey());
        $curl->addHeader('Accept', 'application/json');

        try {
            $curl->get($url);
            $status = (int) $curl->getStatus();
        } catch (Throwable $e) {
            return new PingResult(PingResult::TRANSPORT_ERROR, $this->scrub($e->getMessage(), $credentials));
        }

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
     * @param RestCredentials $credentials
     * @return string
     */
    private function scrub(string $message, RestCredentials $credentials): string
    {
        return str_replace(
            array_filter([$credentials->getApiKey(), $credentials->getConnectionId()]),
            '***',
            $message
        );
    }
}
