<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Setup\Patch\Data\AddCategoryTicAttribute;
use Taxcloud\Magento2\Setup\Patch\Data\InstallTaxcloudData;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * The setup patch behind category-level TICs. Driven through mocks: what matters
 * here is the attribute's identity and scope, which is what the resolver and the
 * admin form both depend on.
 */
#[AllowMockObjectsWithoutExpectations]
class AddCategoryTicAttributeTest extends TestCase
{
    public function testRunsAfterTheProductAttributeInstaller()
    {
        $this->assertSame([InstallTaxcloudData::class], AddCategoryTicAttribute::getDependencies());
    }

    public function testGetAliasesIsEmpty()
    {
        [$patch] = $this->buildPatch($this->createMock(Dbl\EavSetupDouble::class));

        $this->assertSame([], $patch->getAliases());
    }

    /**
     * The attribute must land on catalog_category, under the TaxCloud code the
     * resolver reads, at STORE scope — anything else silently breaks either the
     * lookup or the per-store-view override.
     */
    public function testApplyAddsAStoreScopedCategoryAttribute()
    {
        $eavSetup = $this->getMockBuilder(Dbl\EavSetupDouble::class)
            ->onlyMethods(['addAttribute'])
            ->getMock();

        $captured = null;
        $eavSetup->expects($this->once())
            ->method('addAttribute')
            ->with(Category::ENTITY, AddCategoryTicAttribute::ATTRIBUTE_CODE, $this->anything())
            ->willReturnCallback(static function ($entityType, $code, $data) use (&$captured) {
                $captured = $data;
            });

        [$patch] = $this->buildPatch($eavSetup);

        $patch->apply();

        $this->assertIsArray($captured);
        $this->assertSame('varchar', $captured['type']);
        $this->assertSame('text', $captured['input']);
        $this->assertFalse($captured['required']);
        $this->assertSame(
            ScopedAttributeInterface::SCOPE_STORE,
            $captured['global'],
            'The category TIC must be overridable per store view.'
        );
    }

    /**
     * revert() has to leave the catalog clean when the module is uninstalled.
     */
    public function testRevertRemovesTheCategoryAttribute()
    {
        $eavSetup = $this->getMockBuilder(Dbl\EavSetupDouble::class)
            ->onlyMethods(['removeAttribute'])
            ->getMock();
        $eavSetup->expects($this->once())
            ->method('removeAttribute')
            ->with(Category::ENTITY, AddCategoryTicAttribute::ATTRIBUTE_CODE);

        [$patch, $connection] = $this->buildPatch($eavSetup);
        $connection->expects($this->once())->method('startSetup')->willReturnSelf();
        $connection->expects($this->once())->method('endSetup')->willReturnSelf();

        $patch->revert();
    }

    /**
     * @return array{0: AddCategoryTicAttribute, 1: AdapterInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function buildPatch($eavSetup): array
    {
        $eavSetupFactory = $this->createMock(EavSetupFactory::class);
        $eavSetupFactory->method('create')->willReturn($eavSetup);

        $connection = $this->createMock(AdapterInterface::class);
        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);

        return [new AddCategoryTicAttribute($moduleDataSetup, $eavSetupFactory), $connection];
    }
}
