<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Setup\Patch\Data;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Setup\Patch\Data\InstallTaxcloudData;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Customer;

/**
 * Section A3: cover the InstallTaxcloudData setup patch. apply() registers two EAV
 * attributes (taxcloud_tic on Product, taxcloud_cert on Customer); we drive it through
 * mocks and assert the orchestration without touching a database.
 */
class InstallTaxcloudDataTest extends TestCase
{
    /**
     * A3.1: getDependencies is a static contract method on DataPatchInterface — empty.
     */
    public function testGetDependenciesIsEmpty()
    {
        $this->assertSame([], InstallTaxcloudData::getDependencies());
    }

    /**
     * A3.2: getAliases — empty list, no rename history.
     */
    public function testGetAliasesIsEmpty()
    {
        $patch = $this->buildPatchWithMockedDependencies(
            $this->createMock(EavSetupFactory::class),
            $this->createMock(CustomerSetupFactory::class),
            $this->createMock(SetFactory::class),
            $this->createMock(ModuleDataSetupInterface::class)
        );

        $this->assertSame([], $patch->getAliases());
    }

    /**
     * A3.3: revert is a no-op today — confirm calling it does not throw.
     */
    public function testRevertDoesNotThrow()
    {
        $patch = $this->buildPatchWithMockedDependencies(
            $this->createMock(EavSetupFactory::class),
            $this->createMock(CustomerSetupFactory::class),
            $this->createMock(SetFactory::class),
            $this->createMock(ModuleDataSetupInterface::class)
        );

        $patch->revert();
        $this->assertTrue(true, 'revert() must not throw');
    }

    /**
     * A3.4: apply() must register the taxcloud_tic EAV attribute on Product and the
     * taxcloud_cert EAV attribute on Customer, via the right factories.
     */
    public function testApplyRegistersTaxcloudTicAndCertAttributes()
    {
        $eavSetup = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->addMethods(['addAttribute'])
            ->getMock();
        $eavSetup->expects($this->once())
            ->method('addAttribute')
            ->with(
                Product::ENTITY,
                'taxcloud_tic',
                $this->callback(function ($attrConfig) {
                    return ($attrConfig['type'] ?? null) === 'varchar'
                        && ($attrConfig['input'] ?? null) === 'text'
                        && ($attrConfig['label'] ?? null) === 'Taxcloud TIC';
                })
            );
        $eavSetupFactory = $this->createMock(EavSetupFactory::class);
        $eavSetupFactory->method('create')->willReturn($eavSetup);

        $attribute = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->addMethods(['addData', 'save'])
            ->getMock();
        $attribute->method('addData')->willReturnSelf();
        $attribute->expects($this->once())->method('save');

        $eavConfig = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->addMethods(['getAttribute', 'getEntityType'])
            ->getMock();
        $customerEntity = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDefaultAttributeSetId'])
            ->getMock();
        $customerEntity->method('getDefaultAttributeSetId')->willReturn(1);
        $eavConfig->method('getEntityType')->with('customer')->willReturn($customerEntity);
        $eavConfig->method('getAttribute')->with(Customer::ENTITY, 'taxcloud_cert')->willReturn($attribute);

        $customerSetup = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->addMethods(['addAttribute', 'getEavConfig'])
            ->getMock();
        $customerSetup->method('getEavConfig')->willReturn($eavConfig);
        $customerSetup->expects($this->once())
            ->method('addAttribute')
            ->with(Customer::ENTITY, 'taxcloud_cert', $this->callback(function ($attrConfig) {
                return ($attrConfig['type'] ?? null) === 'varchar'
                    && ($attrConfig['label'] ?? null) === 'Taxcloud Exemption Certificate ID';
            }));
        $customerSetupFactory = $this->createMock(CustomerSetupFactory::class);
        $customerSetupFactory->method('create')->willReturn($customerSetup);

        $attributeSet = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDefaultGroupId'])
            ->getMock();
        $attributeSet->method('getDefaultGroupId')->willReturn(11);
        $attributeSetFactory = $this->createMock(SetFactory::class);
        $attributeSetFactory->method('create')->willReturn($attributeSet);

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);

        $patch = $this->buildPatchWithMockedDependencies(
            $eavSetupFactory,
            $customerSetupFactory,
            $attributeSetFactory,
            $moduleDataSetup
        );

        $patch->apply();
    }

    private function buildPatchWithMockedDependencies(
        EavSetupFactory $eavSetupFactory,
        CustomerSetupFactory $customerSetupFactory,
        SetFactory $attributeSetFactory,
        ModuleDataSetupInterface $moduleDataSetup
    ): InstallTaxcloudData {
        return new InstallTaxcloudData(
            $customerSetupFactory,
            $attributeSetFactory,
            $eavSetupFactory,
            $moduleDataSetup
        );
    }
}
