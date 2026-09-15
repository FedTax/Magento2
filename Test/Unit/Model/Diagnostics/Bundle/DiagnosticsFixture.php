<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Shared fixture for the diagnostics tests: a two-website, three-store install
 * whose configuration is a plain [scope][code][path] => value map resolved the
 * way Magento resolves it (store → website → default).
 */
trait DiagnosticsFixture
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private $configMap = ['default' => []];

    /**
     * Store code => [id, website code, name].
     */
    private static $storeLayout = [
        'us_en' => [1, 'us', 'US English'],
        'us_es' => [2, 'us', 'US Spanish'],
        'ca_en' => [3, 'ca', 'Canada'],
    ];

    /**
     * Website code => id.
     */
    private static $websiteLayout = ['us' => 1, 'ca' => 2];

    /**
     * @param array $default
     * @param array $websites code => [path => value]
     * @param array $stores   code => [path => value]
     * @return void
     */
    private function setConfig(array $default, array $websites = [], array $stores = []): void
    {
        $this->configMap = ['default' => $default, 'websites' => $websites, 'stores' => $stores];
    }

    /**
     * @return StoreInterface[] keyed by id
     */
    private function stores(): array
    {
        $stores = [];
        foreach (self::$storeLayout as $code => [$id, $website, $name]) {
            $store = $this->createMock(\Magento\Store\Model\Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $store->method('getName')->willReturn($name);
            $store->method('getWebsiteId')->willReturn(self::$websiteLayout[$website]);
            $stores[$id] = $store;
        }

        return $stores;
    }

    /**
     * @return WebsiteInterface[] keyed by id
     */
    private function websites(): array
    {
        $websites = [];
        foreach (self::$websiteLayout as $code => $id) {
            $website = $this->createMock(\Magento\Store\Model\Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getCode')->willReturn($code);
            $websites[$id] = $website;
        }

        return $websites;
    }

    /**
     * @return StoreManagerInterface
     */
    private function storeManager(): StoreManagerInterface
    {
        $stores = $this->stores();
        $websites = $this->websites();
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getStore')->willReturnCallback(function ($id) use ($stores) {
            return $stores[(int) $id];
        });
        $storeManager->method('getWebsite')->willReturnCallback(function ($id) use ($websites) {
            return $websites[(int) $id];
        });

        return $storeManager;
    }

    /**
     * Resolve a path the way Magento's scope config does.
     *
     * @param string          $path
     * @param string          $scope
     * @param int|string|null $scopeId
     * @return mixed
     */
    private function resolveConfig(string $path, string $scope, $scopeId)
    {
        $default = $this->configMap['default'] ?? [];
        $subtree = function (array $values) use ($path) {
            $prefix = $path . '/';
            $out = [];
            foreach ($values as $key => $value) {
                if (strpos($key, $prefix) === 0) {
                    $out[substr($key, strlen($prefix))] = $value;
                }
            }
            return $out;
        };

        $layers = [$default];
        if ($scope === 'store' || $scope === 'stores') {
            foreach (self::$storeLayout as $code => [$id, $websiteCode]) {
                if ((string) $id === (string) $scopeId || $code === $scopeId) {
                    $layers[] = $this->configMap['websites'][$websiteCode] ?? [];
                    $layers[] = $this->configMap['stores'][$code] ?? [];
                }
            }
        } elseif ($scope === 'website' || $scope === 'websites') {
            foreach (self::$websiteLayout as $code => $id) {
                if ((string) $id === (string) $scopeId || $code === $scopeId) {
                    $layers[] = $this->configMap['websites'][$code] ?? [];
                }
            }
        }

        $value = null;
        $tree = [];
        foreach ($layers as $layer) {
            if (array_key_exists($path, $layer)) {
                $value = $layer[$path];
            }
            $tree = array_merge($tree, $subtree($layer));
        }

        return $value ?? ($tree !== [] ? $tree : null);
    }

    /**
     * @return ScopeConfigInterface
     */
    private function scopeConfig(): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function ($path, $scope = 'default', $scopeId = null) {
            return $this->resolveConfig((string) $path, (string) $scope, $scopeId);
        });
        $scopeConfig->method('isSetFlag')->willReturnCallback(function ($path, $scope = 'default', $scopeId = null) {
            return (bool) $this->resolveConfig((string) $path, (string) $scope, $scopeId);
        });

        return $scopeConfig;
    }

    /**
     * core_config_data rows implied by the config map.
     *
     * @return array
     */
    private function databaseRows(): array
    {
        $rows = [];
        foreach ($this->configMap['default'] ?? [] as $path => $value) {
            $rows[] = ['scope' => 'default', 'scope_id' => 0, 'path' => $path, 'value' => $value];
        }
        foreach ($this->configMap['websites'] ?? [] as $code => $values) {
            foreach ($values as $path => $value) {
                $rows[] = ['scope' => 'websites', 'scope_id' => self::$websiteLayout[$code], 'path' => $path,
                    'value' => $value];
            }
        }
        foreach ($this->configMap['stores'] ?? [] as $code => $values) {
            foreach ($values as $path => $value) {
                $rows[] = ['scope' => 'stores', 'scope_id' => self::$storeLayout[$code][0], 'path' => $path,
                    'value' => $value];
            }
        }

        return array_values(array_filter($rows, static function ($row) {
            return strpos($row['path'], 'tax/taxcloud_settings/') === 0;
        }));
    }

    /**
     * @param string $dir
     * @return void
     */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir)) {
                unlink($dir);
            }
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
