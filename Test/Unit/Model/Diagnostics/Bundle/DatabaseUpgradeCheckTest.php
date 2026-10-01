<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle;

use Magento\Framework\Module\DbVersionInfo;
use Magento\Framework\Setup\Patch\UpToDateData;
use Magento\Framework\Setup\Patch\UpToDateSchema;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\DatabaseUpgradeCheck;

/**
 * Deployed code without setup:upgrade is the most common post-upgrade failure;
 * the bundle has to say so plainly.
 */
#[AllowMockObjectsWithoutExpectations]
class DatabaseUpgradeCheckTest extends TestCase
{
    private function check(array $versionErrors, bool $schemaUpToDate, bool $dataUpToDate): array
    {
        $versions = $this->createMock(DbVersionInfo::class);
        $versions->method('getDbVersionErrors')->willReturn($versionErrors);
        $schema = $this->createMock(UpToDateSchema::class);
        $schema->method('isUpToDate')->willReturn($schemaUpToDate);
        $data = $this->createMock(UpToDateData::class);
        $data->method('isUpToDate')->willReturn($dataUpToDate);

        return (new DatabaseUpgradeCheck($versions, $schema, $data))->check();
    }

    public function testUpToDate()
    {
        $result = $this->check([], true, true);

        $this->assertTrue($result['up_to_date']);
        $this->assertSame([], $result['module_versions']);
        $this->assertFalse($result['schema_patches_pending']);
        $this->assertFalse($result['data_patches_pending']);
    }

    public function testReportsModulesWhoseDatabaseVersionLagsTheCode()
    {
        $result = $this->check([[
            DbVersionInfo::KEY_MODULE => 'Taxcloud_Magento2',
            DbVersionInfo::KEY_TYPE => 'schema',
            DbVersionInfo::KEY_CURRENT => '1.4.0',
            DbVersionInfo::KEY_REQUIRED => '1.5.0',
        ]], true, true);

        $this->assertFalse($result['up_to_date']);
        $this->assertSame(
            [['module' => 'Taxcloud_Magento2', 'type' => 'schema', 'database' => '1.4.0', 'code' => '1.5.0']],
            $result['module_versions']
        );
    }

    public function testPendingPatchesMakeItNotUpToDate()
    {
        $this->assertFalse($this->check([], true, false)['up_to_date']);
        $this->assertTrue($this->check([], true, false)['data_patches_pending']);
        $this->assertFalse($this->check([], false, true)['up_to_date']);
    }

    public function testAFailingValidatorIsReportedNotThrown()
    {
        $versions = $this->createMock(DbVersionInfo::class);
        $versions->method('getDbVersionErrors')->willReturn([]);
        $schema = $this->createMock(UpToDateSchema::class);
        $schema->method('isUpToDate')->willThrowException(new \RuntimeException('patch reader failed'));
        $data = $this->createMock(UpToDateData::class);
        $data->method('isUpToDate')->willReturn(true);

        $result = (new DatabaseUpgradeCheck($versions, $schema, $data))->check();

        $this->assertNull($result['schema_patches_pending']);
        $this->assertSame('patch reader failed', $result['pending']['schema']['error']);
        $this->assertTrue($result['up_to_date'], 'an unknown result is not reported as a pending upgrade');
    }
}
