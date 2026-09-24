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


namespace Taxcloud\Magento2\Test\Unit\Controller\Certificate;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Controller\Certificate\Add;
use Taxcloud\Magento2\Controller\Certificate\Attach;
use Taxcloud\Magento2\Controller\Certificate\Delete;
use Taxcloud\Magento2\Controller\Certificate\Listing;
use Taxcloud\Magento2\Controller\Certificate\Refresh;
use Taxcloud\Magento2\Model\Certificate\AttachmentWriteScope;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateAttachment;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * What the storefront endpoints refuse, and what they do for the customers
 * they serve.
 *
 * These controllers are the only part of the feature a shopper can reach
 * directly, so their refusals are the boundary: a signed-out request, a store
 * with exemptions switched off, a customer the store has not nominated for
 * self-service, and — the one that matters most — a certificate identifier
 * that belongs to somebody else. Nothing about a certificate id is secret or
 * unguessable, and TaxCloud itself performs no ownership check, so the only
 * thing standing between one customer's exemptions and another's is these
 * paths refusing.
 *
 * They also pin the distinction between "you hold none" and "we could not ask".
 * Both render as an empty table and mean opposite things: a shopper told the
 * first when the second is true creates a duplicate of a certificate they
 * already hold.
 */
class StorefrontCertificateControllersTest extends TestCase
{
    /** @var array<string, mixed> Whatever the controller last answered with */
    private $answer = [];

    /** @var array<string, mixed> Request parameters for the call under test */
    private $params = [];

    /** @var bool */
    private $loggedIn = true;

    /** @var bool */
    private $exemptionsEnabled = true;

    /** @var bool */
    private $selfServiceEnabled = false;

    /** @var int[] */
    private $nominatedGroups = [];

    /** @var int */
    private $groupId = 1;

    /** @var string The customer's stored attachment */
    private $attached = '';

    /** @var Certificate[] */
    private $held = [];

    /** @var \Throwable|null Raised instead of returning certificates */
    private $readFailure;

    /** @var string[] Identities whose cache was invalidated */
    private $invalidated = [];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> Certificates filed */
    private $created = [];

    /** @var string[] Identifiers deleted at TaxCloud */
    private $deleted = [];

    /** @var string[] Lines written to the TaxCloud log */
    private $logged = [];

    protected function setUp(): void
    {
        $this->answer = [];
        $this->params = [];
        $this->loggedIn = true;
        $this->exemptionsEnabled = true;
        $this->selfServiceEnabled = false;
        $this->nominatedGroups = [];
        $this->groupId = 1;
        $this->attached = '';
        $this->held = [];
        $this->readFailure = null;
        $this->invalidated = [];
        $this->created = [];
        $this->deleted = [];
        $this->logged = [];
    }

    private function nominate(): void
    {
        $this->selfServiceEnabled = true;
        $this->nominatedGroups = [$this->groupId];
    }

