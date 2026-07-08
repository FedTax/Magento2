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

namespace Taxcloud\Magento2\Test\Integration\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Customer;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Taxcloud\Magento2\Setup\Patch\Data\InstallTaxcloudData;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that InstallTaxcloudData::revert() actually drops the two EAV
 * attributes apply() creates — against the real installed Magento, not mocks.
 *
 * The module is installed on the integration Magento, so both attributes exist
 * at the start. We assert they're present, run revert(), assert they're gone,
 * then re-apply so the schema is restored.
 *
 * Non-destructive by design: dropping an EAV attribute cascades to delete every
 * stored value for it (e.g. the seeded variant taxcloud_tic values other tests
 * rely on), and re-apply() only recreates the attribute *definition*, not the
 * data. So this test snapshots each attribute's per-entity values before revert
 * and writes them back after re-apply, leaving the install exactly as it found
 * it. In production revert/apply run as separate CLI processes and there is no
 * data to preserve, so none of this bookkeeping applies there.
 */
class InstallTaxcloudDataRevertTest extends IntegrationTestCase
{
    private const PRODUCT_ENTITY = Product::ENTITY;
    private const PRODUCT_ATTR = 'taxcloud_tic';
    private const CUSTOMER_ENTITY = Customer::ENTITY;
    private const CUSTOMER_ATTR = 'taxcloud_cert';

    /** @var array<int, array<int, array{link: int, store_id: int, value: mixed}>> */
    private array $valueSnapshots = [];

    private bool $reverted = false;

    protected function tearDown(): void
    {
        // Guarantee schema + data are restored even if an assertion aborts the
        // test after revert() — leaving them dropped would break other tests and
        // the install itself.
        if ($this->reverted) {
            $this->reapplyPatch();
            $this->reverted = false;
        }
        parent::tearDown();
    }

    public function testRevertRemovesBothEavAttributesFromRealInstall(): void
    {
        // Baseline: the installed module means both attributes are present.
        $this->assertTrue(
            $this->attributeExists(self::PRODUCT_ENTITY, self::PRODUCT_ATTR),
            'Precondition: taxcloud_tic should exist on Product before revert.'
        );
        $this->assertTrue(
            $this->attributeExists(self::CUSTOMER_ENTITY, self::CUSTOMER_ATTR),
            'Precondition: taxcloud_cert should exist on Customer before revert.'
        );

        // Snapshot stored values so re-apply() can restore them (revert drops
        // the attribute, cascading its values away — see class docblock).
        $this->snapshotValues(self::PRODUCT_ENTITY, self::PRODUCT_ATTR);
        $this->snapshotValues(self::CUSTOMER_ENTITY, self::CUSTOMER_ATTR);

        // Act: run the patch's revert() through the real DI graph.
        $this->reverted = true;
        $this->get(InstallTaxcloudData::class)->revert();

        // Assert: both attributes are gone.
        $this->assertFalse(
            $this->attributeExists(self::PRODUCT_ENTITY, self::PRODUCT_ATTR),
            'revert() should remove the taxcloud_tic Product attribute.'
        );
        $this->assertFalse(
            $this->attributeExists(self::CUSTOMER_ENTITY, self::CUSTOMER_ATTR),
            'revert() should remove the taxcloud_cert Customer attribute.'
        );

        // Restore schema + data for the rest of the suite (also covered by tearDown).
        $this->reapplyPatch();
        $this->reverted = false;

        $this->assertTrue(
            $this->attributeExists(self::PRODUCT_ENTITY, self::PRODUCT_ATTR),
            'apply() should re-create the taxcloud_tic Product attribute.'
        );
        $this->assertTrue(
            $this->attributeExists(self::CUSTOMER_ENTITY, self::CUSTOMER_ATTR),
            'apply() should re-create the taxcloud_cert Customer attribute.'
        );
    }

