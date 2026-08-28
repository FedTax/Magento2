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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Adds `taxcloud_customer_id` to customers: the identity their exemption
 * certificates are filed under in TaxCloud.
 *
 * Left EMPTY on every existing customer, deliberately. An empty value resolves
 * to the Magento entity id — which is precisely what the module queried before
 * this attribute existed — so every install keeps behaving exactly as it did,
 * and nothing needs backfilling. A merchant sets it only for the customers
 * whose certificates were filed under something else, which in practice means
 * the ones created by hand in the TaxCloud portal.
 *
 * Rendered only in `adminhtml_customer`. That is presentation, not protection:
 * `used_in_forms` decides where Magento draws an attribute, not whether a save
 * will accept one. The write itself is guarded by
 * {@see \Taxcloud\Magento2\Plugin\Customer\GuardTaxCloudIdentity}, because
 * setting this hands a customer whatever exemptions are filed under the new
 * value and TaxCloud enforces no ownership of its own.
 */
class AddTaxcloudCustomerIdAttribute implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var CustomerSetupFactory
     */
    private $customerSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CustomerSetupFactory $customerSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CustomerSetupFactory $customerSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(Customer::ENTITY, TaxCloudCustomerIdentity::ATTRIBUTE, [
            'type' => 'varchar',
            'label' => 'TaxCloud Customer ID',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => false,
            'sort_order' => 1001,
            'position' => 1001,
            'system' => 0,
            'note' => 'Leave empty to use the Magento customer ID. Set this only when the customer\'s'
                . ' certificates were filed in TaxCloud under a different identifier.',
        ]);

        $attribute = $customerSetup->getEavConfig()
            ->getAttribute(Customer::ENTITY, TaxCloudCustomerIdentity::ATTRIBUTE);

        if ($attribute && $attribute->getId()) {
            $attribute->addData([
                // Admin only. A storefront form must never render it, and a
                // storefront save must never accept it — the latter enforced
                // by the repository plugin, not by this list.
                'used_in_forms' => ['adminhtml_customer'],
            ]);
            $attribute->save();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function revert()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $customerSetup->removeAttribute(Customer::ENTITY, TaxCloudCustomerIdentity::ATTRIBUTE);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        // The customer entity's attribute set has to exist first.
        return [InstallTaxcloudData::class];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
