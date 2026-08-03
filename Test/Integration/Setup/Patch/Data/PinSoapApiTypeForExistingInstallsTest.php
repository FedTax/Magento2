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

namespace Taxcloud\Magento2\Test\Integration\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Setup\Patch\Data\PinSoapApiTypeForExistingInstalls;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves the upgrade-defaulting patch against the real core_config_data:
 * a saved api_id (any scope) pins api_type=soap at default scope; a fresh
 * install and an already-made admin choice are both left untouched.
 *
 * The unit test drives the same logic through mocks; this one catches what
 * mocks can't — the actual SQL (quoting, table prefixing, the blank-value
 * predicate) running against MariaDB via the real ModuleDataSetup connection.
 *
 * All config rows this test touches go through setScopedConfig(), whose
 * snapshot/restore guarantees the install is left exactly as found — including
 * deleting the row the patch itself inserts.
 */
class PinSoapApiTypeForExistingInstallsTest extends IntegrationTestCase
{
    public function testPinsSoapAtDefaultScopeWhenApiIdExistsAndApiTypeDoesNot(): void
    {
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'integration-api-id');
        $this->clearApiTypeEverywhere();

        $this->get(PinSoapApiTypeForExistingInstalls::class)->apply();

        $this->assertSame(
            ApiType::SOAP,
            $this->rawRow(TaxcloudConfig::XML_PATH_API_TYPE, 'default', 0),
            'The patch must pin api_type=soap at default scope for an install with saved V1 credentials.'
        );
    }

    /**
     * "Any scope" means store-scope credentials count too: a merchant whose
     * api_id lives only on a store view is still an existing SOAP integration.
     */
    public function testStoreScopedApiIdAloneIsEnoughToPin(): void
    {
        $this->clearApiIdEverywhere();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'store-only-id', 'stores', $this->secondStoreId());
        $this->clearApiTypeEverywhere();

        $this->get(PinSoapApiTypeForExistingInstalls::class)->apply();

        $this->assertSame(
            ApiType::SOAP,
            $this->rawRow(TaxcloudConfig::XML_PATH_API_TYPE, 'default', 0),
            'A store-scoped api_id alone must pin api_type at default scope.'
        );
    }

    public function testFreshInstallIsLeftOnTheConfigXmlRestDefault(): void
    {
        $this->clearApiIdEverywhere();
        $this->clearApiTypeEverywhere();

        $this->get(PinSoapApiTypeForExistingInstalls::class)->apply();

        $this->assertNull(
            $this->rawRow(TaxcloudConfig::XML_PATH_API_TYPE, 'default', 0),
            'With no saved api_id the patch must write nothing — the config.xml rest default applies.'
        );
    }

    /**
     * A blank api_id row (saved once, then cleared) is not an existing
     * integration — the patch's SQL predicate must skip it.
     */
    public function testBlankApiIdRowDoesNotPin(): void
    {
        $this->clearApiIdEverywhere();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, '');
        $this->clearApiTypeEverywhere();

        $this->get(PinSoapApiTypeForExistingInstalls::class)->apply();

        $this->assertNull(
            $this->rawRow(TaxcloudConfig::XML_PATH_API_TYPE, 'default', 0),
            'A blank api_id row must not count as an existing SOAP integration.'
        );
    }

    public function testAnAlreadySavedApiTypeIsNeverOverwritten(): void
    {
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'integration-api-id');
        $this->clearApiTypeEverywhere();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_TYPE, ApiType::REST);

        $this->get(PinSoapApiTypeForExistingInstalls::class)->apply();

        $this->assertSame(
            ApiType::REST,
            $this->rawRow(TaxcloudConfig::XML_PATH_API_TYPE, 'default', 0),
            'An explicit admin choice must survive the patch re-running.'
        );
    }

    /**
     * Delete every api_id row, whatever scope it lives in, via the
     * snapshotting writer so tearDown puts each one back.
     */
    private function clearApiIdEverywhere(): void
    {
        $this->clearPathEverywhere(TaxcloudConfig::XML_PATH_API_ID);
    }

    private function clearApiTypeEverywhere(): void
    {
        $this->clearPathEverywhere(TaxcloudConfig::XML_PATH_API_TYPE);
    }

    private function clearPathEverywhere(string $path): void
    {
        $connection = $this->get(ResourceConnection::class)->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($connection->getTableName('core_config_data'), ['scope', 'scope_id'])
                ->where('path = ?', $path)
        );
        foreach ($rows as $row) {
            $this->setScopedConfig($path, null, (string) $row['scope'], (int) $row['scope_id']);
        }
        // Snapshot the default row even when absent, so a row the patch inserts
        // during the test is deleted again by tearDown.
        $this->setScopedConfig($path, null);
    }

    /**
     * The raw value at exactly this scope row (no config fallback), or null.
     */
    private function rawRow(string $path, string $scopeType, int $scopeId): ?string
    {
        $connection = $this->get(ResourceConnection::class)->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('core_config_data'), ['value'])
                ->where('scope = ?', $scopeType)
                ->where('scope_id = ?', $scopeId)
                ->where('path = ?', $path)
        );

        return $value === false ? null : (string) $value;
    }
}
