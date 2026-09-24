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
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Controller\Certificate\Add;
use Taxcloud\Magento2\Controller\Certificate\Attach;
use Taxcloud\Magento2\Controller\Certificate\Delete;
use Taxcloud\Magento2\Controller\Certificate\Listing;
use Taxcloud\Magento2\Controller\Certificate\Refresh;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Customer certificate self-service, through the real storefront controllers,
 * session, config, repository guard and certificate stack — only the v3
 * transport is a double, answering from an in-memory certificate store.
 *
 * What unit tests cannot show: that the store-scoped settings are read from
 * real config per store, that the group comes from the persisted customer,
 * that every write lands on the customer record and survives a reload, and
 * that the repository guard lets certificate management through while a plain
 * customer save carrying the same attribute is refused.
 *
 * Works on a customer of its own in the Wholesale group, created and deleted
 * here; the seeded exempt customer is shared with the e2e suite.
 */
class CustomerSelfServiceTest extends IntegrationTestCase
{
    /** Magento's stock Wholesale group. */
    private const NOMINATED_GROUP = 2;

    /** Magento's stock General group. */
    private const OTHER_GROUP = 1;

    /** @var int|null */
    private $customerId;

    /**
     * The certificates "TaxCloud" holds, keyed by id.
     *
     * @var array<string, array<string, mixed>>
     */
    private $held = [];

    /** @var int */
    private $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->held = [];
        $this->nextId = 1;

        $this->installRestMock($this->restRespondersWith([
            'GET /tax/exemption-certificates' => function (): RestResponse {
                return new RestResponse(200, (string) json_encode(['items' => array_values($this->held)]));
            },
            'POST /exemption-certificates' => function (?array $body): RestResponse {
                $id = 'cert-' . $this->nextId++;
                $this->held[$id] = ['certificateId' => $id] + (array) $body;

                return new RestResponse(200, (string) json_encode(['certificateId' => $id]));
            },
            'DELETE /exemption-certificates/' => function (): RestResponse {
                $calls = $this->restMock()->getCalls();
                $last = end($calls);
                unset($this->held[rawurldecode(basename((string) $last['path']))]);

                return new RestResponse(204, '');
            },
        ]));

        $this->setScopedConfig('tax/taxcloud_settings/api_type', 'rest');
        $this->setScopedConfig('tax/taxcloud_settings/exemptions_enabled', '1');
        $this->setScopedConfig('tax/taxcloud_settings/customer_certificates_enabled', '1');
        $this->setScopedConfig(
            'tax/taxcloud_settings/customer_certificate_groups',
            (string) self::NOMINATED_GROUP
        );


