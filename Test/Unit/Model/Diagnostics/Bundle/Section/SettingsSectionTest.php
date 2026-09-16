<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Section;

use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleScope;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ConfigSourceReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialFingerprint;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialInventory;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\SettingsSection;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\DiagnosticsFixture;

/**
 * Provenance is what turns "I changed the setting and nothing happened" into a
 * one-line answer: every scope's value, whether it is inherited or set there,
 * and whether a deployment file locks it.
 */
#[AllowMockObjectsWithoutExpectations]
class SettingsSectionTest extends TestCase
{
    use DiagnosticsFixture;

    private function collect(array $locks = []): array
    {
        $scopeConfig = $this->scopeConfig();
        $sourceReader = $this->createMock(ConfigSourceReader::class);
        $sourceReader->method('getDatabaseRows')->willReturn($this->databaseRows());
        $sourceReader->method('getLockedPaths')->willReturn(array_keys($locks));
        $sourceReader->method('getLockedValue')->willReturnCallback(
            function ($path, $scope, $code = null) use ($locks) {
                $label = $scope === 'default' ? 'default' : $scope . '/' . $code;
                return $locks[$path][$label] ?? null;
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(function ($value) {
            return str_replace('0:3:', 'plain-', $value);
        });

        $inventory = new CredentialInventory(
            $sourceReader,
            $scopeConfig,
            $this->storeManager(),
            $encryptor,
            new TaxcloudConfig($scopeConfig, $encryptor),
            $this->createMock(TokenCache::class)
        );
        $section = new SettingsSection($scopeConfig, $sourceReader, $inventory, new CredentialFingerprint());

        $scope = new BundleScope(BundleRequest::SCOPE_DEFAULT, null, 'default', $this->stores(), $this->websites());
        $context = new BundleContext(new BundleRequest(), $scope, null, sys_get_temp_dir(), [], null);
        $archive = $this->createMock(BundleArchive::class);

        return $section->collect($context, $archive);
    }

    public function testRecordsEveryScopeWithInheritanceAndTheEffectiveValuePerStore()
    {
        $this->setConfig(
            ['tax/taxcloud_settings/enabled' => '1', 'tax/taxcloud_settings/logging' => '1'],
            ['ca' => ['tax/taxcloud_settings/enabled' => '0']],
            ['us_es' => ['tax/taxcloud_settings/logging' => '2']]
        );

        $settings = $this->collect()['settings'];

        $enabled = $settings['enabled'];
        $this->assertTrue($enabled['scopes']['default']['explicit']);
        $this->assertSame('database', $enabled['scopes']['default']['source']);
        $this->assertFalse($enabled['scopes']['websites/us']['explicit']);
        $this->assertTrue($enabled['scopes']['websites/ca']['explicit']);
        $this->assertSame('0', $enabled['scopes']['websites/ca']['value']);
        $this->assertSame('1', $enabled['effective']['us_en']['value']);
        $this->assertSame('default', $enabled['effective']['us_en']['resolved_from']);
        $this->assertSame('0', $enabled['effective']['ca_en']['value']);
        $this->assertSame('websites/ca', $enabled['effective']['ca_en']['resolved_from']);

        $logging = $settings['logging'];
        $this->assertTrue($logging['scopes']['stores/us_es']['explicit']);
        $this->assertSame('2', $logging['effective']['us_es']['value']);
        $this->assertSame('stores/us_es', $logging['effective']['us_es']['resolved_from']);
        $this->assertSame('1', $logging['effective']['us_en']['value']);
    }

    public function testEveryKnownSettingIsListedEvenWhenUnset()
    {
        $this->setConfig([]);

        $settings = $this->collect()['settings'];

        foreach ((new \ReflectionClass(TaxcloudConfig::class))->getConstants() as $name => $value) {
            if (strpos($name, 'XML_PATH_') === 0 && is_string($value) && strpos($value, 'tax/taxcloud_settings/') === 0) {
                $this->assertArrayHasKey(substr($value, strlen('tax/taxcloud_settings/')), $settings);
            }
        }
        $this->assertFalse($settings['api_type']['scopes']['default']['explicit']);
    }

    public function testLockedValuesAreReportedWithTheirSourceAndPropagate()
    {
        $this->setConfig(['tax/taxcloud_settings/logging' => '1']);

        $data = $this->collect([
            'tax/taxcloud_settings/logging' => [
                'default' => ['source' => ConfigSourceReader::SOURCE_ENV_PHP, 'value' => '0'],
            ],
            'tax/taxcloud_settings/verify_address' => [
                'stores/ca_en' => ['source' => ConfigSourceReader::SOURCE_CONFIG_PHP, 'value' => '0'],
            ],
        ]);

        $logging = $data['settings']['logging'];
        $this->assertTrue($logging['scopes']['default']['locked']);
        $this->assertSame('app/etc/env.php', $logging['scopes']['default']['source']);
        $this->assertSame('0', $logging['scopes']['default']['value']);
        $this->assertTrue($logging['effective']['us_en']['locked'], 'a default-scope lock applies to every store');

        $verify = $data['settings']['verify_address'];
        $this->assertTrue($verify['effective']['ca_en']['locked']);
        $this->assertFalse($verify['effective']['us_en']['locked']);

        $this->assertContains(
            ['setting' => 'verify_address', 'scope' => 'stores/ca_en', 'locked_by' => 'app/etc/config.php'],
            $data['locked']
        );
    }

    public function testCredentialsAreFingerprintedAtEveryScopeAndDecryptedFirst()
    {
        $this->setConfig(
            ['tax/taxcloud_settings/api_key' => 'SENTINEL-DEFAULT-KEY-0001'],
            ['us' => ['tax/taxcloud_settings/rest_api_key' => '0:3:SENTINEL-REST-KEY']],
            ['ca_en' => ['tax/taxcloud_settings/api_key' => "SENTINEL-CA-KEY-0002 "]]
        );

        $data = $this->collect();
        $json = (string) json_encode($data);

        $this->assertStringNotContainsString('SENTINEL', $json);

        $apiKey = $data['settings']['api_key'];
        $this->assertTrue($apiKey['credential']);
        $this->assertArrayNotHasKey('value', $apiKey['scopes']['default']);
        $this->assertSame(25, $apiKey['scopes']['default']['fingerprint']['length']);
        $this->assertTrue($apiKey['effective']['ca_en']['fingerprint']['trailing_whitespace']);

        $rest = $data['settings']['rest_api_key']['scopes']['websites/us']['fingerprint'];
        $this->assertSame(
            substr(hash('sha256', 'plain-SENTINEL-REST-KEY'), 0, 12),
            $rest['sha256_prefix'],
            'the fingerprint describes the decrypted key, not the ciphertext'
        );
    }
}
