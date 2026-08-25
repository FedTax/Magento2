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
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
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

    /**
     * @var int[] Customer groups the store treats as exempt in the test at hand
     */
    private $exemptGroups = [];

    private function resolver(): CertificateResolver
    {
        $config = $this->createStub(TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('areExemptionsEnabled')->willReturn(true);
        $config->method('getExemptCustomerGroups')->willReturnCallback(function () {
            return $this->exemptGroups;
        });
        $config->method('isRestrictedToExemptGroups')->willReturn(false);

        return new CertificateResolver(
            $this->repository,
            new TaxCloudCustomerIdentity(),
            new ExemptionPolicy($config)
        );
    }

    /**
     * @param string|null $configured
     * @param int|null $entityId
     */
    private function customer($configured = null, $entityId = 42, $groupId = 1)
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn($entityId);
        $customer->method('getGroupId')->willReturn($groupId);

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

    // ─── group auto-apply ────────────────────────────────────────────────

    /**
     * The merchant vouched for this customer by putting them in an exempt
     * group; making them pick a certificate on every order would be friction
     * without a decision.
     */
    public function testExemptGroupCustomerIsAutoApplied()
    {
        $this->exemptGroups = [7];
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $resolved = $this->resolver()->resolve($this->customer(null, 42, 7), 'TX', null);

        $this->assertNotNull($resolved);
        $this->assertSame(self::TX_CERT, $resolved->getCertificateId());
    }

    public function testCustomerOutsideTheExemptGroupsIsNotAutoApplied()
    {
        $this->exemptGroups = [7];
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $this->assertNull($this->resolver()->resolve($this->customer(null, 42, 1), 'TX', null));
    }

    public function testAutoApplyStillRequiresTheCertificateToCoverTheDestination()
    {
        $this->exemptGroups = [7];
        $this->holding([$this->certificate(self::NY_CERT, ['NY'])]);

        $this->assertNull($this->resolver()->resolve($this->customer(null, 42, 7), 'TX', null));
    }

    /**
     * An exempt-group customer who declines must stay declined; otherwise they
     * could never remove an exemption they are entitled not to use.
     */
    public function testAnExplicitClearingIsNotOverruledByTheGroup()
    {
        $this->exemptGroups = [7];
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $this->assertNull(
            $this->resolver()->resolve($this->customer(null, 42, 7), 'TX', null, null, true)
        );
    }

    // ─── declining ───────────────────────────────────────────────────────

    /**
     * A decline must beat the certificate an administrator pinned to the
     * customer, not just their exempt group.
     *
     * Getting this wrong gives the shopper a control that appears to work and
     * does nothing — and files the order against a certificate they refused.
     */
    public function testDecliningBeatsACertificatePinnedToTheCustomer()
    {
        $this->exemptGroups = [];
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        // The customer has cert-tx attached to their account...
        $customer = $this->customer(self::TX_CERT, 42, 1);

        $this->assertNotNull(
            $this->resolver()->resolve($customer, 'TX', null, null, false),
            'precondition: the attached certificate applies when nothing was declined'
        );

        $this->assertNull(
            $this->resolver()->resolve($customer, 'TX', null, null, true),
            'a declined order must not be exempted by the attached certificate'
        );
    }

    /**
     * Choosing a certificate for this cart is not a decline — the two cannot
     * both be true, and the choice must win.
     */
    public function testChoosingACertificateOverridesAStaleDeclineFlag()
    {
        $this->holding([$this->certificate(self::TX_CERT, ['TX'])]);

        $resolved = $this->resolver()->resolve($this->customer(), 'TX', self::TX_CERT, null, true);

        $this->assertNotNull($resolved);
        $this->assertSame(self::TX_CERT, $resolved->getCertificateId());
    }

    /**
     * A decline costs no API call either: there is nothing to look up.
     */
    public function testDecliningMakesNoApiCall()
    {
        $this->exemptGroups = [7];
        $this->expectRepository()->expects($this->never())->method('forCustomer');

        $this->assertNull(
            $this->resolver()->resolve($this->customer(null, 42, 7), 'TX', null, null, true)
        );
    }

    /**
     * The property the foundation established, which auto-apply must not cost:
     * a store applying nothing automatically pays no API call per cart.
     */
    public function testNoApiCallWhenNothingIsClaimedAndNothingIsAutomatic()
    {
        $this->exemptGroups = [];
        $this->expectRepository()->expects($this->never())->method('forCustomer');

        $this->assertNull($this->resolver()->resolve($this->customer(), 'TX', null));
    }
}
