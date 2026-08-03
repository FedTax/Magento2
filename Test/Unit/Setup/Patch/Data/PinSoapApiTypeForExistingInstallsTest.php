<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Setup\Patch\Data\PinSoapApiTypeForExistingInstalls;

/**
 * Upgrade-defaulting contract: a saved api_id marks an existing SOAP
 * integration, and only those installs get api_type pinned to soap — fresh
 * installs keep the config.xml REST default, and a saved admin choice is
 * never overwritten.
 */
#[AllowMockObjectsWithoutExpectations]
class PinSoapApiTypeForExistingInstallsTest extends TestCase
{
    public function testHasNoDependenciesOrAliases()
    {
        $this->assertSame([], PinSoapApiTypeForExistingInstalls::getDependencies());

        [$patch] = $this->buildPatch([false]);
        $this->assertSame([], $patch->getAliases());
    }

    /**
     * The upgrade path: api_id saved, api_type never chosen → pin soap at the
     * default scope so every scope without an override keeps legacy behavior.
     */
    public function testPinsSoapAtDefaultScopeWhenApiIdExistsAndApiTypeDoesNot()
    {
        [$patch, $connection] = $this->buildPatch(['42', false]);

        $connection->expects($this->once())
            ->method('insert')
            ->with('prefixed_core_config_data', [
                'scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                'scope_id' => 0,
                'path' => TaxcloudConfig::XML_PATH_API_TYPE,
                'value' => ApiType::SOAP,
            ]);

        $this->assertSame($patch, $patch->apply());
    }

    /**
     * Fresh install: no api_id anywhere → nothing is written, the config.xml
     * REST default applies.
     */
    public function testDoesNothingOnFreshInstall()
    {
        [$patch, $connection] = $this->buildPatch([false]);

        $connection->expects($this->never())->method('insert');

        $patch->apply();
    }

    /**
     * Idempotency: an existing api_type row — patch-written or admin-saved,
     * any value — must never be overwritten or duplicated on a re-run.
     */
    public function testDoesNothingWhenApiTypeAlreadySaved()
    {
        [$patch, $connection] = $this->buildPatch(['42', '77']);

        $connection->expects($this->never())->method('insert');

        $patch->apply();
    }

    /**
     * The api_id probe must ignore blank rows: a saved-then-cleared credential
     * is not an existing SOAP integration. The patch expresses this as a SQL
     * predicate, so pin the predicate itself.
     */
    public function testApiIdProbeExcludesBlankValues()
    {
        $wheres = [];
        [$patch] = $this->buildPatch([false], $wheres);

        $patch->apply();

        $this->assertContains("value IS NOT NULL AND value != ''", $wheres);
    }

    /**
     * @param array $fetchOneResults consecutive fetchOne() returns
     * @param array $wheres collects where() predicates when passed
     * @return array{0: PinSoapApiTypeForExistingInstalls, 1: AdapterInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function buildPatch(array $fetchOneResults, array &$wheres = []): array
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond) use (&$wheres, $select) {
            $wheres[] = $cond;
            return $select;
        });
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(...$fetchOneResults);

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->with('core_config_data')->willReturn('prefixed_core_config_data');

        return [new PinSoapApiTypeForExistingInstalls($moduleDataSetup), $connection];
    }
}
