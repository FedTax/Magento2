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

namespace Taxcloud\Magento2\Test\Unit\Model\Certificate;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * The resolver is the ownership boundary, so most of this file is about what
 * it REFUSES.
 *
 * That emphasis is deliberate. TaxCloud performs no ownership check of its own
 * — confirmed live: a cart naming one customer, carrying another's certificate,
 * came back exempt. Once an identifier reaches TaxCloud it has already been
 * trusted, so every refusal that matters has to happen here.
 */
class CertificateResolverTest extends TestCase
{
    private const TX_CERT = 'cert-tx';
    private const NY_CERT = 'cert-ny';

    /**
     * A stub by default: most tests here only need the repository to answer,
     * not to be asserted against. The few that assert HOW it was called swap in
     * a mock via {@see expectRepository()}.
     *
     * @var CertificateRepository
     */
    private $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(CertificateRepository::class);
    }

    /**
     * Swap in a mock for the tests that assert on the call itself.
     *
     * @return CertificateRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectRepository()
    {
        $this->repository = $this->createMock(CertificateRepository::class);

        return $this->repository;
    }

    private function resolver(): CertificateResolver
    {
        return new CertificateResolver($this->repository, new TaxCloudCustomerIdentity());
    }

    /**
     * @param string|null $configured
     * @param int|null $entityId
     */
    private function customer($configured = null, $entityId = 42)
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn($entityId);

        if ($configured === null) {
            $customer->method('getCustomAttribute')->willReturn(null);
            return $customer;
        }

        $attribute = $this->createStub(\Magento\Framework\Api\AttributeInterface::class);
        $attribute->method('getValue')->willReturn($configured);
        $customer->method('getCustomAttribute')->willReturn($attribute);

        return $customer;
    }

    /**
     * @param string[] $states
     */
    private function certificate(string $id, array $states, bool $disabled = false, bool $single = false)
    {
        return new Certificate($id, '42', $states, $disabled, $single);
    }

    private function holding(array $certificates): void
    {
        $this->repository->method('forCustomer')->willReturn($certificates);
    }

    // ─── eligibility ─────────────────────────────────────────────────────

    public function testCertificateCoveringTheDestinationIsApplied()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $resolved = $this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT);

        $this->assertNotNull($resolved);
        $this->assertSame(self::TX_CERT, $resolved->getCertificateId());
    }

    public function testCertificateNotCoveringTheDestinationIsNotApplied()
    {
        $this->holding([$this->certificate(self::NY_CERT, ['NY'])]);

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', self::NY_CERT));
    }

    public function testTheRightCertificateIsPickedFromSeveral()
    {
        $this->holding([
            $this->certificate(self::NY_CERT, ['NY']),
            $this->certificate(self::TX_CERT, ['TX']),
        ]);

        $resolved = $this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT);

        $this->assertSame(self::TX_CERT, $resolved->getCertificateId());
    }

    public function testDisabledCertificateIsNotApplied()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'], true)]);

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT));
    }

    /**
     * The module cannot tell which order a single-purchase certificate was
     * meant for, so it never picks one.
     */
    public function testSinglePurchaseCertificateIsNotApplied()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'], false, true)]);

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT));
    }

    // ─── ownership ───────────────────────────────────────────────────────

    /**
     * The attack. A valid certificate id on the account, belonging to someone
     * else, submitted for this customer's order. TaxCloud would honour it.
     */
    public function testForeignCertificateIdentifierIsRefused()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $this->assertNull(
            $this->resolver()->resolve($this->customer(), 'TX', 'someone-elses-certificate'),
            'an identifier outside the customer\'s own set must never be applied'
        );
    }

    /**
     * Happens legitimately — a certificate deleted in TaxCloud, or an identity
     * changed — and is handled the same way as an attack, because from here
     * the two are indistinguishable.
     */
    public function testStoredIdentifierThatNoLongerResolvesIsTreatedAsAbsent()
    {
        $this->holding([]);

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT));
    }

    public function testBelongsToCustomerAnswersOwnershipForWritePaths()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);
        $resolver = $this->resolver();

        $this->assertTrue($resolver->belongsToCustomer($this->customer(), self::TX_CERT));
        $this->assertFalse($resolver->belongsToCustomer($this->customer(), 'someone-elses-certificate'));
    }

    /**
     * A certificate that is the customer's but covers nowhere relevant still
     * belongs to them — deletion is about ownership, not eligibility.
     */
    public function testOwnershipIsIndependentOfEligibility()
    {
        $this->holding([$this->certificate(self::NY_CERT, ['NY'], true)]);

        $this->assertTrue($this->resolver()->belongsToCustomer($this->customer(), self::NY_CERT));
    }

    // ─── failing closed ──────────────────────────────────────────────────

    /**
     * "Could not ask" must not read as "no certificate restrictions". Getting
     * this backwards would exempt orders during an outage.
     */
    public function testRetrievalFailureTaxesTheOrder()
    {
        $this->repository->method('forCustomer')->willThrowException(new RuntimeException('taxcloud down'));

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT));
    }

    public function testRetrievalFailureDeniesOwnership()
    {
        $this->repository->method('forCustomer')->willThrowException(new RuntimeException('taxcloud down'));

        $this->assertFalse(
            $this->resolver()->belongsToCustomer($this->customer(), self::TX_CERT),
            'an unverifiable claim of ownership is not ownership'
        );
    }

    // ─── guests ──────────────────────────────────────────────────────────

    /**
     * No customer, no certificates, and crucially no API call — querying under
     * an empty identity would match whatever TaxCloud files under the empty
     * string.
     */
    public function testGuestResolvesToNothingWithoutCallingTaxCloud()
    {
        $this->expectRepository()->expects($this->never())->method('forCustomer');

        $this->assertNull($this->resolver()->resolve(null, 'TX', self::TX_CERT));
    }

    public function testCustomerWithoutAnEntityIdIsTreatedAsAGuest()
    {
        $this->expectRepository()->expects($this->never())->method('forCustomer');

        $this->assertNull($this->resolver()->resolve($this->customer(null, null), 'TX', self::TX_CERT));
    }

    public function testMissingDestinationStateResolvesToNothing()
    {
        $this->expectRepository()->expects($this->never())->method('forCustomer');

        $this->assertNull($this->resolver()->resolve($this->customer(), '', self::TX_CERT));
    }

    // ─── identity ────────────────────────────────────────────────────────

    public function testCertificatesAreLookedUpUnderTheConfiguredIdentity()
    {
        $this->expectRepository()->expects($this->once())
            ->method('forCustomer')
            ->with('acme-corp', 7)
            ->willReturn([$this->certificate(self::TX_CERT, ['TX'])]);

        $resolved = $this->resolver()->resolve($this->customer('acme-corp'), 'TX', self::TX_CERT, 7);

        $this->assertSame(self::TX_CERT, $resolved->getCertificateId());
    }

    public function testCertificatesAreLookedUpUnderTheEntityIdByDefault()
    {
        $this->expectRepository()->expects($this->once())
            ->method('forCustomer')
            ->with('42', null)
            ->willReturn([]);

        $this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT);
    }

    // ─── auto-apply slot ─────────────────────────────────────────────────

    /**
     * With no identifier supplied, nothing is applied yet: the group auto-apply
     * branch is a slot until the setting that nominates exempt customer groups
     * exists. A rule nothing can turn on would be worse than no rule.
     */
    public function testNothingIsAutoAppliedYet()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', null));
    }
}
