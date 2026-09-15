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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ConfigSourceReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialFingerprint;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialInventory;

/**
 * settings.json: every TaxCloud setting, at every scope, with provenance.
 *
 * The extension resolves each setting against the store of the entity being
 * processed, so one resolved value hides what a multi-store install actually
 * does. For each setting this records the value at default, at each covered
 * website and store — whether it is set there or inherited — where it comes
 * from (database, config.xml default, or locked in app/etc/env.php,
 * app/etc/config.php or an environment variable), and the value each store
 * ends up with. A locked value is the answer to "I changed the setting and
 * nothing happened".
 *
 * Credentials never appear: each is replaced by its fingerprint.
 */
class SettingsSection implements SectionInterface
{
    public const FILE = 'settings.json';

    /**
     * Prefix of every TaxCloud setting.
     */
    public const PATH_PREFIX = 'tax/taxcloud_settings/';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ConfigSourceReader
     */
    private $sourceReader;

    /**
     * @var CredentialInventory
     */
    private $credentials;

    /**
     * @var CredentialFingerprint
     */
    private $fingerprint;

    /**
     * Context of the bundle being collected, for known-secret checks.
     *
     * @var BundleContext|null
     */
    private $context;

    /**
     * @param ScopeConfigInterface  $scopeConfig
     * @param ConfigSourceReader    $sourceReader
     * @param CredentialInventory   $credentials
     * @param CredentialFingerprint $fingerprint
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ConfigSourceReader $sourceReader,
        CredentialInventory $credentials,
        CredentialFingerprint $fingerprint
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->sourceReader = $sourceReader;
        $this->credentials = $credentials;
        $this->fingerprint = $fingerprint;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'settings';
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(BundleContext $context): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function collect(BundleContext $context, BundleArchive $archive): array
    {
        $scope = $context->getScope();
        $this->context = $context;

        $dbValues = [];
        foreach ($this->sourceReader->getDatabaseRows(self::PATH_PREFIX) as $row) {
            $dbValues[$row['path']][$row['scope']][$row['scope_id']] = $row['value'];
        }

        $paths = $this->settingPaths(array_keys($dbValues));
        $settings = [];
        $locked = [];

        foreach ($paths as $path) {
            $key = substr($path, strlen(self::PATH_PREFIX));
            $isCredential = $this->credentials->isCredentialPath($path);

            $defaultEntry = $this->scopeEntry($path, 'default', 0, null, $dbValues, $isCredential);
            if ($defaultEntry['explicit'] === false) {
                // Not set at default by the merchant: whatever resolves there
                // is a module (config.xml) default.
                $moduleDefault = $this->scopeConfig->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
                $defaultEntry['source'] = $moduleDefault === null ? null : 'config.xml';
                $defaultEntry += $this->presentValue($path, $moduleDefault, $isCredential);
            }
            $setting = [
                'path' => $path,
                'credential' => $isCredential,
                'scopes' => ['default' => $defaultEntry],
                'effective' => [],
            ];
            if ($defaultEntry['locked']) {
                $locked[] = ['setting' => $key, 'scope' => 'default', 'locked_by' => $defaultEntry['source']];
            }

            foreach ($scope->getWebsites() as $website) {
                $entry = $this->scopeEntry(
                    $path,
                    ScopeInterface::SCOPE_WEBSITES,
                    (int) $website->getId(),
                    (string) $website->getCode(),
                    $dbValues,
                    $isCredential
                );
                $setting['scopes']['websites/' . $website->getCode()] = $entry;
                if ($entry['locked']) {
                    $locked[] = ['setting' => $key, 'scope' => 'websites/' . $website->getCode(),
                        'locked_by' => $entry['source']];
                }
            }

            foreach ($scope->getStores() as $store) {
                $entry = $this->scopeEntry(
                    $path,
                    ScopeInterface::SCOPE_STORES,
                    (int) $store->getId(),
                    (string) $store->getCode(),
                    $dbValues,
                    $isCredential
                );
                $setting['scopes']['stores/' . $store->getCode()] = $entry;
                if ($entry['locked']) {
                    $locked[] = ['setting' => $key, 'scope' => 'stores/' . $store->getCode(),
                        'locked_by' => $entry['source']];
                }

                $websiteLabel = 'websites/' . $this->websiteCode($scope->getWebsites(), (int) $store->getWebsiteId());
                $resolvedFrom = $entry['explicit']
                    ? 'stores/' . $store->getCode()
                    : (!empty($setting['scopes'][$websiteLabel]['explicit'])
                        ? $websiteLabel
                        : ($defaultEntry['explicit'] ? 'default' : ($defaultEntry['source'] ?? 'unset')));

                $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store->getId());
                $setting['effective'][(string) $store->getCode()] = [
                    'resolved_from' => $resolvedFrom,
                    // A default-scope lock applies to every store beneath it.
                    'locked' => $entry['locked'] || !empty($setting['scopes'][$websiteLabel]['locked'])
                        || $defaultEntry['locked'],
                ] + $this->presentValue($path, $value, $isCredential);
            }

            $settings[$key] = $setting;
        }

        $data = [
            'scope' => $scope->toArray(),
            'note' => 'Credentials (api_id, api_key, rest_api_key) are never included; each is replaced by a '
                . 'fingerprint: whether it is set, its length, last 4 characters, a SHA-256 prefix and '
                . 'whitespace/encoding flags.',
            'settings' => $settings,
            'locked' => $locked,
        ];

        $archive->addJson(self::FILE, $data);

        return $data;
    }

    /**
     * Every TaxCloud setting path known from any source.
     *
     * @param string[] $databasePaths
     * @return string[]
     */
    private function settingPaths(array $databasePaths): array
    {
        $paths = $databasePaths;

        foreach ((new \ReflectionClass(TaxcloudConfig::class))->getConstants() as $name => $value) {
            if (strpos($name, 'XML_PATH_') === 0 && is_string($value) && strpos($value, self::PATH_PREFIX) === 0) {
                $paths[] = $value;
            }
        }

        $defaults = $this->scopeConfig->getValue(
            rtrim(self::PATH_PREFIX, '/'),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if (is_array($defaults)) {
            foreach ($defaults as $key => $value) {
                if (!is_array($value)) {
                    $paths[] = self::PATH_PREFIX . $key;
                }
            }
        }

        foreach ($this->sourceReader->getLockedPaths(self::PATH_PREFIX) as $path) {
            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * Provenance of a setting at one scope.
     *
     * @param string      $path
     * @param string      $scope
     * @param int         $scopeId
     * @param string|null $scopeCode
     * @param array       $dbValues
     * @param bool        $isCredential
     * @return array
     */
    private function scopeEntry(
        string $path,
        string $scope,
        int $scopeId,
        ?string $scopeCode,
        array $dbValues,
        bool $isCredential
    ): array {
        $lock = $this->sourceReader->getLockedValue($path, $scope, $scopeCode);
        if ($lock !== null) {
            return [
                'explicit' => true,
                'source' => $lock['source'],
                'locked' => true,
            ] + $this->presentValue($path, $lock['value'], $isCredential);
        }

        if (array_key_exists($scopeId, $dbValues[$path][$scope] ?? [])) {
            return [
                'explicit' => true,
                'source' => ConfigSourceReader::SOURCE_DATABASE,
                'locked' => false,
            ] + $this->presentValue($path, $dbValues[$path][$scope][$scopeId], $isCredential);
        }

        return ['explicit' => false, 'source' => null, 'locked' => false];
    }

    /**
     * A value as it may appear in the bundle: as-is, or fingerprinted.
     *
     * @param string $path
     * @param mixed  $value
     * @param bool   $isCredential
     * @return array
     */
    private function presentValue(string $path, $value, bool $isCredential): array
    {
        if (!$isCredential) {
            if ($this->context !== null && $this->context->isKnownSecret($value)) {
                // Not a secret itself, but equal to one (the Connection ID of a
                // V1-migrated account is its V1 API Key): printing it would
                // print the credential.
                return [
                    'fingerprint' => $this->fingerprint->fingerprint((string) $value),
                    'withheld' => 'same value as a configured credential',
                ];
            }
            return ['value' => is_scalar($value) || $value === null ? $value : '[non-scalar]'];
        }

        return ['fingerprint' => $this->fingerprint->fingerprint($this->credentials->plaintext($path, $value))];
    }

    /**
     * @param array $websites
     * @param int   $websiteId
     * @return string
     */
    private function websiteCode(array $websites, int $websiteId): string
    {
        return isset($websites[$websiteId]) ? (string) $websites[$websiteId]->getCode() : (string) $websiteId;
    }
}
