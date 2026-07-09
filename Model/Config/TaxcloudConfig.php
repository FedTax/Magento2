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

namespace Taxcloud\Magento2\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for the module's store-scoped configuration.
 *
 * Collects the scattered `tax/taxcloud_settings/*` lookups behind intention-
 * revealing accessors so the gateway collaborators depend on a small config
 * contract rather than reaching into ScopeConfig with string paths.
 */
class TaxcloudConfig
{
    /**
     * Default SOAP connection/read timeout in seconds.
     */
    const DEFAULT_SOAP_TIMEOUT = 10;

    /**#@+
     * Store-config paths.
     */
    const XML_PATH_ENABLED = 'tax/taxcloud_settings/enabled';
    const XML_PATH_LOGGING = 'tax/taxcloud_settings/logging';
    const XML_PATH_API_ID = 'tax/taxcloud_settings/api_id';
    const XML_PATH_API_KEY = 'tax/taxcloud_settings/api_key';
    const XML_PATH_GUEST_CUSTOMER_ID = 'tax/taxcloud_settings/guest_customer_id';
    const XML_PATH_CACHE_LIFETIME = 'tax/taxcloud_settings/cache_lifetime';
    const XML_PATH_FALLBACK_TO_MAGENTO = 'tax/taxcloud_settings/fallback_to_magento';
    const XML_PATH_API_TIMEOUT = 'tax/taxcloud_settings/api_timeout';
    /**#@-*/

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Whether the TaxCloud integration is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Whether TaxCloud request/response logging is enabled.
     *
     * @return bool
     */
    public function isLoggingEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_LOGGING, ScopeInterface::SCOPE_STORE);
    }

    /**
     * TaxCloud API login ID.
     *
     * @return string|null
     */
    public function getApiId()
    {
        return $this->scopeConfig->getValue(self::XML_PATH_API_ID, ScopeInterface::SCOPE_STORE);
    }

    /**
     * TaxCloud API key.
     *
     * @return string|null
     */
    public function getApiKey()
    {
        return $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Customer ID reported to TaxCloud for guest orders (defaults to '-1').
     *
     * @return string
     */
    public function getGuestCustomerId()
    {
        return $this->scopeConfig->getValue(self::XML_PATH_GUEST_CUSTOMER_ID, ScopeInterface::SCOPE_STORE) ?? '-1';
    }

    /**
     * Response cache lifetime in seconds (0 disables caching).
     *
     * @return int
     */
    public function getCacheLifetime(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_CACHE_LIFETIME, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Whether to fall back to Magento-native tax rates when TaxCloud fails.
     *
     * @return bool
     */
    public function isFallbackToMagentoEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_FALLBACK_TO_MAGENTO, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Configured SOAP timeout in seconds, or the default when unset/invalid.
     *
     * @return int
     */
    public function getSoapTimeout(): int
    {
        $configured = (int) $this->scopeConfig->getValue(self::XML_PATH_API_TIMEOUT, ScopeInterface::SCOPE_STORE);
        return $configured > 0 ? $configured : self::DEFAULT_SOAP_TIMEOUT;
    }
}