    /**
     * Re-run apply() to restore the attribute definitions, then write the
     * snapshotted per-entity values back.
     *
     * Clears the EAV config cache first: the assertFalse() checks above cached a
     * negative "taxcloud_cert doesn't exist" lookup, and apply()'s final
     * getAttribute()->save() would otherwise read that stale miss and
     * double-insert the customer attribute (AlreadyExistsException). In
     * production revert/apply run in separate processes, so no such shared cache
     * exists.
     */
    private function reapplyPatch(): void
    {
        $this->get(EavConfig::class)->clear();
        $this->get(InstallTaxcloudData::class)->apply();

        $this->restoreValues(self::PRODUCT_ENTITY, self::PRODUCT_ATTR);
        $this->restoreValues(self::CUSTOMER_ENTITY, self::CUSTOMER_ATTR);
        $this->valueSnapshots = [];
    }

    /**
     * Read every stored value for an EAV attribute into an in-memory snapshot,
     * keyed by entity link id + store, so it can be written back after the
     * attribute is dropped and recreated.
     */
    private function snapshotValues(string $entityType, string $code): void
    {
        $attribute = $this->loadAttribute($entityType, $code);
        if ($attribute === null || !$attribute->getAttributeId()) {
            $this->valueSnapshots[$this->snapshotKey($entityType, $code)] = [];
            return;
        }

        $connection = $attribute->getResource()->getConnection();
        $linkField = $attribute->getEntity()->getLinkField();
        $table = $attribute->getBackendTable();

        // Product EAV value tables are store-scoped (store_id column); customer
        // ones are not. Only carry store_id when the table actually has it.
        $hasStore = $connection->tableColumnExists($table, 'store_id');
        $columns = $hasStore ? [$linkField, 'store_id', 'value'] : [$linkField, 'value'];

        $select = $connection->select()
            ->from($table, $columns)
            ->where('attribute_id = ?', (int) $attribute->getAttributeId());

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $snapshot = [
                'link'  => (int) $row[$linkField],
                'value' => $row['value'],
            ];
            if ($hasStore) {
                $snapshot['store_id'] = (int) $row['store_id'];
            }
            $rows[] = $snapshot;
        }

        $this->valueSnapshots[$this->snapshotKey($entityType, $code)] = $rows;
    }

    /**
     * Write a snapshot's values back onto the freshly recreated attribute.
     */
    private function restoreValues(string $entityType, string $code): void
    {
        $rows = $this->valueSnapshots[$this->snapshotKey($entityType, $code)] ?? [];
        if ($rows === []) {
            return;
        }

        $attribute = $this->loadAttribute($entityType, $code);
        if ($attribute === null || !$attribute->getAttributeId()) {
            return;
        }

        $connection = $attribute->getResource()->getConnection();
        $linkField = $attribute->getEntity()->getLinkField();
        $table = $attribute->getBackendTable();
        $attributeId = (int) $attribute->getAttributeId();

        foreach ($rows as $row) {
            $data = [
                $linkField     => $row['link'],
                'attribute_id' => $attributeId,
                'value'        => $row['value'],
            ];
            if (array_key_exists('store_id', $row)) {
                $data['store_id'] = $row['store_id'];
            }
            $connection->insertOnDuplicate($table, $data, ['value']);
        }
    }

    private function snapshotKey(string $entityType, string $code): string
    {
        return $entityType . ':' . $code;
    }

    /**
     * Does an EAV attribute exist for the given entity type? Reads a freshly
     * loaded attribute so we see persisted state, not a cached copy from before
     * revert()/apply() mutated the schema.
     */
    private function attributeExists(string $entityType, string $code): bool
    {
        $attribute = $this->loadAttribute($entityType, $code);

        return $attribute !== null && (bool) $attribute->getAttributeId();
    }

    /**
     * Load an attribute with a cleared EAV config so schema mutations from
     * revert()/apply() are always reflected.
     */
    private function loadAttribute(string $entityType, string $code): ?AbstractAttribute
    {
        /** @var EavConfig $eavConfig */
        $eavConfig = $this->get(EavConfig::class);
        $eavConfig->clear();

        $attribute = $eavConfig->getAttribute($entityType, $code);

        return $attribute instanceof AbstractAttribute ? $attribute : null;
    }
}