        $this->get(StoreManagerInterface::class)->setCurrentStore('default');
        $this->customerId = $this->createCustomer(self::NOMINATED_GROUP);
        $this->get(Session::class)->setCustomerId($this->customerId);
    }

    protected function tearDown(): void
    {
        $this->get(Session::class)->setCustomerId(null);
        $this->get(RequestInterface::class)->clearParams();

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

    private function createCustomer(int $groupId): int
    {
        /** @var CustomerInterface $customer */
        $customer = $this->get(CustomerInterfaceFactory::class)->create();
        $customer->setFirstname('Self');
        $customer->setLastname('Service');
        $customer->setEmail('self-service-' . uniqid('', false) . '@example.com');
        $customer->setWebsiteId(1);
        $customer->setGroupId($groupId);

        return (int) $this->get(CustomerRepositoryInterface::class)->save($customer)->getId();
    }

    private function reload(): CustomerInterface
    {
        // The repository keeps a per-request registry; clear it so this is what
        // the database holds, not what an earlier call left in memory.
        $this->get(\Magento\Customer\Model\CustomerRegistry::class)->remove($this->customerId);

        return $this->get(CustomerRepositoryInterface::class)->getById($this->customerId);
    }

    private function attached(): string
    {
        return $this->get(CertificateResolver::class)->attachedCertificateId($this->reload());
    }

    private function moveToGroup(int $groupId): void
    {
        $customer = $this->reload();
        $customer->setGroupId($groupId);
        $this->get(CustomerRepositoryInterface::class)->save($customer);
        $this->get(\Magento\Customer\Model\CustomerRegistry::class)->remove($this->customerId);
    }

    /**
     * Run a storefront certificate controller and decode its JSON answer.
     *
     * @param class-string $controller
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $controller, array $params = []): array
    {
        // setParams() merges; a parameter left from the previous call — an
        // attestation, say — would otherwise ride along into this one.
        $request = $this->get(RequestInterface::class);
        $request->clearParams();
        $request->setParams($params);

        // The session keeps the customer data object it loaded; a fresh one
        // per call is what a new request would see.
        \Closure::bind(function () {
            $this->_customer = null;
        }, $this->get(Session::class), Session::class)();

        /** @var ResultInterface $result */
        $result = $this->objectManager()->create($controller)->execute();
        $response = $this->objectManager()->create(HttpResponse::class);
        $result->renderResult($response);

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(): array
    {
        return [
            'states' => ['TX'],
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

    private function createCertificate(): array
    {
        return $this->call(Add::class, ['attestation' => '1', 'certificate' => $this->form()]);
    }

    // ─── the nomination ───────────────────────────────────────────────────

    public function testTheNominationIsReadFromEachStoresConfig(): void
    {
        // The second store runs TaxCloud with exemptions on, but has not
        // nominated anyone for self-service.
        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/exemptions_enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/customer_certificates_enabled', '0');

        $policy = $this->get(ExemptionPolicy::class);
        $defaultStoreId = (int) $this->get(StoreManagerInterface::class)->getStore('default')->getId();

        $this->assertTrue($policy->mayManage($this->reload(), $defaultStoreId));
        $this->assertFalse(
            $policy->mayManage($this->reload(), $this->secondStoreId()),
            'the same customer is nominated on one store and not on the other'
        );
    }

    public function testNothingIsOfferedUntilAGroupIsNominated(): void
    {
        $this->setScopedConfig('tax/taxcloud_settings/customer_certificate_groups', '');

        $listing = $this->call(Listing::class);
        $this->assertTrue($listing['success']);
        $this->assertFalse($listing['canManage'], 'switched on with nobody nominated means nobody');

        $this->assertFalse($this->createCertificate()['success']);
        $this->assertSame([], $this->held, 'nothing may reach TaxCloud');
    }

    public function testLeavingTheGroupRefusesTheNextRequestButKeepsTheExemption(): void
    {
        $created = $this->createCertificate();
        $this->assertTrue($created['attached']);

        $this->moveToGroup(self::OTHER_GROUP);

        $this->assertFalse(
            $this->call(Attach::class, ['certificate_id' => ''])['success'],
            'the group is read from the customer record on each request, not from the session'
        );
        $this->assertSame(
            $created['certificateId'],
            $this->attached(),
            'the settings govern who may change things, not whether the customer stays exempt'
        );
    }

    // ─── the lifecycle ────────────────────────────────────────────────────

    public function testCreateAttachSwitchAndDeleteThroughMyAccount(): void
    {
        $first = $this->createCertificate();
        $this->assertTrue($first['success'], (string) ($first['message'] ?? ''));
        $this->assertTrue($first['attached'], 'a first certificate is put in use');
        $this->assertSame($first['certificateId'], $this->attached());
        $this->assertSame(
            (string) $this->customerId,
            $this->held[$first['certificateId']]['customerId'],
            'filed under the signed-in customer\'s identity'
        );

        $second = $this->createCertificate();
        $this->assertTrue($second['success']);
        $this->assertFalse($second['attached'], 'a certificate still in use is not displaced');
        $this->assertSame($first['certificateId'], $this->attached());

        $listing = $this->call(Listing::class);
        $this->assertSame($first['certificateId'], $listing['attached']);
        $this->assertTrue($listing['canManage']);
        $this->assertCount(2, $listing['certificates']);

        $switch = $this->call(Attach::class, ['certificate_id' => $second['certificateId']]);
        $this->assertTrue($switch['success']);
        $this->assertSame($second['certificateId'], $this->attached());

        $delete = $this->call(Delete::class, ['certificate_id' => $second['certificateId']]);
        $this->assertTrue($delete['success']);
        $this->assertArrayNotHasKey($second['certificateId'], $this->held);
        $this->assertSame('', $this->attached(), 'deleting the certificate in use clears the attachment');

        $third = $this->createCertificate();
        $this->assertTrue($third['attached'], 'with nothing in use, the next certificate takes its place');
    }

    public function testAStaleAttachmentDoesNotBlockTheNextCertificate(): void
    {
        $first = $this->createCertificate();
        // Deleted in the TaxCloud portal, behind Magento's back.
        unset($this->held[$first['certificateId']]);
        $this->call(Refresh::class);

        $second = $this->createCertificate();

        $this->assertTrue($second['attached']);
        $this->assertSame($second['certificateId'], $this->attached());
    }

    public function testACertificateWithoutTheAttestationIsNotFiled(): void
    {
        $answer = $this->call(Add::class, ['certificate' => $this->form()]);

        $this->assertFalse($answer['success']);
        $this->assertSame([], $this->held);
    }

    public function testAnotherCustomersCertificateCannotBeAttached(): void
    {
        $this->held['cert-foreign'] = [
            'certificateId' => 'cert-foreign',
            'customerId' => 'someone-else',
            'states' => [['abbreviation' => 'TX']],
        ];
        // The v3 listing is filtered by customerId; so is this double.
        $this->restMock()->respondTo('GET', '/tax/exemption-certificates', function (): RestResponse {
            $mine = array_filter($this->held, function (array $held) {
                return ($held['customerId'] ?? '') === (string) $this->customerId;
            });

            return new RestResponse(200, (string) json_encode(['items' => array_values($mine)]));
        });

        $answer = $this->call(Attach::class, ['certificate_id' => 'cert-foreign']);

        $this->assertFalse($answer['success']);
        $this->assertSame('', $this->attached());
    }

    // ─── the repository guard ─────────────────────────────────────────────

    public function testAPlainCustomerSaveCannotSetTheAttachment(): void
    {
        // What PUT /V1/customers/me with custom_attributes does: a customer-
        // facing save through the repository. The rest of the save still lands.
        $customer = $this->reload();
        $customer->setFirstname('Renamed');
        $customer->setCustomAttribute(CertificateResolver::ATTACHED_ATTRIBUTE, 'cert-chosen-by-hand');
        $this->get(CustomerRepositoryInterface::class)->save($customer);

        $reloaded = $this->reload();
        $this->assertSame('', $this->get(CertificateResolver::class)->attachedCertificateId($reloaded));
        $this->assertSame('Renamed', $reloaded->getFirstname(), 'the rest of the customer save must not be lost');
    }

    public function testAPlainCustomerSaveCannotClearTheAttachment(): void
    {
        $created = $this->createCertificate();

        $customer = $this->reload();
        $customer->setCustomAttribute(CertificateResolver::ATTACHED_ATTRIBUTE, '');
        $this->get(CustomerRepositoryInterface::class)->save($customer);

        $this->assertSame($created['certificateId'], $this->attached());
    }
}
