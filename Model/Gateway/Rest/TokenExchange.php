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
use Throwable;

/**
 * Exchanges a V1 credential pair for a short-lived v3 Bearer token.
 *
 * POST {rest_auth_endpoint}/api/v3/auth/token with {apiLoginID, apiKey}.
 * The endpoint is undocumented but is what TaxCloud's own WooCommerce plugin
 * ships; the host is config-overridable so a vendor-side move needs no
 * release. Request/response bodies are never logged — they carry the
 * credential pair and a live token.
 */
class TokenExchange
{
    /**
     * Exchange path, relative to the auth host.
     */
    private const TOKEN_PATH = '/api/v3/auth/token';

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
     * Exchange a V1 pair for a Bearer token.
     *
     * The store argument scopes the endpoint and timeout, never the
     * credentials — callers resolve those for the scope they are processing.
     *
     * @param string $apiLoginId
     * @param string $apiKey
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return BearerToken
     * @throws TokenExchangeException
     */
    public function exchange(string $apiLoginId, string $apiKey, $store = null): BearerToken
    {
        $url = $this->config->getRestAuthEndpoint($store) . self::TOKEN_PATH;

        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getSoapTimeout($store));
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');

        try {
            $curl->post($url, json_encode(['apiLoginID' => $apiLoginId, 'apiKey' => $apiKey]));
            $status = (int) $curl->getStatus();
            $body = (string) $curl->getBody();
        } catch (Throwable $e) {
            throw new TokenExchangeException(
                TokenExchangeException::UNREACHABLE,
                'TaxCloud credential exchange could not be reached: '
                . str_replace(array_filter([$apiLoginId, $apiKey]), '***', $e->getMessage())
            );
        }

        if ($status >= 400 && $status < 500) {
            throw new TokenExchangeException(
                TokenExchangeException::REJECTED,
                'TaxCloud rejected the V1 credential pair (HTTP ' . $status . ').'
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new TokenExchangeException(
                TokenExchangeException::UNREACHABLE,
                'TaxCloud credential exchange failed (HTTP ' . $status . ').'
            );
        }

        $data = json_decode($body, true);
        $token = is_array($data) ? ($data['access_token'] ?? '') : '';
        if (!is_string($token) || $token === '') {
            throw new TokenExchangeException(
                TokenExchangeException::UNREACHABLE,
                'TaxCloud credential exchange returned no access token.'
            );
        }

        $validTo = strtotime((string) ($data['access_token_validTo'] ?? ''));
        if ($validTo === false || $validTo <= time()) {
            // No usable expiry: treat the token as immediately stale except for
            // the current request (small fixed window keeps the flow working).
            $validTo = time() + 60;
        }

        return new BearerToken($token, $validTo);
    }
}
