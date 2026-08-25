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
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;

/**
 * Typed reader for the module's store-scoped configuration.
 *
 * Collects the scattered `tax/taxcloud_settings/*` lookups behind intention-
 * revealing accessors so the gateway collaborators depend on a small config
 * contract rather than reaching into ScopeConfig with string paths.
 *
 * Every accessor takes an optional $store (store id, code, or store object,
 * mirroring what core's Magento\Tax\Model\Config does) and forwards it as the
 * scope code. Callers processing an order/quote MUST pass that entity's store:
 * with $store omitted, Magento resolves the ambient store of the current
 * request, which in admin/cron/webhook contexts is the default store view —
 * never the store the order was placed on.
 */
class TaxcloudConfig
{
    /**
     * Default SOAP connection/read timeout in seconds.
     */
    public const DEFAULT_SOAP_TIMEOUT = 10;

    /**
     * Production TaxCloud v3 REST API base URL.
     *
     * Also declared as the field default in etc/config.xml. This constant is
     * the fallback for installs whose stored value is blank, so the module
     * keeps reaching production even if the config row is cleared.
     */
    public const DEFAULT_REST_ENDPOINT = 'https://api.v3.taxcloud.com';

    /**
     * Production host of the v1→v3 credential exchange service.
     *
     * Undocumented but load-bearing: TaxCloud's own WooCommerce plugin ships
     * this same host. Config-overridable (config.xml default, no admin field)
     * so a vendor-side move needs no code release. Staging equivalent:
     * https://staging-taxcloudapi.azurewebsites.net
     */
    public const DEFAULT_REST_AUTH_ENDPOINT = 'https://taxcloudapi-appservice-core-prod.azurewebsites.net';

    /**
     * Production TaxCloud SOAP WSDL endpoint.
     *
     * Also declared as the field default in etc/config.xml. This constant is the
     * fallback for installs whose stored value is blank, so the module keeps
     * reaching production even if the config row is cleared.
     */
    public const DEFAULT_WSDL_URL = 'https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl';

    /**
     * Default TIC (Taxability Information Code) when configuration is empty.
     */
    public const DEFAULT_TIC = '00000';

    /**
     * Default shipping TIC when configuration is empty.
     */
    public const DEFAULT_SHIPPING_TIC = '11010';

    /**#@+
     * Logging modes stored under XML_PATH_LOGGING.
     *
     * BASIC (1) is the pre-existing "Enable" value, so installs upgraded from
     * the two-state flag keep logging exactly what they logged before.
     * ADVANCED additionally emits debug-level records: full request/response
     * payloads, SOAP wire traces, and timing.
     */
    public const LOGGING_DISABLED = 0;
    public const LOGGING_BASIC = 1;
    public const LOGGING_ADVANCED = 2;
    /**#@-*/

    /**#@+
     * Store-config paths.
     */
    public const XML_PATH_ENABLED = 'tax/taxcloud_settings/enabled';
    public const XML_PATH_API_TYPE = 'tax/taxcloud_settings/api_type';
    public const XML_PATH_LOGGING = 'tax/taxcloud_settings/logging';
    public const XML_PATH_API_ID = 'tax/taxcloud_settings/api_id';
    public const XML_PATH_API_KEY = 'tax/taxcloud_settings/api_key';
    public const XML_PATH_GUEST_CUSTOMER_ID = 'tax/taxcloud_settings/guest_customer_id';
    public const XML_PATH_CACHE_LIFETIME = 'tax/taxcloud_settings/cache_lifetime';
    public const XML_PATH_FALLBACK_TO_MAGENTO = 'tax/taxcloud_settings/fallback_to_magento';
    public const XML_PATH_CALCULATIONS_ONLY = 'tax/taxcloud_settings/calculations_only';

    /**
     * Master switch for exemption certificates. Off by default.
     */
    public const XML_PATH_EXEMPTIONS_ENABLED = 'tax/taxcloud_settings/exemptions_enabled';

    /**
     * Customer groups the store treats as exempt.
     */
    public const XML_PATH_EXEMPT_CUSTOMER_GROUPS = 'tax/taxcloud_settings/exempt_customer_groups';

    /**
     * Hide the exemption interface outside those groups.
     */
    public const XML_PATH_RESTRICT_TO_EXEMPT_GROUPS = 'tax/taxcloud_settings/restrict_to_exempt_groups';

