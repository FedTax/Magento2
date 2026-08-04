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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\CredentialMigrator;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves the credential migration against the real stack: actual
 * core_config_data SQL (grouping, scope rows, table prefixing), the real
 * TokenExchange + Curl path, and DI construction — with the exchange served
 * by a local PHP built-in server (Test/Integration/_files/rest_auth_stub.php)
 * so no live TaxCloud call ever happens.
 *
 * All config rows go through setScopedConfig() snapshots, so the install is
 * left exactly as found — including rows the migrator itself inserts.
 */
class CredentialMigratorTest extends IntegrationTestCase
{
    private const STUB_PORT = 8099;

    /** @var int|null PID of the stub server */
    private static ?int $stubPid = null;

    private static string $hitLog = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$hitLog = sys_get_temp_dir() . '/tc_stub_hits_' . getmypid() . '.log';
        @unlink(self::$hitLog);

        $stub = __DIR__ . '/../_files/rest_auth_stub.php';
        $cmd = sprintf(
            'TC_STUB_HIT_LOG=%s php -S 127.0.0.1:%d %s >/dev/null 2>&1 & echo $!',
            escapeshellarg(self::$hitLog),
            self::STUB_PORT,
            escapeshellarg($stub)
        );
        // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative
        self::$stubPid = (int) exec($cmd);

        // Wait for the server to accept connections (max ~2s).
        for ($i = 0; $i < 20; $i++) {
            $socket = @fsockopen('127.0.0.1', self::STUB_PORT);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        self::fail('rest_auth_stub.php server did not come up on port ' . self::STUB_PORT);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$stubPid) {
            // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative
            exec('kill ' . self::$stubPid . ' 2>/dev/null');
            self::$stubPid = null;
        }
        @unlink(self::$hitLog);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_REST_AUTH_ENDPOINT, 'http://127.0.0.1:' . self::STUB_PORT);
    }

    public function testMigratesDefaultAndStoreScopesEachAtTheirOwnRow(): void
    {
        $secondStoreId = $this->secondStoreId();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'good-default-id');
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_KEY, 'default-key-uuid');
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'good-store-id', 'stores', $secondStoreId);
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_KEY, 'store-key-uuid', 'stores', $secondStoreId);
        $this->clearRestConnectionIdRows();

        $result = $this->get(CredentialMigrator::class)->migrate();

        $this->assertCount(2, $result['migrated']);
        $this->assertSame(
            'default-key-uuid',
            $this->rawRow(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'default', 0),
            'The default scope must receive its own api_key as connection id.'
        );
        $this->assertSame(
            'store-key-uuid',
            $this->rawRow(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'stores', $secondStoreId),
            'The store scope must receive its own api_key at its own row.'
        );
    }

    public function testAlreadyConfiguredScopeIsSkippedWithoutContactingTheExchange(): void
    {
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'good-default-id');
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_KEY, 'default-key-uuid');
        $this->clearRestConnectionIdRows();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'pre-existing-conn');

        $hitsBefore = $this->stubHits();
        $result = $this->get(CredentialMigrator::class)->migrate();

        $this->assertSame($hitsBefore, $this->stubHits(), 'A skipped scope must cause no exchange call.');
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(
            'pre-existing-conn',
            $this->rawRow(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'default', 0),
            'An existing connection id must stay byte-identical.'
        );
    }

    public function testRejectedPairAbortsNamingTheScopeAndKeepsPriorWrites(): void
    {
        $secondStoreId = $this->secondStoreId();
        // Ordered so 'default' migrates first, then the store scope fails.
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'good-default-id');
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_KEY, 'default-key-uuid');
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_ID, 'bad-store-id', 'stores', $secondStoreId);
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_API_KEY, 'store-key-uuid', 'stores', $secondStoreId);
        $this->clearRestConnectionIdRows();

        try {
            $this->get(CredentialMigrator::class)->migrate();
            $this->fail('Expected LocalizedException for the rejected store-scope pair.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('stores/' . $secondStoreId, $e->getMessage());
            $this->assertStringContainsString('rejected', $e->getMessage());
            $this->assertStringNotContainsString('bad-store-id', $e->getMessage());
            $this->assertStringNotContainsString('store-key-uuid', $e->getMessage());
        }

        $this->assertSame(
            'default-key-uuid',
            $this->rawRow(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'default', 0),
            'The scope validated before the failure must stay migrated.'
        );
        $this->assertNull(
            $this->rawRow(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'stores', $secondStoreId),
            'No partial write may be left for the failing scope.'
        );
    }

    /**
     * Delete every rest_connection_id row via the snapshotting writer so
     * tearDown restores prior state — and rows inserted by the migrator under
     * test are removed again.
     */
    private function clearRestConnectionIdRows(): void
    {
        $connection = $this->get(ResourceConnection::class)->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($connection->getTableName('core_config_data'), ['scope', 'scope_id'])
                ->where('path = ?', TaxcloudConfig::XML_PATH_REST_CONNECTION_ID)
        );
        foreach ($rows as $row) {
            $this->setScopedConfig(
                TaxcloudConfig::XML_PATH_REST_CONNECTION_ID,
                null,
                (string) $row['scope'],
                (int) $row['scope_id']
            );
        }
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, null);
        $this->setScopedConfig(
            TaxcloudConfig::XML_PATH_REST_CONNECTION_ID,
            null,
            'stores',
            $this->secondStoreId()
        );
    }

    private function stubHits(): int
    {
        return self::$hitLog && is_file(self::$hitLog)
            ? count(file(self::$hitLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : 0;
    }

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
