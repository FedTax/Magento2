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


namespace Taxcloud\Magento2\Test\Integration\Certificate;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Taxcloud\Magento2\Model\Certificate\CertificateAttachment;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The attachment write path against a real customer.
 *
 * Unit tests prove the rules; this proves the value actually lands on a
 * customer, survives a reload, and that the two guards around it hold when real
 * Magento is doing the saving rather than a mock.
 *
 * Works on a customer of its own, created and deleted here. The seeded exempt
 * customer is shared with the e2e suite, and changing whose certificate applies
 * would surface as an unrelated checkout test failing.
 */
class AttachmentPersistenceTest extends IntegrationTestCase
{
    /** @var int|null */
    private $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerId = $this->createCustomer();
    }

    protected function tearDown(): void
    {
        if ($this->customerId !== null) {
            $this->inSecureArea(function () {
                try {
                    $this->get(CustomerRepositoryInterface::class)->deleteById($this->customerId);
                } catch (\Throwable $e) {
                    // Cleanup only.
                }
            });
        }

        parent::tearDown();
    }

    private function createCustomer(): int
    {
        /** @var CustomerInterface $customer */
        $customer = $this->get(CustomerInterfaceFactory::class)->create();
        $customer->setFirstname('Attachment');
        $customer->setLastname('Probe');
        $customer->setEmail('attachment-probe-' . uniqid('', false) . '@example.com');
        $customer->setWebsiteId(1);
        $customer->setGroupId(1);

        return (int) $this->get(CustomerRepositoryInterface::class)->save($customer)->getId();
    }

    private function reload(): CustomerInterface
    {
        return $this->get(CustomerRepositoryInterface::class)->getById($this->customerId);
    }

    private function attachment(): CertificateAttachment
    {
        return $this->get(CertificateAttachment::class);
    }

    private function resolver(): CertificateResolver
    {
        return $this->get(CertificateResolver::class);
    }

    public function testAttachmentIsPersistedAndSurvivesAReload(): void
    {
        $this->assertSame(
            '',
            $this->resolver()->attachedCertificateId($this->reload()),
            'a new customer starts with nothing attached'
        );

        $this->attachment()->set($this->reload(), 'cert-persisted', 'phpunit');

        $this->assertSame(
            'cert-persisted',
            $this->resolver()->attachedCertificateId($this->reload()),
            'the attachment must be readable from a customer loaded afresh, not just from the object written'
        );
    }

    public function testAttachmentCanBeCleared(): void
    {
        $this->attachment()->set($this->reload(), 'cert-persisted', 'phpunit');
        $this->attachment()->set($this->reload(), '', 'phpunit');

        $this->assertSame(
            '',
            $this->resolver()->attachedCertificateId($this->reload()),
            'clearing must be a real state, not merely the absence of a write'
        );
    }

    public function testAutoAttachDoesNotDisplaceAnExistingAttachment(): void
    {
        $this->attachment()->set($this->reload(), 'cert-first', 'phpunit');

        $displaced = $this->attachment()->setIfUnattached($this->reload(), 'cert-second', 'phpunit');

        $this->assertFalse($displaced, 'setIfUnattached must report that it did nothing');
        $this->assertSame(
            'cert-first',
            $this->resolver()->attachedCertificateId($this->reload()),
            'adding a second certificate must never silently re-file the customer against it'
        );
    }

    public function testAutoAttachFillsAnEmptySlot(): void
    {
        $attached = $this->attachment()->setIfUnattached($this->reload(), 'cert-auto', 'phpunit');

        $this->assertTrue($attached);
        $this->assertSame('cert-auto', $this->resolver()->attachedCertificateId($this->reload()));
    }

    /**
     * The identity is what certificates are filed under.
     */
    public function testIdentityDefaultsToTheEntityId(): void
    {
        $this->assertSame(
            (string) $this->customerId,
            $this->get(TaxCloudCustomerIdentity::class)->resolve($this->reload()),
            'with nothing configured, certificates are filed under the Magento customer id'
        );
    }

    /**
     * Setting a customer's TaxCloud identity grants them whatever exemptions are
     * filed under it, so it is admin-only and permission-gated. This suite runs
     * in the frontend area — the same position a storefront account edit, a REST
     * call or an import occupies — and the write must simply not take.
     *
     * Discovered by asserting the opposite: the guard refused the write and the
     * value stayed at the entity id, which is the behaviour that matters.
     */
    public function testCustomerFacingWritesToTheIdentityAreRefused(): void
    {
        $customer = $this->reload();
        $customer->setCustomAttribute(TaxCloudCustomerIdentity::ATTRIBUTE, 'acme-corp');
        $this->get(CustomerRepositoryInterface::class)->save($customer);

        $this->assertSame(
            (string) $this->customerId,
            $this->get(TaxCloudCustomerIdentity::class)->resolve($this->reload()),
            'a customer-facing write must leave the stored identity untouched — otherwise a '
            . 'shopper could claim another company\'s certificates by editing their own account'
        );
    }
}
