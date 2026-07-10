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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that credentials written to core_config_data (the path the admin UI —
 * and scripts/seed-test-data.php — write to) are the ones TaxcloudConfig::getApiId()
 * and TaxcloudConfig::getApiKey() read back. Almost tautological, but it is the
 * smoke test for "did the install's config writes actually land where the API
 * reads them".
 */
class ConfigInjectionTest extends IntegrationTestCase
{
    private const TEST_API_ID  = 'IT_API_ID_12345';
    private const TEST_API_KEY = 'IT_API_KEY_ABCDE_67890';

    /** @var array<string, string|null> original values to restore */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $scopeConfig = $this->get(ScopeConfigInterface::class);
        foreach (['tax/taxcloud_settings/api_id', 'tax/taxcloud_settings/api_key'] as $path) {
            $this->originalConfig[$path] = $scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        }
    }

    protected function tearDown(): void
    {
        // Restore the seeded credentials so later tests run against the real env.
        foreach ($this->originalConfig as $path => $value) {
            $this->writeConfig($path, (string) $value);
        }
        parent::tearDown();
    }

    public function testTaxcloudCredentialsAreReadFromCoreConfigData(): void
    {
        $this->writeConfig('tax/taxcloud_settings/api_id', self::TEST_API_ID);
        $this->writeConfig('tax/taxcloud_settings/api_key', self::TEST_API_KEY);

        // Credential reading now lives in TaxcloudConfig (the reader every gateway
        // collaborator depends on), so assert against it directly.
        $config = $this->get(TaxcloudConfig::class);

        $this->assertSame(
            self::TEST_API_ID,
            $config->getApiId(),
            'TaxcloudConfig::getApiId() should read tax/taxcloud_settings/api_id from core_config_data.'
        );
        $this->assertSame(
            self::TEST_API_KEY,
            $config->getApiKey(),
            'TaxcloudConfig::getApiKey() should read tax/taxcloud_settings/api_key from core_config_data.'
        );
    }
}
