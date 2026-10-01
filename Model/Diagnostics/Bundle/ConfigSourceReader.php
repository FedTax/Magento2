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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Reader as DeploymentReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Config\File\ConfigFilePool;

/**
 * Reads where configuration values actually come from.
 *
 * The one place in the diagnostics code allowed near app/etc/env.php, which
 * holds the crypt key and the database password. Nothing from that file is
 * ever returned whole: only the `system` subtree (store configuration locked
 * by `bin/magento config:set --lock-*`) is kept, and the individual
 * deployment keys the environment report needs are fetched through a
 * hardcoded whitelist. Anything else is refused, whatever the caller asks.
 */
class ConfigSourceReader
{
    public const SOURCE_ENV_VAR = 'environment variable';
    public const SOURCE_ENV_PHP = 'app/etc/env.php';
    public const SOURCE_CONFIG_PHP = 'app/etc/config.php';
    public const SOURCE_DATABASE = 'database';

    /**
     * The only deployment-config keys this reader will return. Names of
     * backends, never hosts, credentials or connection strings.
     */
    public const DEPLOYMENT_KEY_WHITELIST = [
        'MAGE_MODE',
        'cache/frontend/default/backend',
        'cache/frontend/page_cache/backend',
        'session/save',
        'queue/consumers_wait_for_messages',
        'cron/enabled',
    ];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DeploymentReader
     */
    private $deploymentReader;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var SettingChecker
     */
    private $settingChecker;

    /**
     * `system` subtrees of the two deployment files, keyed by source label.
     *
     * @var array<string, array>|null
     */
    private $systemSubtrees;

    /**
     * @param ResourceConnection $resource
     * @param DeploymentReader   $deploymentReader
     * @param DeploymentConfig   $deploymentConfig
     * @param SettingChecker     $settingChecker
     */
    public function __construct(
        ResourceConnection $resource,
        DeploymentReader $deploymentReader,
        DeploymentConfig $deploymentConfig,
        SettingChecker $settingChecker
    ) {
        $this->resource = $resource;
        $this->deploymentReader = $deploymentReader;
        $this->deploymentConfig = $deploymentConfig;
        $this->settingChecker = $settingChecker;
    }

    /**
     * core_config_data rows under a path prefix.
     *
     * @param string $pathPrefix e.g. 'tax/taxcloud_settings/'
     * @return array<int, array{scope: string, scope_id: int, path: string, value: string|null}>
     */
    public function getDatabaseRows(string $pathPrefix): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path LIKE ?', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pathPrefix) . '%')
            ->order(['path', 'scope', 'scope_id']);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'scope' => (string) $row['scope'],
                'scope_id' => (int) $row['scope_id'],
                'path' => (string) $row['path'],
                'value' => $row['value'] === null ? null : (string) $row['value'],
            ];
        }

        return $rows;
    }

    /**
     * Where a value is locked for one scope, and the locked value.
     *
     * Checks the scope itself only; the caller decides how a lock at default
     * propagates. Precedence matches Magento's: environment variable, then
     * env.php, then config.php.
     *
     * @param string      $path      Config path
     * @param string      $scope     'default', 'websites' or 'stores'
     * @param string|null $scopeCode Website or store code
     * @return array{source: string, value: mixed}|null Null when not locked at this scope
     */
    public function getLockedValue(string $path, string $scope, ?string $scopeCode = null): ?array
    {
        $envValue = $this->settingChecker->getPlaceholderValue($path, $scope, $scopeCode);
        if ($envValue !== null) {
            return ['source' => self::SOURCE_ENV_VAR, 'value' => $envValue];
        }

        $keys = $scope === 'default'
            ? ['default', ...explode('/', $path)]
            : [$scope, (string) $scopeCode, ...explode('/', $path)];

        foreach ($this->getSystemSubtrees() as $source => $system) {
            $node = $system;
            foreach ($keys as $key) {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$key];
            }
            if ($node !== null && !is_array($node)) {
                return ['source' => $source, 'value' => $node];
            }
        }

        return null;
    }

    /**
     * Every config path locked in either deployment file under a prefix, at
     * any scope — so a locked setting with no database row is not missed.
     *
     * @param string $pathPrefix e.g. 'tax/taxcloud_settings/'
     * @return string[]
     */
    public function getLockedPaths(string $pathPrefix): array
    {
        $prefixKeys = array_values(array_filter(explode('/', $pathPrefix), static function (string $key): bool {
            return $key !== '';
        }));
        $paths = [];

        foreach ($this->getSystemSubtrees() as $system) {
            $scopeRoots = [];
            if (isset($system['default']) && is_array($system['default'])) {
                $scopeRoots[] = $system['default'];
            }
            foreach (['websites', 'stores'] as $scope) {
                foreach ((array) ($system[$scope] ?? []) as $scoped) {
                    if (is_array($scoped)) {
                        $scopeRoots[] = $scoped;
                    }
                }
            }

            foreach ($scopeRoots as $root) {
                $node = $root;
                foreach ($prefixKeys as $key) {
                    $node = is_array($node) ? ($node[$key] ?? null) : null;
                }
                if (is_array($node)) {
                    foreach ($node as $leaf => $value) {
                        if (!is_array($value)) {
                            $paths[] = implode('/', $prefixKeys) . '/' . $leaf;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * One whitelisted deployment-config value.
     *
     * @param string $key One of DEPLOYMENT_KEY_WHITELIST
     * @return mixed
     * @throws \InvalidArgumentException For any key outside the whitelist
     */
    public function getDeploymentValue(string $key)
    {
        if (!in_array($key, self::DEPLOYMENT_KEY_WHITELIST, true)) {
            throw new \InvalidArgumentException(sprintf('Deployment config key "%s" is not whitelisted', $key));
        }

        $value = $this->deploymentConfig->get($key);

        return is_array($value) ? null : $value;
    }

    /**
     * @return array<string, array>
     */
    private function getSystemSubtrees(): array
    {
        if ($this->systemSubtrees === null) {
            $this->systemSubtrees = [];
            foreach ([
                self::SOURCE_ENV_PHP => ConfigFilePool::APP_ENV,
                self::SOURCE_CONFIG_PHP => ConfigFilePool::APP_CONFIG,
            ] as $source => $fileKey) {
                try {
                    $loaded = $this->deploymentReader->load($fileKey);
                } catch (\Throwable $e) {
                    $loaded = [];
                }
                // Only the system subtree survives this line; the rest of the
                // file (db, crypt, queue...) goes out of scope immediately.
                $this->systemSubtrees[$source] = isset($loaded['system']) && is_array($loaded['system'])
                    ? $loaded['system']
                    : [];
                unset($loaded);
            }
        }

        return $this->systemSubtrees;
    }
}