    private function customer()
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getGroupId')->willReturnCallback(function () {
            return $this->groupId;
        });
        $customer->method('getCustomAttribute')->willReturnCallback(function ($code) {
            if ($code !== CertificateResolver::ATTACHED_ATTRIBUTE || $this->attached === '') {
                return null;
            }

            $attribute = $this->createStub(\Magento\Framework\Api\AttributeInterface::class);
            $attribute->method('getValue')->willReturn($this->attached);

            return $attribute;
        });
        $customer->method('setCustomAttribute')->willReturnCallback(function ($code, $value) {
            if ($code === CertificateResolver::ATTACHED_ATTRIBUTE) {
                $this->attached = (string) $value;
            }

            return null;
        });

        return $customer;
    }

    private function policy(): ExemptionPolicy
    {
        $config = $this->createStub(TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('areExemptionsEnabled')->willReturnCallback(function () {
            return $this->exemptionsEnabled;
        });
        $config->method('areCustomerCertificatesEnabled')->willReturnCallback(function () {
            return $this->selfServiceEnabled;
        });
        $config->method('getCustomerCertificateGroups')->willReturnCallback(function () {
            return $this->nominatedGroups;
        });

        return new ExemptionPolicy($config);
    }

    private function repository(): CertificateRepository
    {
        $repository = $this->createStub(CertificateRepository::class);
        $repository->method('forCustomer')->willReturnCallback(function () {
            if ($this->readFailure !== null) {
                throw $this->readFailure;
            }

            return $this->held;
        });
        $repository->method('create')->willReturnCallback(function ($identity, array $data) {
            $this->created[] = [$identity, $data];
            $this->held[] = new Certificate('cert-new', (string) $identity, $data['states'], false, false);

            return 'cert-new';
        });
        $repository->method('delete')->willReturnCallback(function ($certificateId) {
            $this->deleted[] = $certificateId;
            $this->held = array_values(array_filter($this->held, function (Certificate $held) use ($certificateId) {
                return $held->getCertificateId() !== $certificateId;
            }));
        });
        $repository->method('invalidate')->willReturnCallback(function ($identity) {
            $this->invalidated[] = (string) $identity;
        });

        return $repository;
    }

    private function context(): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(function ($name, $default = null) {
            return $this->params[$name] ?? $default;
        });

        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->answer = $data;

            return $json;
        });

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($json);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return $context;
    }

    private function session(): Session
    {
        $session = $this->createStub(Session::class);
        $session->method('isLoggedIn')->willReturnCallback(function () {
            return $this->loggedIn;
        });
        $session->method('getCustomerData')->willReturn($this->customer());

        return $session;
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createStub(\Magento\Store\Api\Data\StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);

        return $manager;
    }

    private function logger(): GatewayLogger
    {
        $logger = $this->createStub(GatewayLogger::class);
        $logger->method('info')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });

        return $logger;
    }

    /**
     * @return array{0: Context, 1: Session, 2: CertificateRepository, 3: CertificateResolver,
     *               4: TaxCloudCustomerIdentity, 5: ExemptionPolicy, 6: StoreManagerInterface,
     *               7: CertificateAttachment}
     */
    private function dependencies(): array
    {
        $repository = $this->repository();
        $resolver = new CertificateResolver($repository, new TaxCloudCustomerIdentity());

        return [
            $this->context(),
            $this->session(),
            $repository,
            $resolver,
            new TaxCloudCustomerIdentity(),
            $this->policy(),
            $this->storeManager(),
            new CertificateAttachment(
                $this->createStub(CustomerRepositoryInterface::class),
                $resolver,
                $this->logger(),
                new AttachmentWriteScope()
            ),
        ];
    }

    private function listing(): Listing
    {
        return new Listing(...$this->dependencies());
    }

    private function delete(): Delete
    {
        return new Delete(...$this->dependencies());
    }

    private function attach(): Attach
    {
        return new Attach(...$this->dependencies());
    }

    private function refresh(): Refresh
    {
        return new Refresh(...$this->dependencies());
    }

    private function add(): Add
    {
        return new Add(...array_merge($this->dependencies(), [new CertificateFormReader(), $this->logger()]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validForm(): array
    {
        return [
            'states' => ['TX', 'NY'],
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'address1' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'businessType' => 'WholesaleTrade',
            'reason' => 'Resale',
        ];
    }

    // ─── who may reach these endpoints at all ─────────────────────────────

    public function testListingRefusesASignedOutVisitor(): void
    {
        $this->loggedIn = false;

        $this->listing()->execute();

        $this->assertFalse($this->answer['success']);
    }

    public function testListingRefusesWhenExemptionsAreSwitchedOff(): void
    {
        $this->exemptionsEnabled = false;
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];

        $this->listing()->execute();

        $this->assertFalse(
            $this->answer['success'],
            'a store that has not switched exemptions on must expose nothing at all'
        );
    }

    public function testDeleteRefusesASignedOutVisitor(): void
    {
        $this->loggedIn = false;
        $this->params['certificate_id'] = 'cert-tx';

        $this->delete()->execute();

        $this->assertFalse($this->answer['success']);
    }

    // ─── the ownership boundary ───────────────────────────────────────────

    public function testDeleteRefusesACertificateThatIsNotTheCustomers(): void
    {
        $this->held = [new Certificate('cert-mine', '7', ['TX'], false, false)];
        $this->params['certificate_id'] = 'cert-someone-elses';

        $this->delete()->execute();

        $this->assertFalse(
            $this->answer['success'],
            'TaxCloud performs no ownership check, so refusing here is the only thing '
            . 'stopping one customer deleting another customer\'s certificate'
        );
        $this->assertSame([], $this->deleted);
    }

    public function testDeleteRefusesAnUnknownCertificateWithTheSameAnswer(): void
    {
        $this->held = [new Certificate('cert-mine', '7', ['TX'], false, false)];

        $this->params['certificate_id'] = 'cert-someone-elses';
        $this->delete()->execute();
        $foreign = $this->answer;

        $this->params['certificate_id'] = 'cert-does-not-exist';
        $this->delete()->execute();

        $this->assertSame(
            $foreign,
            $this->answer,
            'the two must be indistinguishable, or the refusal tells an attacker which ids are real'
        );
    }

    public function testDeleteRefusesAnEmptyIdentifier(): void
    {
        $this->params['certificate_id'] = '';

        $this->delete()->execute();

        $this->assertFalse($this->answer['success']);
    }

    // ─── deleting clears the attachment ───────────────────────────────────

    public function testDeletingTheCertificateInUseClearsTheAttachment(): void
    {
        // Not a self-service privilege: any customer who may delete gets this.
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-tx';

        $this->delete()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertSame(['cert-tx'], $this->deleted);
        $this->assertSame(
            '',
            $this->attached,
            'an attachment naming a deleted certificate would block the next one from being attached'
        );
        $this->assertStringContainsString('customer 7 (My Account)', implode("\n", $this->logged));
    }

    public function testDeletingAnotherCertificateKeepsTheOneInUse(): void
    {
        $this->held = [
            new Certificate('cert-tx', '7', ['TX'], false, false),
            new Certificate('cert-ny', '7', ['NY'], false, false),
        ];
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-ny';

        $this->delete()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertSame('cert-tx', $this->attached);
    }

    // ─── "none" and "could not ask" are different answers ─────────────────

    public function testListingReportsFailureRatherThanAnEmptyList(): void
    {
        $this->readFailure = new \RuntimeException('TaxCloud is unreachable');

        $this->listing()->execute();

        $this->assertFalse(
            $this->answer['success'],
            'a failed read must never be reported as "you have no certificates" — a customer '
            . 'told that will create a duplicate of one they already hold'
        );
        $this->assertNotEmpty($this->answer['message']);
    }

    public function testListingReportsAGenuinelyEmptySetAsSuccess(): void
    {
        $this->held = [];

        $this->listing()->execute();

        $this->assertTrue(
            $this->answer['success'],
            'holding none is a real answer, and must not be dressed up as a failure'
        );
        $this->assertSame([], $this->answer['certificates']);
    }

    public function testListingOffersOnlyCertificatesCoveringTheDestination(): void
    {
        $this->held = [
            new Certificate('cert-tx', '7', ['TX'], false, false),
            new Certificate('cert-ny', '7', ['NY'], false, false),
        ];
        $this->params['state'] = 'TX';

        $this->listing()->execute();

        $ids = array_column($this->answer['certificates'], 'certificateId');
        $this->assertSame(
            ['cert-tx'],
            $ids,
            'offering a certificate that cannot apply could only mislead'
        );
    }

    // ─── what the listing tells the page ──────────────────────────────────

    public function testListingShowsEveryCustomerWhichCertificateIsInUse(): void
    {
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];
        $this->attached = 'cert-tx';

        $this->listing()->execute();

        $this->assertSame('cert-tx', $this->answer['attached']);
        $this->assertFalse(
            $this->answer['canManage'],
            'seeing which certificate is in use is not permission to change it'
        );
    }

    public function testListingTellsANominatedCustomerTheyMayManage(): void
    {
        $this->nominate();

        $this->listing()->execute();

        $this->assertTrue($this->answer['canManage']);
    }

    // ─── self-service is refused unless the customer is nominated ────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function selfServiceEndpoints(): array
    {
        return [
            'add' => ['add'],
            'attach' => ['attach'],
            'refresh' => ['refresh'],
        ];
    }

    /**
     * @dataProvider selfServiceEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEndpoints')]
    public function testSelfServiceIsRefusedWhileTheSwitchIsOff(string $endpoint): void
    {
        $this->nominatedGroups = [$this->groupId];
        $this->selfServiceEnabled = false;

        $this->callSelfService($endpoint);

        $this->assertFalse($this->answer['success']);
        $this->assertNothingChanged();
    }

    /**
     * @dataProvider selfServiceEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEndpoints')]
    public function testSelfServiceIsRefusedWithNoGroupNominated(string $endpoint): void
    {
        $this->selfServiceEnabled = true;
        $this->nominatedGroups = [];

        $this->callSelfService($endpoint);

        $this->assertFalse($this->answer['success'], 'switched on with nobody nominated means nobody');
        $this->assertNothingChanged();
    }

    /**
     * @dataProvider selfServiceEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEndpoints')]
    public function testSelfServiceIsRefusedToACustomerOutsideTheNominatedGroups(string $endpoint): void
    {
        $this->selfServiceEnabled = true;
        $this->nominatedGroups = [2];
        $this->groupId = 1;

        $this->callSelfService($endpoint);

        $this->assertFalse($this->answer['success']);
        $this->assertNothingChanged();
    }

    /**
     * @dataProvider selfServiceEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEndpoints')]
    public function testSelfServiceIsRefusedWhenExemptionsAreOff(string $endpoint): void
    {
        $this->nominate();
        $this->exemptionsEnabled = false;

        $this->callSelfService($endpoint);

        $this->assertFalse($this->answer['success']);
        $this->assertNothingChanged();
    }

    /**
     * @dataProvider selfServiceEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEndpoints')]
    public function testSelfServiceIsRefusedToASignedOutVisitor(string $endpoint): void
    {
        $this->nominate();
        $this->loggedIn = false;

        $this->callSelfService($endpoint);

        $this->assertFalse($this->answer['success']);
        $this->assertNothingChanged();
    }

    private function callSelfService(string $endpoint): void
    {
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];
        $this->params = [
            'certificate_id' => 'cert-tx',
            'attestation' => '1',
            'certificate' => $this->validForm(),
        ];

        $this->{$endpoint}()->execute();
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame([], $this->created, 'nothing may be filed');
        $this->assertSame('', $this->attached, 'nothing may be attached');
        $this->assertSame([], $this->invalidated, 'nothing may be refreshed');
    }

    // ─── creating ─────────────────────────────────────────────────────────

    public function testANominatedCustomerCreatesAndAttachesTheirFirstCertificate(): void
    {
        $this->nominate();
        $this->params = ['attestation' => '1', 'certificate' => $this->validForm()];

        $this->add()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertSame('7', $this->created[0][0], 'filed under the session customer\'s identity');
        $this->assertSame(['TX', 'NY'], $this->created[0][1]['states']);
        $this->assertTrue($this->answer['attached']);
        $this->assertSame('cert-new', $this->attached, 'a first certificate applies without a second step');
    }

    public function testCreatingDoesNotDisplaceACertificateStillInUse(): void
    {
        $this->nominate();
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];
        $this->attached = 'cert-tx';
        $this->params = ['attestation' => '1', 'certificate' => $this->validForm()];

        $this->add()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertFalse($this->answer['attached']);
        $this->assertSame('cert-tx', $this->attached);
    }

    public function testCreatingReplacesAnAttachmentToACertificateThatNoLongerExists(): void
    {
        $this->nominate();
        $this->held = [];
        $this->attached = 'cert-deleted-in-portal';
        $this->params = ['attestation' => '1', 'certificate' => $this->validForm()];

        $this->add()->execute();

        $this->assertTrue($this->answer['attached']);
        $this->assertSame(
            'cert-new',
            $this->attached,
            'a stale attachment exempts nothing and must not keep the new certificate from applying'
        );
    }

    public function testCreatingWithoutTheAttestationFilesNothing(): void
    {
        $this->nominate();
        $this->params = ['certificate' => $this->validForm()];

        $this->add()->execute();

        $this->assertFalse(
            $this->answer['success'],
            'the attestation is checked by the server, not only by the page'
        );
        $this->assertSame([], $this->created);
    }

    public function testAnInvalidFormFilesNothing(): void
    {
        $this->nominate();
        $form = $this->validForm();
        $form['states'] = [];
        $this->params = ['attestation' => '1', 'certificate' => $form];

        $this->add()->execute();

        $this->assertFalse($this->answer['success']);
        $this->assertSame([], $this->created);
    }

    public function testTheRequestCannotChooseWhoseCertificateItBecomes(): void
    {
        $this->nominate();
        $this->params = [
            'attestation' => '1',
            'customer_id' => 99,
            'identity' => 'someone-else',
            'certificate' => $this->validForm() + ['customerId' => 'someone-else'],
        ];

        $this->add()->execute();

        $this->assertSame('7', $this->created[0][0]);
    }

    public function testCreationIsLoggedAsTheCustomersOwnClaim(): void
    {
        $this->nominate();
        $this->params = ['attestation' => '1', 'certificate' => $this->validForm()];

        $this->add()->execute();

        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('cert-new', $log);
        $this->assertStringContainsString('customer 7 (My Account)', $log);
        $this->assertStringContainsString('attested', $log);
    }

    // ─── attaching ────────────────────────────────────────────────────────

    public function testANominatedCustomerChangesTheCertificateInUse(): void
    {
        $this->nominate();
        $this->held = [
            new Certificate('cert-tx', '7', ['TX'], false, false),
            new Certificate('cert-ny', '7', ['NY'], false, false),
        ];
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-ny';

        $this->attach()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertSame('cert-ny', $this->attached);
        $this->assertStringContainsString('customer 7 (My Account)', implode("\n", $this->logged));
    }

    public function testANominatedCustomerClearsTheCertificateInUse(): void
    {
        $this->nominate();
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = '';

        $this->attach()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertSame('', $this->attached);
    }

    public function testAttachingSomeoneElsesCertificateIsRefusedLikeAnUnknownOne(): void
    {
        $this->nominate();
        $this->held = [new Certificate('cert-mine', '7', ['TX'], false, false)];

        $this->params['certificate_id'] = 'cert-someone-elses';
        $this->attach()->execute();
        $foreign = $this->answer;

        $this->params['certificate_id'] = 'cert-does-not-exist';
        $this->attach()->execute();

        $this->assertFalse($foreign['success']);
        $this->assertSame($foreign, $this->answer);
        $this->assertSame('', $this->attached, 'TaxCloud would honour it, so this refusal is the only guard');
    }

    // ─── refreshing ───────────────────────────────────────────────────────

    public function testANominatedCustomerRefreshesTheirOwnCertificates(): void
    {
        $this->nominate();
        $this->params['identity'] = 'someone-else';

        $this->refresh()->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertSame(['7'], $this->invalidated);
    }
}
