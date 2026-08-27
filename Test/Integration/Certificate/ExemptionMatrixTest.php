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

use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * Whether an exemption applies, and what the customer is therefore charged.
 *
 * The decision was heavily unit-tested against mocks and not tested at all
 * through real wiring: every green exemption test in the suite proved the
 * ATTACHMENT path, because the seed grants exemption by attaching a certificate
 * and nominates no exempt groups. Group auto-apply — an advertised feature with
 * its own settings field — was verified only by stubs.
 *
 * Certificates are supplied by a stub so the set is fixed and the call is
 * observable; everything downstream is real — resolver, request builder, tax
 * collector, quote totals. Two of these rows cannot be written any other way: a
 * live API cannot be made to fail on demand, and no amount of end-to-end
 * clicking can prove a call did NOT happen.
 *
 * Taxed rows assert against a control computed in the same run rather than a
 * hardcoded figure, so a rate change cannot turn this suite red for a reason
 * that has nothing to do with certificates.
 */
class ExemptionMatrixTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    private const RATE = 0.10;
    private const TX_CERT = 'cert-tx';
    private const TX_CERT_2 = 'cert-tx-2';
    private const NY_CERT = 'cert-ny';

    /** @var Certificate[] What TaxCloud holds for the customer in the row at hand */
    private $held = [];

    /** @var bool Whether reading them fails */
    private $readFails = false;

    /** @var int How many times the certificate set was actually requested */
    private $reads = 0;

    /** @var string|null The certificate id that reached the lookup payload */
    private $sentCertificateId;




    protected function setUp(): void
    {
        parent::setUp();

        $this->held = [];
        $this->readFails = false;
        $this->reads = 0;
        $this->sentCertificateId = null;

        // Before installSoapMock: that call evicts Api/Router/Tax so they rebuild
        // against the mock, and anything rebuilt before the stub is in place
        // would capture the real repository instead.
        $this->installCertificateStub();

        // Mirrors the real API: a certificate on the request means no tax.
        $rate = self::RATE;
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => function (array $args) use ($rate): array {
                $this->sentCertificateId = $args['exemptCert']['CertificateID'] ?? null;
                $exempt = !empty($this->sentCertificateId);
                $responses = [];

                foreach ($args['cartItems'] ?? [] as $line) {
                    $responses[] = [
                        'CartItemIndex' => $line['Index'],
                        'TaxAmount' => $exempt ? 0.0 : round($line['Price'] * $line['Qty'] * $rate, 2),
                    ];
                }

                return [
                    'LookupResult' => [
                        'ResponseType' => 'OK',
                        'Messages' => '',
                        'CartItemsResponse' => ['CartItemResponse' => $responses],
                    ],
                ];
            },
        ]));

        $this->setScopedConfig('tax/taxcloud_settings/exemptions_enabled', '1');
        $this->setScopedConfig('tax/taxcloud_settings/exempt_customer_groups', '');
    }

    /**
     * Evict the stub, and everything built around it.
     *
     * Without this the stub outlives the test: the object manager is shared
     * across the whole run, so every later test resolves certificates through a
     * double bound to a dead test instance. Verified — leaving it in place broke
     * four unrelated tests, including native-tax fallback.
     */
    protected function tearDown(): void
    {
        $this->mutateSharedInstances([
            // The mocked SOAP client factory too. installSoapMock() sets it and
            // nothing restores it — harmless until a test that needs a REAL
            // client runs afterwards, which LiveCertificateLifecycleTest does:
            // this file is simply the first to sort ahead of it.
            \Magento\Framework\Webapi\Soap\ClientFactory::class,
            CertificateRepository::class,
            \Taxcloud\Magento2\Model\Certificate\CertificateResolver::class,
            \Taxcloud\Magento2\Model\Api::class,
            \Taxcloud\Magento2\Model\Gateway\Router::class,
            \Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway::class,
            // Holds the SOAP client directly — this is the one that made
            // LiveCertificateLifecycleTest talk to a recording double.
            \Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway::class,
            \Taxcloud\Magento2\Model\Certificate\RestCertificateGateway::class,
            \Taxcloud\Magento2\Model\Tax::class,
        ]);

        // The collector list caches the tax total object it built around the
        // stub, in a property that survives evicting the list itself. A test
        // installing no SOAP mock never resets it for itself, so it would
        // inherit ours and collect no tax at all.
        $collectorList = $this->get(\Magento\Quote\Model\Quote\TotalsCollectorList::class);
        \Closure::bind(
            function () {
                $this->totalCollector = null;
            },
            $collectorList,
            \Magento\Quote\Model\Quote\TotalsCollectorList::class
        )();

        parent::tearDown();
    }

    /**
     * Replaces only the certificate source. The resolver, its precedence rules,
     * the ownership check and the caching layer all remain the real ones.
     */
    private function installCertificateStub(): void
    {
        $test = $this;
        $stub = new class ($test) extends CertificateRepository {
            /** @var ExemptionMatrixTest */
            private $test;

            // Deliberately does not call the parent constructor: nothing this
            // override touches needs the real collaborators.
            // phpcs:disable
            public function __construct($test)
            {
                $this->test = $test;
            }
            // phpcs:enable

            public function forCustomer($customerIdentity, $store = null)
            {
                return $this->test->supplyCertificates();
            }

            public function invalidate($customerIdentity, $store = null)
            {
            }
        };

        // The resolver is not among the base class's SOAP-dependent types, so a
        // cached one would keep the real repository it was built with.
        $this->mutateSharedInstances(
            [\Taxcloud\Magento2\Model\Certificate\CertificateResolver::class],
            [CertificateRepository::class => $stub]
        );
    }

    /**
     * Called by the stub. Public because the anonymous class needs it.
     *
     * @return Certificate[]
     */
    public function supplyCertificates(): array
    {
        $this->reads++;

        if ($this->readFails) {
            throw new \RuntimeException('TaxCloud is unreachable');
        }

        return $this->held;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function exemptionMatrixProvider(): array
    {
        return [
            // 1 — switching the interface off must not start taxing customers
            //     an administrator already exempted.
            'switch off, attached certificate still exempts' => [
                'enabled' => false, 'guest' => false, 'exemptGroup' => false,
                'held' => ['TX'], 'attached' => self::TX_CERT, 'destination' => 'TX', 'expect' => 'exempt',
            ],
            // 2 — but it does suppress what the store was doing on its own.
            'switch off suppresses group auto-apply' => [
                'enabled' => false, 'guest' => false, 'exemptGroup' => true,
                'held' => ['TX'], 'attached' => null, 'destination' => 'TX', 'expect' => 'taxed',
            ],
            'guest is taxed' => [
                'enabled' => true, 'guest' => true, 'exemptGroup' => false,
                'held' => ['TX'], 'attached' => null, 'destination' => 'TX', 'expect' => 'taxed',
            ],
            'signed in holding nothing is taxed' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => false,
                'held' => [], 'attached' => null, 'destination' => 'TX', 'expect' => 'taxed',
            ],
            // 5 — holding a covering certificate is not, on its own, enough.
            //     This is the row that surprises people.
            'holds a covering certificate but neither attached nor grouped' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => false,
                'held' => ['TX'], 'attached' => null, 'destination' => 'TX', 'expect' => 'taxed',
            ],
            'attached covering certificate exempts' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => false,
                'held' => ['TX'], 'attached' => self::TX_CERT, 'destination' => 'TX', 'expect' => 'exempt',
            ],
            // 7 — the feature with no non-mocked coverage before this test.
            'exempt group auto-applies a covering certificate' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => true,
                'held' => ['TX'], 'attached' => null, 'destination' => 'TX', 'expect' => 'exempt',
            ],
            'certificate for another state is taxed' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => true,
                'held' => ['NY'], 'attached' => null, 'destination' => 'TX', 'expect' => 'taxed',
            ],
            'the covering certificate is picked from several' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => true,
                'held' => ['NY', 'TX'], 'attached' => null, 'destination' => 'TX', 'expect' => 'exempt',
            ],
            'disabled certificate never applies' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => true,
                'held' => ['TX-disabled'], 'attached' => null, 'destination' => 'TX', 'expect' => 'taxed',
            ],
            'attached certificate not covering the destination is taxed' => [
                'enabled' => true, 'guest' => false, 'exemptGroup' => false,
                'held' => ['TX'], 'attached' => self::TX_CERT, 'destination' => 'CA', 'expect' => 'taxed',
            ],
        ];
    }

    /**
     * Both forms on purpose: Magento 2.4.7 ships PHPUnit 9.5, which does not read
     * the attribute and would otherwise run this once with no arguments.
     *
     * @param array<string> $held
     * @dataProvider exemptionMatrixProvider
     */
    #[DataProvider('exemptionMatrixProvider')]
    public function testExemptionMatrix(
        bool $enabled,
        bool $guest,
        bool $exemptGroup,
        array $held,
        ?string $attached,
        string $destination,
        string $expect
    ): void {
        $this->setScopedConfig('tax/taxcloud_settings/exemptions_enabled', $enabled ? '1' : '0');
        $this->setScopedConfig('tax/taxcloud_settings/exempt_customer_groups', $exemptGroup ? '1' : '');
        $this->held = $this->certificatesFrom($held);

        $quote = $this->quoteFor($guest, $attached, $destination);
        $tax = (float) $quote->getShippingAddress()->getTaxAmount();

        if ($expect === 'exempt') {
            $this->assertSame(0.0, $tax, sprintf(
                'an exempt order must carry no tax (certificate reads=%d, sent=%s)',
                $this->reads,
                var_export($this->sentCertificateId, true)
            ));
            $this->assertNotEmpty(
                $this->sentCertificateId,
                'the exemption must be claimed on the request, not merely decided locally'
            );

            return;
        }

        $address = $quote->getShippingAddress();
        $expected = round(((float) $address->getSubtotal() + (float) $address->getShippingAmount()) * self::RATE, 2);

        $this->assertGreaterThan(0.0, $tax, 'a taxed order must carry tax');
        $this->assertSame(
            $expected,
            $tax,
            'a taxed order must be charged on its whole basis — a certificate must not silently zero a line'
        );
        $this->assertEmpty(
            $this->sentCertificateId,
            'no certificate may be claimed on the request when none applies'
        );
    }

    /**
     * Nothing claimed and nothing automatic must not cost an API call: a store
     * that does not use exemptions should not pay a round trip per cart to be
     * told so. Unprovable from a browser, which is why it lives here.
     */
    public function testNothingClaimedCostsNoCertificateLookup(): void
    {
        $this->held = $this->certificatesFrom(['TX']);

        $this->quoteFor(false, null, 'TX');

        $this->assertSame(0, $this->reads, 'the certificate set must not be requested at all');
        $this->assertEmpty($this->sentCertificateId);
    }

    /**
     * Fail closed. "Could not ask" is not "no restrictions": reading it that way
     * would exempt an order whenever TaxCloud had a bad minute.
     */
    public function testLookupFailureTaxesTheOrder(): void
    {
        $this->setScopedConfig('tax/taxcloud_settings/exempt_customer_groups', '1');
        $this->held = $this->certificatesFrom(['TX']);
        $this->readFails = true;

        $quote = $this->quoteFor(false, null, 'TX');

        $this->assertGreaterThan(
            0.0,
            (float) $quote->getShippingAddress()->getTaxAmount(),
            'a failure to establish certificates must tax the order, never exempt it'
        );
        $this->assertEmpty($this->sentCertificateId);
    }

    /**
     * @param string[] $spec
     * @return Certificate[]
     */
    private function certificatesFrom(array $spec): array
    {
        $map = [
            'TX' => [self::TX_CERT, ['TX'], false],
            'TX2' => [self::TX_CERT_2, ['TX'], false],
            'NY' => [self::NY_CERT, ['NY'], false],
            'TX-disabled' => [self::TX_CERT, ['TX'], true],
        ];

        $out = [];
        foreach ($spec as $key) {
            [$id, $states, $disabled] = $map[$key];
            $out[] = new Certificate($id, '1', $states, $disabled, false);
        }

        return $out;
    }


    private function quoteFor(
        bool $guest,
        ?string $attached,
        string $destination,
        string $email = 'guest@example.com'
    ): Quote {
        // California rather than New York for the "elsewhere" case: Magento
        // caches "no native rate" per region in singletons that
        // ModuleDisabledFallsBackToNativeTaxTest evicts only partially, and it
        // ships to NY. Any non-covered state proves this row equally well, so
        // there is no reason to collide with it.
        $address = $destination === 'CA'
            ? ['region_id' => 12, 'region' => 'California', 'city' => 'Los Angeles', 'postcode' => '90001']
            : [];
        $address['email'] = $email;

        $quote = $this->newGuestQuote($address);
        $quote->setCustomerEmail($email);

        if (!$guest) {
            $quote->setCustomerIsGuest(false)
                ->setCustomerId(1)
                ->setCustomerGroupId(1)
                ->setCustomer($this->customerData($attached));
        }

        // forceReload: the repository caches product instances, and a cached one
        // still carries the quote-item state from the previous quote in this
        // test — the control cart silently ends up with no line at all.
        $product = $this->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)
            ->get(self::TEST_PRODUCT_SKU, false, null, true);
        $quote->addProduct($product, 1);


        return $this->collectAndSaveQuote($quote);
    }

    private function customerData(?string $attached)
    {
        $customer = $this->get(CustomerInterfaceFactory::class)->create();
        $customer->setId(1);
        $customer->setGroupId(1);
        $customer->setEmail('matrix@example.com');

        if ($attached !== null) {
            $customer->setCustomAttribute('taxcloud_certificate_id', $attached);
        }

        return $customer;
    }
}
