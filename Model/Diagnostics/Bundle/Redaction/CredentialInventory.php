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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ConfigSourceReader;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;

/**
 * Every credential value configured anywhere in the installation.
 *
 * The bundle generator scrubs these exact values from every byte it writes, on
 * top of the pattern-based redaction. Collected across ALL scopes — not just
 * the scope being exported — because a log line written for one store can
 * carry another store's key, and because a value that was once saved and then
 * replaced can still be in a rotated log from before a redaction gap closed.
 */
class CredentialInventory
{
    /**
     * Config paths holding secrets. Never emitted in plaintext, under any option.
     */
    public const CREDENTIAL_PATHS = [
        TaxcloudConfig::XML_PATH_API_ID,
        TaxcloudConfig::XML_PATH_API_KEY,
        TaxcloudConfig::XML_PATH_REST_API_KEY,
    ];

    /**
     * Credential paths stored through Magento's Encrypted backend model.
     */
    public const ENCRYPTED_PATHS = [
        TaxcloudConfig::XML_PATH_REST_API_KEY,
    ];

    /**
     * Magento's encrypted-value envelope: "<key version>:<cipher version>:<payload>".
     */
    private const ENCRYPTED_ENVELOPE = '/^\d+:\d+:\S+$/';

    /**
     * @var ConfigSourceReader
     */
    private $sourceReader;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var TokenCache
     */
    private $tokenCache;

    /**
     * @param ConfigSourceReader    $sourceReader
     * @param ScopeConfigInterface  $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param EncryptorInterface    $encryptor
     * @param TaxcloudConfig        $config
     * @param TokenCache            $tokenCache
     */
    public function __construct(
        ConfigSourceReader $sourceReader,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        EncryptorInterface $encryptor,
        TaxcloudConfig $config,
        TokenCache $tokenCache
    ) {
        $this->sourceReader = $sourceReader;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->encryptor = $encryptor;
        $this->config = $config;
        $this->tokenCache = $tokenCache;
    }

    /**
     * Whether a config path holds a credential.
     *
     * @param string $path
     * @return bool
     */
    public function isCredentialPath(string $path): bool
    {
        return in_array($path, self::CREDENTIAL_PATHS, true);
    }

    /**
     * Plaintext of a stored credential value (decrypting where the path is
     * stored encrypted and the value carries Magento's envelope).
     *
     * @param string $path
     * @param mixed  $stored
     * @return string|null
     */
    public function plaintext(string $path, $stored): ?string
    {
        if ($stored === null || $stored === '' || is_array($stored)) {
            return null;
        }
        $stored = (string) $stored;
        if (in_array($path, self::ENCRYPTED_PATHS, true) && preg_match(self::ENCRYPTED_ENVELOPE, $stored)) {
            try {
                $decrypted = (string) $this->encryptor->decrypt($stored);
            } catch (\Throwable $e) {
                $decrypted = '';
            }
            return $decrypted !== '' ? $decrypted : null;
        }

        return $stored;
    }

    /**
     * Every secret value known to the installation: raw stored values,
     * their plaintexts, and any cached Bearer token.
     *
     * @return string[]
     */
    public function collectSecrets(): array
    {
        $secrets = [];
        $add = static function ($value) use (&$secrets) {
            if (is_string($value) && $value !== '') {
                $secrets[$value] = true;
            }
        };

        try {
            foreach ($this->sourceReader->getDatabaseRows('tax/taxcloud_settings/') as $row) {
                if ($this->isCredentialPath($row['path'])) {
                    $add($row['value']);
                    $add($this->plaintext($row['path'], $row['value']));
                }
            }
        } catch (\Throwable $e) {
            // A database failure here must not stop the other sources below.
            $secrets['__inventory_incomplete__'] = true;
        }

        $scopes = [['default', null]];
        foreach ($this->storeManager->getWebsites() as $website) {
            $scopes[] = [ScopeInterface::SCOPE_WEBSITES, (string) $website->getCode()];
        }
        $stores = $this->storeManager->getStores(true);
        foreach ($stores as $store) {
            $scopes[] = [ScopeInterface::SCOPE_STORES, (string) $store->getCode()];
        }

        foreach (self::CREDENTIAL_PATHS as $path) {
            foreach ($scopes as [$scope, $code]) {
                try {
                    $locked = $this->sourceReader->getLockedValue($path, $scope, $code);
                } catch (\Throwable $e) {
                    $locked = null;
                }
                if ($locked !== null) {
                    $add(is_scalar($locked['value']) ? (string) $locked['value'] : null);
                    $add($this->plaintext($path, $locked['value']));
                }
            }
            foreach ($stores as $store) {
                $stored = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store->getId());
                $add(is_scalar($stored) ? (string) $stored : null);
                $add($this->plaintext($path, $stored));
            }
        }

        foreach ($stores as $store) {
            try {
                $apiId = (string) $this->config->getApiId($store->getId());
                $apiKey = (string) $this->config->getApiKey($store->getId());
                if ($apiId !== '' && $apiKey !== '') {
                    $token = $this->tokenCache->get(
                        $this->config->getRestAuthEndpoint($store->getId()),
                        $apiId,
                        $apiKey
                    );
                    if ($token !== null) {
                        $add($token->getToken());
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        unset($secrets['__inventory_incomplete__']);

        // Numeric-string keys (an all-digit API ID) become ints in PHP arrays.
        return array_map('strval', array_keys($secrets));
    }
}
