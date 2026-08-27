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

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Controller\Certificate\Add;
use Taxcloud\Magento2\Controller\Certificate\Delete;
use Taxcloud\Magento2\Controller\Certificate\Listing;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * What the storefront endpoints refuse.
 *
 * These controllers are the only part of the feature a shopper can reach
 * directly, so their refusals are the boundary: a signed-out request, a store
 * with exemptions switched off, a customer outside the groups a merchant
 * vouched for, and — the one that matters most — a certificate identifier that
 * belongs to somebody else. Nothing about a certificate id is secret or
 * unguessable, and TaxCloud itself performs no ownership check, so the only
 * thing standing between one customer's exemptions and another's is these four
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

    /** @var int[] Groups the store treats as exempt */
    private $exemptGroups = [];

    /** @var bool */
    private $exemptionsEnabled = true;

    /** @var Certificate[] */
    private $held = [];

    /** @var \Throwable|null Raised instead of returning certificates */
    private $readFailure;

    protected function setUp(): void
    {
        $this->answer = [];
        $this->params = [];
        $this->loggedIn = true;
        $this->exemptGroups = [];
        $this->exemptionsEnabled = true;
        $this->held = [];
        $this->readFailure = null;
    }

    private function customer()
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getGroupId')->willReturn(1);
        $customer->method('getCustomAttribute')->willReturn(null);

        return $customer;
    }

    private function policy(): ExemptionPolicy
    {
        $config = $this->createStub(\Taxcloud\Magento2\Model\Config\TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('areExemptionsEnabled')->willReturnCallback(function () {
            return $this->exemptionsEnabled;
        });
        $config->method('getExemptCustomerGroups')->willReturnCallback(function () {
            return $this->exemptGroups;
        });
        $config->method('isRestrictedToExemptGroups')->willReturn(false);

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

    private function resolver(): CertificateResolver
    {
        return new CertificateResolver(
            $this->repository(),
            new TaxCloudCustomerIdentity(),
            $this->policy()
        );
    }

    /**
     * @return array{0: Context, 1: Session, 2: CertificateRepository, 3: CertificateResolver,
     *               4: TaxCloudCustomerIdentity, 5: ExemptionPolicy, 6: StoreManagerInterface}
     */
    private function dependencies(): array
    {
        return [
            $this->context(),
            $this->session(),
            $this->repository(),
            $this->resolver(),
            new TaxCloudCustomerIdentity(),
            $this->policy(),
            $this->storeManager(),
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

    private function add(): Add
    {
        return new Add(...array_merge($this->dependencies(), [new CertificateFormReader()]));
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

    public function testCreationIsRefusedOutsideTheExemptGroups(): void
    {
        // Visible to any signed-in customer, but creating is narrower: asserting
        // an exemption nobody verifies is confined to customers the merchant
        // has already vouched for.
        $this->exemptGroups = [];
        $this->params['certificate'] = ['states' => ['TX']];

        $this->add()->execute();

        $this->assertFalse($this->answer['success']);
        $this->assertStringContainsString('cannot add', $this->answer['message']);
    }

    public function testCreationIsAllowedInsideTheExemptGroups(): void
    {
        $this->exemptGroups = [1];
        // Deliberately incomplete, so this stops at the form check rather than
        // reaching TaxCloud — what is under test is that it got past the gate.
        $this->params['certificate'] = ['states' => []];

        $this->add()->execute();

        $this->assertFalse($this->answer['success']);
        $this->assertStringNotContainsString(
            'cannot add',
            $this->answer['message'],
            'an exempt-group customer must get past the permission gate to the form itself'
        );
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
}
