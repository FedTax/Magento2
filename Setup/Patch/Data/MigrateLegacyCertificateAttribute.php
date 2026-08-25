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
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;

/**
 * Copies every `taxcloud_cert` value into the attribute that replaces it.
 *
 * COPIES. It does not delete: {@see RemoveLegacyCertificateAttribute} does
 * that, ordered after this one. Splitting the two is the whole point — a single
 * patch that migrated and dropped in one pass would have no safe failure point,
 * and an interruption between its halves would leave values neither copied nor
 * recoverable. Separated, an interruption leaves the source attribute intact and
 * this patch re-runnable.
 *
 * The values need no interpretation. A pasted certificate identifier only ever
 * worked when the certificate was filed under the customer's Magento entity id —
 * that is what the old code queried — and the new TaxCloud identity defaults to
 * the entity id. So every configuration that worked keeps working, unchanged.
 *
 * Configurations that never worked — a certificate filed in the TaxCloud portal
 * under a company name — are carried across as they are. They do not start
 * working, because nothing here can invent the identity they were filed under.
 * What changes is that an administrator can now see the certificate is not
 * resolving and fix it, instead of a customer being silently taxed.
 */
class MigrateLegacyCertificateAttribute implements DataPatchInterface
{
    /**
     * The attribute this replaces.
     */
    public const LEGACY_ATTRIBUTE = 'taxcloud_cert';

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
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Both attributes must exist: the legacy one on any install being
        // upgraded, the new one from AddCertificateAttachmentAttribute.
        $legacyId = $this->attributeId($customerSetup, self::LEGACY_ATTRIBUTE);
        $targetId = $this->attributeId($customerSetup, CertificateResolver::ATTACHED_ATTRIBUTE);
        if ($legacyId === null || $targetId === null) {
            $connection->endSetup();
            return $this;
        }

        $table = $this->moduleDataSetup->getTable('customer_entity_varchar');

        // Skip rows already carried across, so re-running after an interruption
        // is a no-op rather than a duplicate-key failure.
        $select = $connection->select()
            ->from(['legacy' => $table], ['entity_id', 'value'])
            ->where('legacy.attribute_id = ?', $legacyId)
            ->where('legacy.value IS NOT NULL')
            ->where("TRIM(legacy.value) != ''")
            ->where(
                'NOT EXISTS (?)',
                new \Zend_Db_Expr(
                    $connection->select()
                        ->from(['migrated' => $table], [new \Zend_Db_Expr('1')])
                        ->where('migrated.attribute_id = ?', $targetId)
                        ->where('migrated.entity_id = legacy.entity_id')
                        ->assemble()
                )
            );

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'attribute_id' => $targetId,
                'entity_id' => (int) $row['entity_id'],
                'value' => trim((string) $row['value']),
            ];
        }

        if ($rows !== []) {
            $connection->insertMultiple($table, $rows);
        }

        $connection->endSetup();

        return $this;
    }

    /**
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @param string $code
     * @return int|null
     */
    private function attributeId($customerSetup, string $code)
    {
        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, $code);
        $id = $attribute ? $attribute->getId() : null;

        return $id ? (int) $id : null;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [AddCertificateAttachmentAttribute::class];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
