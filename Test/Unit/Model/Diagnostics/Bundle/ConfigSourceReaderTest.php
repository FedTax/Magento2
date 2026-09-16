<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Reader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Config\File\ConfigFilePool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ConfigSourceReader;

/**
 * app/etc/env.php holds the crypt key and database password. Only the locked
 * store-config subtree and a hardcoded whitelist of keys may be read from it.
 */
#[AllowMockObjectsWithoutExpectations]
class ConfigSourceReaderTest extends TestCase
{
    private const CRYPT_KEY = 'SENTINEL_CRYPT_KEY_DO_NOT_LEAK';
    private const DB_PASSWORD = 'SENTINEL_DB_PASSWORD_DO_NOT_LEAK';

    private function reader(array $env, array $config = [], ?string $envVar = null, ?DeploymentConfig $deployment = null)
    {
        $deploymentReader = $this->createMock(Reader::class);
        $deploymentReader->method('load')->willReturnCallback(function ($fileKey) use ($env, $config) {
            return $fileKey === ConfigFilePool::APP_ENV ? $env : $config;
        });
        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('getPlaceholderValue')->willReturn($envVar);

        return new ConfigSourceReader(
            $this->createMock(ResourceConnection::class),
            $deploymentReader,
            $deployment ?? $this->createMock(DeploymentConfig::class),
            $settingChecker
        );
    }

    private function envPhp(array $system = []): array
    {
        return [
            'crypt' => ['key' => self::CRYPT_KEY],
            'db' => ['connection' => ['default' => ['password' => self::DB_PASSWORD]]],
            'system' => $system,
        ];
    }

    public function testLockedValuePrecedenceIsEnvVarThenEnvPhpThenConfigPhp()
    {
        $path = 'tax/taxcloud_settings/logging';
        $env = $this->envPhp(['default' => ['tax' => ['taxcloud_settings' => ['logging' => '2']]]]);
        $config = ['system' => ['default' => ['tax' => ['taxcloud_settings' => ['logging' => '0']]]]];

        $this->assertSame(
            ['source' => ConfigSourceReader::SOURCE_ENV_VAR, 'value' => '1'],
            $this->reader($env, $config, '1')->getLockedValue($path, 'default')
        );
        $this->assertSame(
            ['source' => ConfigSourceReader::SOURCE_ENV_PHP, 'value' => '2'],
            $this->reader($env, $config)->getLockedValue($path, 'default')
        );
        $this->assertSame(
            ['source' => ConfigSourceReader::SOURCE_CONFIG_PHP, 'value' => '0'],
            $this->reader($this->envPhp(), $config)->getLockedValue($path, 'default')
        );
        $this->assertNull($this->reader($this->envPhp())->getLockedValue($path, 'default'));
    }

    public function testLockedValuesAreScopedByCode()
    {
        $env = $this->envPhp(['stores' => ['uk' => ['tax' => ['taxcloud_settings' => ['enabled' => '0']]]]]);
        $reader = $this->reader($env);

        $this->assertSame('0', $reader->getLockedValue('tax/taxcloud_settings/enabled', 'stores', 'uk')['value']);
        $this->assertNull($reader->getLockedValue('tax/taxcloud_settings/enabled', 'stores', 'us'));
        $this->assertNull($reader->getLockedValue('tax/taxcloud_settings/enabled', 'default'));
        $this->assertSame(['tax/taxcloud_settings/enabled'], $reader->getLockedPaths('tax/taxcloud_settings/'));
    }

    public function testNothingOutsideTheSystemSubtreeCanBeReachedThroughLockedPaths()
    {
        $reader = $this->reader($this->envPhp());

        $this->assertNull($reader->getLockedValue('crypt/key', 'default'));
        $this->assertNull($reader->getLockedValue('connection/default/password', 'db', ''));
        $this->assertSame([], $reader->getLockedPaths('crypt/'));
    }

    public function testDeploymentValuesAreWhitelisted()
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(function ($key) {
            return [
                'session/save' => 'redis',
                'crypt/key' => self::CRYPT_KEY,
                'db/connection/default/password' => self::DB_PASSWORD,
            ][$key] ?? null;
        });
        $reader = $this->reader($this->envPhp(), [], null, $deployment);

        $this->assertSame('redis', $reader->getDeploymentValue('session/save'));

        foreach (['crypt/key', 'db/connection/default/password', 'db', 'crypt', ''] as $key) {
            try {
                $reader->getDeploymentValue($key);
                $this->fail('Non-whitelisted key "' . $key . '" was readable');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::CRYPT_KEY, $e->getMessage());
            }
        }
    }

    public function testTheWhitelistNamesNoSecretBearingKey()
    {
        foreach (ConfigSourceReader::DEPLOYMENT_KEY_WHITELIST as $key) {
            $this->assertDoesNotMatchRegularExpression('/crypt|db|password|key\b|host|user/i', $key);
        }
    }
}
