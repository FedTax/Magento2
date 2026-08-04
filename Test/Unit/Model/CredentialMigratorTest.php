<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\CredentialMigrator;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;

/**
 * The migrator's contract: every scope with its own V1 pair gets a validated
 * rest_connection_id at that scope; existing values are skipped untouched;
 * failures abort loudly, naming the scope, with prior writes kept.
 */
#[AllowMockObjectsWithoutExpectations]
class CredentialMigratorTest extends TestCase
{
    /**
     * @var TokenExchange&\PHPUnit\Framework\MockObject\MockObject
     */
    private $exchange;

    /**
     * @var array<int, array> insert() rows captured
     */
    private $inserts = [];

    private function migrator(array $rows): CredentialMigrator
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $this->inserts = [];
        $connection->method('insert')->willReturnCallback(function ($table, $row) {
            $this->inserts[] = $row;
            return 1;
        });

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->exchange = $this->createMock(TokenExchange::class);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getCode')->willReturn('second');
        $storeManager->method('getStore')->willReturn($store);

        return new CredentialMigrator($resource, $this->exchange, $storeManager);
    }

    private static function row(string $scope, int $scopeId, string $path, string $value): array
    {
        return ['scope' => $scope, 'scope_id' => $scopeId, 'path' => $path, 'value' => $value];
    }

    public function testMigratesEveryScopeWithItsOwnPairAtItsOwnScope()
    {
        $migrator = $this->migrator([
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_ID, 'default-id'),
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_KEY, 'default-key'),
            self::row('stores', 3, TaxcloudConfig::XML_PATH_API_ID, 'store-id'),
            self::row('stores', 3, TaxcloudConfig::XML_PATH_API_KEY, 'store-key'),
        ]);

        $exchanged = [];
        $this->exchange->method('exchange')->willReturnCallback(
            function ($id, $key, $store) use (&$exchanged) {
                $exchanged[] = [$id, $key, $store];
                return new BearerToken('jwt', time() + 3600);
            }
        );

        $result = $migrator->migrate();

        $this->assertCount(2, $result['migrated']);
        $this->assertSame([
            ['default-id', 'default-key', null],
            ['store-id', 'store-key', 3],
        ], $exchanged, 'store rows must resolve exchange config for their own store');
        $this->assertSame([
            [
                'scope' => 'default', 'scope_id' => 0,
                'path' => TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'value' => 'default-key',
            ],
            [
                'scope' => 'stores', 'scope_id' => 3,
                'path' => TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'value' => 'store-key',
            ],
        ], $this->inserts);
    }

    public function testScopeWithExistingRestConnectionIdIsSkippedWithoutValidation()
    {
        $migrator = $this->migrator([
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_ID, 'default-id'),
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_KEY, 'default-key'),
            self::row('default', 0, TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'already-set'),
        ]);
        $this->exchange->expects($this->never())->method('exchange');

        $result = $migrator->migrate();

        $this->assertSame([], $this->inserts);
        $this->assertCount(1, $result['skipped']);
        $this->assertCount(0, $result['migrated']);
    }

    public function testBlankOrHalfPairsAreIgnored()
    {
        $migrator = $this->migrator([
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_ID, '  '),
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_KEY, 'orphan-key'),
            self::row('websites', 2, TaxcloudConfig::XML_PATH_API_ID, 'id-without-key'),
        ]);
        $this->exchange->expects($this->never())->method('exchange');

        $result = $migrator->migrate();

        $this->assertSame([], $this->inserts);
        $this->assertSame(['migrated' => [], 'skipped' => []], $result);
    }

    public function testRejectedPairAbortsNamingTheScopeAndKeepsPriorWrites()
    {
        $migrator = $this->migrator([
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_ID, 'good-id'),
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_KEY, 'good-key'),
            self::row('stores', 3, TaxcloudConfig::XML_PATH_API_ID, 'bad-id'),
            self::row('stores', 3, TaxcloudConfig::XML_PATH_API_KEY, 'bad-key'),
        ]);

        $this->exchange->method('exchange')->willReturnCallback(function ($id) {
            if ($id === 'bad-id') {
                throw new TokenExchangeException(TokenExchangeException::REJECTED, 'rejected (HTTP 400)');
            }
            return new BearerToken('jwt', time() + 3600);
        });

        try {
            $migrator->migrate();
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $message = (string) $e->getMessage();
            $this->assertStringContainsString("store view 'second' (stores/3)", $message);
            $this->assertStringContainsString('rejected', $message);
            $this->assertStringNotContainsString('bad-id', $message);
            $this->assertStringNotContainsString('bad-key', $message);
        }

        $this->assertCount(1, $this->inserts, 'the scope validated before the failure stays migrated');
        $this->assertSame('default', $this->inserts[0]['scope']);
    }

    public function testUnreachableExchangeAbortsWithDistinctActionableMessage()
    {
        $migrator = $this->migrator([
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_ID, 'an-id'),
            self::row('default', 0, TaxcloudConfig::XML_PATH_API_KEY, 'a-key'),
        ]);
        $this->exchange->method('exchange')->willThrowException(
            new TokenExchangeException(TokenExchangeException::UNREACHABLE, 'could not be reached')
        );

        try {
            $migrator->migrate();
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $message = (string) $e->getMessage();
            $this->assertStringContainsString('could not be reached', $message);
            $this->assertStringContainsString('TAXCLOUD_SKIP_CREDENTIAL_MIGRATION', $message);
            $this->assertStringContainsString('default scope (default/0)', $message);
        }

        $this->assertSame([], $this->inserts);
    }
}
