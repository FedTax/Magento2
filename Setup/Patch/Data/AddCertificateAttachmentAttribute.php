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
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;

/**
 * The customer attribute naming a certificate to apply to their orders — the
 * replacement for `taxcloud_cert`.
 *
 * Same job, different meaning. The old attribute was the ONLY way a customer
 * could be exempt, so it doubled as "is this customer exempt at all". This one
 * is a preference among certificates the customer may hold several of: set it
 * to pin a particular certificate, leave it empty and let the store's exempt
 * groups apply a covering one.
 *
 * Not rendered in any customer-facing form, and — like the TaxCloud identity —
 * the guard on the customer repository is what actually prevents a storefront
 * write, since `used_in_forms` governs rendering rather than writability.
 */
class AddCertificateAttachmentAttribute implements DataPatchInterface, PatchRevertableInterface
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

        $customerSetup->addAttribute(Customer::ENTITY, CertificateResolver::ATTACHED_ATTRIBUTE, [
            'type' => 'varchar',
            'label' => 'TaxCloud Exemption Certificate',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => false,
            'sort_order' => 1002,
            'position' => 1002,
            'system' => 0,
            'note' => 'Pin a specific certificate to this customer. Leave empty to let a covering'
                . ' certificate apply automatically when the customer is in an exempt group.',
        ]);

        $attribute = $customerSetup->getEavConfig()
            ->getAttribute(Customer::ENTITY, CertificateResolver::ATTACHED_ATTRIBUTE);

        if ($attribute && $attribute->getId()) {
            $attribute->addData(['used_in_forms' => ['adminhtml_customer']]);
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
        $customerSetup->removeAttribute(Customer::ENTITY, CertificateResolver::ATTACHED_ATTRIBUTE);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [AddTaxcloudCustomerIdAttribute::class];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