    /**
     * Seller name recorded on certificates customers create.
     */
    public const XML_PATH_COMPANY_NAME = 'tax/taxcloud_settings/company_name';
    public const XML_PATH_API_TIMEOUT = 'tax/taxcloud_settings/api_timeout';
    public const XML_PATH_WSDL_URL = 'tax/taxcloud_settings/wsdl_url';
    public const XML_PATH_REST_API_KEY = 'tax/taxcloud_settings/rest_api_key';
    public const XML_PATH_REST_CONNECTION_ID = 'tax/taxcloud_settings/rest_connection_id';
    public const XML_PATH_REST_ENDPOINT = 'tax/taxcloud_settings/rest_endpoint';
    public const XML_PATH_REST_AUTH_ENDPOINT = 'tax/taxcloud_settings/rest_auth_endpoint';
    public const XML_PATH_VERIFY_ADDRESS = 'tax/taxcloud_settings/verify_address';
    public const XML_PATH_CAPTURE_TRIGGER = 'tax/taxcloud_settings/capture_trigger';
    public const XML_PATH_DEFAULT_TIC = 'tax/taxcloud_settings/default_tic';
    public const XML_PATH_SHIPPING_TIC = 'tax/taxcloud_settings/shipping_tic';
    /**#@-*/

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface|null
     */
    private $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface|null $encryptor
     */
    public function __construct(ScopeConfigInterface $scopeConfig, ?EncryptorInterface $encryptor = null)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the TaxCloud integration is enabled.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isEnabled($store = null): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * Configured logging mode (one of the LOGGING_* constants).
     *
     * Unknown stored values collapse to BASIC rather than ADVANCED so a
     * corrupted row can never silently turn on payload logging.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int
     */
    public function getLoggingMode($store = null): int
    {
        $mode = (int) $this->scopeConfig->getValue(self::XML_PATH_LOGGING, ScopeInterface::SCOPE_STORE, $store);
        if (!in_array($mode, [self::LOGGING_DISABLED, self::LOGGING_BASIC, self::LOGGING_ADVANCED], true)) {
            return self::LOGGING_BASIC;
        }
        return $mode;
    }

    /**
     * Whether TaxCloud logging is enabled at all (Basic or Advanced).
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isLoggingEnabled($store = null): bool
    {
        return $this->getLoggingMode($store) !== self::LOGGING_DISABLED;
    }

    /**
     * Whether Advanced logging is enabled (payload dumps, SOAP wire traces).
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isAdvancedLoggingEnabled($store = null): bool
    {
        return $this->getLoggingMode($store) === self::LOGGING_ADVANCED;
    }

    /**
     * Which TaxCloud API generation this store uses (ApiType::SOAP or ::REST).
     *
     * Unknown or blank stored values collapse to REST — the shipped default —
     * so a corrupted row selects the current API rather than the legacy one.
     * Existing installs are pinned to SOAP by a data patch at upgrade, not
     * here: deriving the type from other fields at read time would make the
     * effective value depend on data this accessor can't show in the admin UI.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getApiType($store = null): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, $store);
        if (!in_array($value, [ApiType::SOAP, ApiType::REST], true)) {
            return ApiType::REST;
        }
        return $value;
    }

    /**
     * TaxCloud API login ID.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string|null
     */
    public function getApiId($store = null)
    {
        return $this->scopeConfig->getValue(self::XML_PATH_API_ID, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * TaxCloud API key.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string|null
     */
    public function getApiKey($store = null)
    {
        return $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * Customer ID reported to TaxCloud for guest orders (defaults to '-1').
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getGuestCustomerId($store = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GUEST_CUSTOMER_ID,
            ScopeInterface::SCOPE_STORE,
            $store
        ) ?? '-1';
    }

    /**
     * Response cache lifetime in seconds (0 disables caching).
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int
     */
    public function getCacheLifetime($store = null): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_CACHE_LIFETIME, ScopeInterface::SCOPE_STORE, $store);
    }

