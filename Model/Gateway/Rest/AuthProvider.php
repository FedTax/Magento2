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

use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Store-aware selection of how a v3 REST request authenticates.
 *
 * Precedence per store (of the entity being processed, never the ambient
 * one): a saved rest_api_key wins and is sent as X-API-KEY; otherwise a V1
 * credential pair is exchanged for a Bearer token (cached until shortly
 * before expiry); otherwise the scope is unconfigured and the request fails
 * locally. Two stores can run different modes side by side.
 */
class AuthProvider
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var TokenExchange
     */
    private $tokenExchange;

    /**
     * @var TokenCache
     */
    private $tokenCache;

    /**
     * @param TaxcloudConfig $config
     * @param TokenExchange $tokenExchange
     * @param TokenCache $tokenCache
     */
    public function __construct(
        TaxcloudConfig $config,
        TokenExchange $tokenExchange,
        TokenCache $tokenCache
    ) {
        $this->config = $config;
        $this->tokenExchange = $tokenExchange;
        $this->tokenCache = $tokenCache;
    }

    /**
     * Resolve the auth headers for a store.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return AuthMethod
     * @throws RestConfigurationException When the scope has no usable credentials
     * @throws TokenExchangeException When the Bearer exchange fails
     */
    public function resolve($store = null): AuthMethod
    {
        $restApiKey = (string) $this->config->getRestApiKey($store);
        if ($restApiKey !== '') {
            return new AuthMethod(['X-API-KEY' => $restApiKey], false);
        }

        $apiId = (string) $this->config->getApiId($store);
        $apiKey = (string) $this->config->getApiKey($store);
        if ($apiId === '' || $apiKey === '') {
            throw new RestConfigurationException(
                'No v3 REST credentials for this scope: save an API Key (Developer → API), '
                . 'or keep V1 credentials configured so they can be exchanged automatically.'
            );
        }

        $endpoint = $this->config->getRestAuthEndpoint($store);
        $token = $this->tokenCache->get($endpoint, $apiId, $apiKey);
        if ($token === null) {
            $token = $this->tokenExchange->exchange($apiId, $apiKey, $store);
            $this->tokenCache->save($endpoint, $apiId, $apiKey, $token);
        }

        return new AuthMethod(['Authorization' => 'Bearer ' . $token->getToken()], true);
    }

    /**
     * Drop any cached Bearer token for the store's V1 pair (after a 401).
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    public function invalidate($store = null): void
    {
        $apiId = (string) $this->config->getApiId($store);
        $apiKey = (string) $this->config->getApiKey($store);
        if ($apiId === '' || $apiKey === '') {
            return;
        }

        $this->tokenCache->invalidate($this->config->getRestAuthEndpoint($store), $apiId, $apiKey);
    }
}
