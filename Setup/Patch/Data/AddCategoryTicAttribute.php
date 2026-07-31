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

namespace Taxcloud\Magento2\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Adds the `taxcloud_tic` attribute to catalog categories.
 *
 * This is the middle level of TIC resolution: a line item takes its product's
 * own TIC when set, otherwise the TIC of the nearest category above it (see
 * {@see \Taxcloud\Magento2\Model\CategoryTicResolver}), otherwise the store's
 * configured default TIC.
 *
 * Deliberately SCOPE_STORE, unlike the product-level attribute installed by
 * {@see InstallTaxcloudData}: every other TaxCloud setting resolves against the
 * store the order/quote belongs to, and multi-store merchants running different
 * TaxCloud accounts per store view need to be able to differ here too. Leaving
 * the store row empty falls back to the default-scope value, exactly like core
 * category attributes.
 */
class AddCategoryTicAttribute implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * Attribute code, shared with the resolver that reads it.
     */
    public const ATTRIBUTE_CODE = 'taxcloud_tic';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * Create the category attribute.
     *
     * @return $this
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Category::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'varchar',
                'label' => 'TaxCloud TIC',
                'input' => 'text',
                'required' => false,
                'sort_order' => 100,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'General Information',
                'visible' => true,
                'user_defined' => true,
                'is_used_in_grid' => false,
                'visible_on_front' => false,
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Runs after the attribute installer so both TaxCloud EAV attributes are
     * created in a predictable order on a fresh install.
     *
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [InstallTaxcloudData::class];
    }

    /**
     * Drop the attribute again so uninstalling the module leaves no orphaned
     * attribute metadata. removeAttribute() is a no-op when the attribute is
     * already gone, so this is safe to run even if apply() never completed.
     *
     * {@inheritdoc}
     */
    public function revert()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup])
            ->removeAttribute(Category::ENTITY, self::ATTRIBUTE_CODE);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