    /**
     * Whether to fall back to Magento-native tax rates when TaxCloud fails.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isFallbackToMagentoEnabled($store = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_FALLBACK_TO_MAGENTO,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Whether the module is restricted to tax calculation only.
     *
     * When on, the calculation calls still run (Lookup, VerifyAddress, and the
     * exempt-certificate validation nested inside Lookup), but nothing that
     * mutates order state in TaxCloud is sent: AuthorizedWithCapture, Returned
     * for credit memos, and Returned/OrderDetails for cancellations are all
     * skipped. It is for merchants whose orders reach TaxCloud through another
     * system (QuickBooks and the like), where a second push from Magento would
     * double-report the sale.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isCalculationsOnly($store = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_CALCULATIONS_ONLY,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Configured SOAP timeout in seconds, or the default when unset/invalid.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int
     */
    public function getSoapTimeout($store = null): int
    {
        $configured = (int) $this->scopeConfig->getValue(
            self::XML_PATH_API_TIMEOUT,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configured > 0 ? $configured : self::DEFAULT_SOAP_TIMEOUT;
    }

    /**
     * SOAP WSDL endpoint, or the production default when unset/blank.
     *
     * Lets an install point at a sandbox or staging endpoint without a code
     * change. Whitespace-only values fall back rather than being passed to the
     * SoapClient, where they would surface as an opaque WSDL fetch failure.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getWsdlUrl($store = null): string
    {
        $configured = $this->scopeConfig->getValue(self::XML_PATH_WSDL_URL, ScopeInterface::SCOPE_STORE, $store);
        $configured = is_string($configured) ? trim($configured) : '';

        return $configured !== '' ? $configured : self::DEFAULT_WSDL_URL;
    }

    /**
     * TaxCloud v3 REST API key, decrypted.
     *
     * Stored encrypted by the admin field's Encrypted backend model. Returns
     * null when unset/blank. Without an encryptor (test construction), the
     * stored value is returned as-is.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string|null
     */
    public function getRestApiKey($store = null)
    {
        $stored = $this->scopeConfig->getValue(self::XML_PATH_REST_API_KEY, ScopeInterface::SCOPE_STORE, $store);
        if ($stored === null || $stored === '') {
            return null;
        }
        return $this->encryptor !== null ? $this->encryptor->decrypt($stored) : $stored;
    }

    /**
     * TaxCloud v3 REST connection ID (UUID from Integrations → Custom API).
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string|null
     */
    public function getRestConnectionId($store = null)
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_REST_CONNECTION_ID,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * TaxCloud v3 REST base URL, or the production default when unset/blank.
     *
     * Lets an install point at a staging endpoint without a code change (the
     * field is config-only, not shown in admin). Trailing slashes are trimmed
     * so callers can append paths directly.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getRestEndpoint($store = null): string
    {
        $configured = $this->scopeConfig->getValue(self::XML_PATH_REST_ENDPOINT, ScopeInterface::SCOPE_STORE, $store);
        $configured = is_string($configured) ? trim($configured) : '';

        return rtrim($configured !== '' ? $configured : self::DEFAULT_REST_ENDPOINT, '/');
    }

    /**
     * Host of the v1→v3 credential exchange service, or the production
     * default when unset/blank. Trailing slashes trimmed for path appending.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getRestAuthEndpoint($store = null): string
    {
        $configured = $this->scopeConfig->getValue(
            self::XML_PATH_REST_AUTH_ENDPOINT,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        $configured = is_string($configured) ? trim($configured) : '';

        return rtrim($configured !== '' ? $configured : self::DEFAULT_REST_AUTH_ENDPOINT, '/');
    }

    /**
     * Whether TaxCloud address verification (VerifyAddress) is enabled.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isVerifyAddressEnabled($store = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_VERIFY_ADDRESS,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Configured capture trigger (one of the CaptureTrigger::* values), or the
     * order-creation default when unset/blank.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getCaptureTrigger($store = null): string
    {
        $configured = $this->scopeConfig->getValue(
            self::XML_PATH_CAPTURE_TRIGGER,
            ScopeInterface::SCOPE_STORE,
            $store
        );

        return ($configured !== null && $configured !== '') ? (string) $configured : CaptureTrigger::ORDER_CREATION;
    }

    /**
     * Default TIC for products without a custom taxcloud_tic attribute, or the
     * DEFAULT_TIC fallback when configuration is empty.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getDefaultTic($store = null): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_DEFAULT_TIC, ScopeInterface::SCOPE_STORE, $store);

        return ($value !== null && $value !== '') ? (string) $value : self::DEFAULT_TIC;
    }

    /**
     * TIC reported for shipping lines, or the DEFAULT_SHIPPING_TIC fallback
     * when configuration is empty.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getShippingTic($store = null): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SHIPPING_TIC, ScopeInterface::SCOPE_STORE, $store);

        return ($value !== null && $value !== '') ? (string) $value : self::DEFAULT_SHIPPING_TIC;
    }

    /**
     * Whether exemption certificates are offered at all for this store.
     *
     * Defaults to false. A certificate is an unverified attestation, so a store
     * gains the feature only by an admin turning it on, never by upgrading.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function areExemptionsEnabled($store = null): bool
    {
        // getValue + cast, like isEnabled() above, rather than isSetFlag():
        // consistent with the rest of this class and immune to a scope-config
        // double that only implements getValue.
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_EXEMPTIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Customer group ids the store treats as exempt.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int[]
     */
    public function getExemptCustomerGroups($store = null): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_EXEMPT_CUSTOMER_GROUPS,
            ScopeInterface::SCOPE_STORE,
            $store
        );

        if ($raw === null || $raw === '') {
            return [];
        }

        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_map('intval', array_filter($ids, static function ($id) {
            return $id !== '' && $id !== null;
        })));
    }

    /**
     * Whether the exemption interface is hidden outside the exempt groups.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isRestrictedToExemptGroups($store = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_RESTRICT_TO_EXEMPT_GROUPS,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Seller name recorded on certificates customers create.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getCompanyName($store = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_COMPANY_NAME,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }
}
