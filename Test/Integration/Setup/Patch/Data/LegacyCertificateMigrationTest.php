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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Setup\Patch\Data\MigrateLegacyCertificateAttribute;
use Taxcloud\Magento2\Setup\Patch\Data\RemoveLegacyCertificateAttribute;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The upgrade promise: a customer who was exempt before this release is still
 * exempt after it, with nobody re-entering anything.
 *
 * Driven through the real patches against the real EAV tables, because that is
 * the only way to find out whether the migration actually moves data. A unit
 * test here would assert that a mocked setup object was called — which is a
 * statement about the test, not about a merchant's customers surviving an
 * upgrade.
 *
 * The two patches are exercised separately as well as together, because their
 * separation is the safety property: the copy must be able to run twice, and
 * the removal must never run before the copy.
 */
class LegacyCertificateMigrationTest extends IntegrationTestCase
{
    private const LEGACY = MigrateLegacyCertificateAttribute::LEGACY_ATTRIBUTE;

    /**
     * @var int[] Customers created here, removed in tearDown
     */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Recreate the pre-upgrade world. On any install where setup:upgrade has
        // already run, the removal patch has retired this attribute — so the
        // test has to stand its own fixture up rather than assume the state it
        // is testing the departure from.
        $this->customerSetup()->addAttribute(Customer::ENTITY, self::LEGACY, [
            'type' => 'varchar',
            'label' => 'Taxcloud Exemption Certificate ID',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => true,
            'system' => 0,
        ]);
        $this->get(\Magento\Eav\Model\Config::class)->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $customerId) {
            $this->inSecureArea(function () use ($customerId) {
                try {
                    $this->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->deleteById($customerId);
                } catch (\Throwable $e) {
                    // Already gone; nothing to undo.
                }
            });
        }
        $this->created = [];

        // Leave the shared install as we found it.
        $this->customerSetup()->removeAttribute(Customer::ENTITY, self::LEGACY);
        $this->get(\Magento\Eav\Model\Config::class)->clear();

        parent::tearDown();
    }

    /**
     * @return \Magento\Customer\Setup\CustomerSetup
     */
    private function customerSetup()
    {
        return $this->get(CustomerSetupFactory::class)
            ->create(['setup' => $this->get(ModuleDataSetupInterface::class)]);
    }

    private function connection()
    {
        return $this->get(ModuleDataSetupInterface::class)->getConnection();
    }

    private function attributeId(string $code): ?int
    {
        $attribute = $this->customerSetup()->getEavConfig()->getAttribute(Customer::ENTITY, $code);
        $id = $attribute ? $attribute->getId() : null;

        return $id ? (int) $id : null;
    }

    /**
     * A customer carrying a legacy certificate value, as an install predating
     * this release would have.
     */
    private function customerWithLegacyValue(string $certificateId): int
    {
        $repository = $this->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customer = $this->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId(1)
            ->setEmail('legacy-cert-' . uniqid('', false) . '@example.com')
            ->setFirstname('Legacy')
            ->setLastname('Customer')
            ->setGroupId(1);
        $saved = $repository->save($customer);
        $customerId = (int) $saved->getId();
        $this->created[] = $customerId;

        // Written straight to EAV: the legacy attribute is not in any form, and
        // this is exactly how an upgraded install's data looks.
        $legacyId = $this->attributeId(self::LEGACY);
        $this->assertNotNull($legacyId, 'the legacy attribute must exist for this test to mean anything');
        $this->connection()->insertOnDuplicate(
            $this->get(ModuleDataSetupInterface::class)->getTable('customer_entity_varchar'),
            ['attribute_id' => $legacyId, 'entity_id' => $customerId, 'value' => $certificateId],
            ['value']
        );

        return $customerId;
    }

    private function migratedValue(int $customerId): ?string
    {
        $targetId = $this->attributeId(CertificateResolver::ATTACHED_ATTRIBUTE);
        if ($targetId === null) {
            return null;
        }

        $value = $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->get(ModuleDataSetupInterface::class)->getTable('customer_entity_varchar'), ['value'])
                ->where('attribute_id = ?', $targetId)
                ->where('entity_id = ?', $customerId)
        );

        return $value === false ? null : (string) $value;
    }

    public function testLegacyValueIsCarriedAcrossVerbatim(): void
    {
        $certificateId = 'legacy-' . uniqid('', false);
        $customerId = $this->customerWithLegacyValue($certificateId);

        $this->get(MigrateLegacyCertificateAttribute::class)->apply();

        $this->assertSame(
            $certificateId,
            $this->migratedValue($customerId),
            'a customer exempt before the upgrade must still name the same certificate after it'
        );
    }

    /**
     * Re-runnability is the property that makes splitting the patches worth
     * anything: an interruption must leave the copy repeatable, not a
     * duplicate-key failure.
     */
    public function testMigrationIsSafeToRunTwice(): void
    {
        $certificateId = 'legacy-' . uniqid('', false);
        $customerId = $this->customerWithLegacyValue($certificateId);

        $patch = $this->get(MigrateLegacyCertificateAttribute::class);
        $patch->apply();
        $patch->apply();

        $this->assertSame($certificateId, $this->migratedValue($customerId));
    }

    /**
     * An administrator who already set the new attribute has made a decision
     * more recent than the legacy value; the migration must not overwrite it.
     */
    public function testAnAlreadyMigratedCustomerIsNotOverwritten(): void
    {
        $customerId = $this->customerWithLegacyValue('legacy-value');

        $targetId = $this->attributeId(CertificateResolver::ATTACHED_ATTRIBUTE);
        $this->connection()->insertOnDuplicate(
            $this->get(ModuleDataSetupInterface::class)->getTable('customer_entity_varchar'),
            ['attribute_id' => $targetId, 'entity_id' => $customerId, 'value' => 'chosen-later'],
            ['value']
        );

        $this->get(MigrateLegacyCertificateAttribute::class)->apply();

        $this->assertSame('chosen-later', $this->migratedValue($customerId));
    }

    public function testEmptyLegacyValuesAreNotCarriedAcross(): void
    {
        $customerId = $this->customerWithLegacyValue('   ');

        $this->get(MigrateLegacyCertificateAttribute::class)->apply();

        $this->assertNull(
            $this->migratedValue($customerId),
            'blank is not a certificate; carrying it across would make the new attribute look configured'
        );
    }

    /**
     * The whole point of the split: values first, deletion second. Running them
     * in order must leave the customer's certificate intact with the legacy
     * attribute gone.
     */
    public function testValuesSurviveTheAttributeBeingRemoved(): void
    {
        $certificateId = 'legacy-' . uniqid('', false);
        $customerId = $this->customerWithLegacyValue($certificateId);

        $this->get(MigrateLegacyCertificateAttribute::class)->apply();
        $this->get(RemoveLegacyCertificateAttribute::class)->apply();
        // EAV config caches attribute metadata in-request; without this the
        // lookup below still answers from before the removal.
        $this->get(\Magento\Eav\Model\Config::class)->clear();

        $this->assertSame(
            $certificateId,
            $this->migratedValue($customerId),
            'the value must outlive the attribute it came from'
        );
        $this->assertNull($this->attributeId(self::LEGACY), 'the legacy attribute must be gone');
    }
}
