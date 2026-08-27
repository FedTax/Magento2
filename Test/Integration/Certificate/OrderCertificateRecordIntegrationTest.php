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
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\OrderCertificateRecord;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * What an order remembers about the certificate that exempted it.
 *
 * An order screen answers a question that cannot change after the sale: what
 * was claimed, and on what grounds. Reading that back from TaxCloud would give
 * today's answer to a question about a past order — and TaxCloud cannot answer
 * it at all once the certificate is deleted, which is exactly when someone is
 * most likely to ask.
 *
 * So the record is written once, from the certificate that actually applied,
 * and never revised. Unit tests cover the object; this covers the observer
 * firing on a real order placement and the values surviving a reload from the
 * database.
 */
class OrderCertificateRecordIntegrationTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    private const RATE = 0.10;
    private const CERT = 'cert-order-record';

    /** @var Certificate[] */
    private $held = [];

    /** @var int|null */
    private $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->held = [new Certificate(self::CERT, '1', ['TX'], false, false)];
        $this->installCertificateStub();
        // Certificate-aware, mirroring the real API: a certificate on the
        // request means no tax. The shared flat-rate responder taxes every line
        // regardless, which would make an "exempt" order come back taxed.
        $rate = self::RATE;
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => static function (array $args) use ($rate): array {
                $exempt = !empty($args['exemptCert']['CertificateID']);
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
        // Pinned, not inherited: leaving this to the environment made the taxed
        // case impossible whenever a previous run had nominated a group, and the
        // customer below belongs to group 1.
        $this->setScopedConfig('tax/taxcloud_settings/exempt_customer_groups', '');

        // A real, persisted customer: placeOrder() reloads the quote by id, so a
        // customer data object set on the in-memory quote is discarded and the
        // attachment with it — the order then comes back taxed for reasons that
        // have nothing to do with the code under test.
        $this->customerId = $this->createCustomer();
    }

    private function createCustomer(): int
    {
        /** @var CustomerInterface $customer */
        $customer = $this->get(CustomerInterfaceFactory::class)->create();
        $customer->setFirstname('Order');
        $customer->setLastname('Record');
        $customer->setEmail('order-record-' . uniqid('', false) . '@example.com');
        $customer->setWebsiteId(1);
        $customer->setGroupId(1);

        return (int) $this->get(CustomerRepositoryInterface::class)->save($customer)->getId();
    }

    protected function tearDown(): void
    {
        $this->mutateSharedInstances([
            \Magento\Framework\Webapi\Soap\ClientFactory::class,
            CertificateRepository::class,
            \Taxcloud\Magento2\Model\Certificate\CertificateResolver::class,
            \Taxcloud\Magento2\Model\Api::class,
            \Taxcloud\Magento2\Model\Gateway\Router::class,
            \Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway::class,
            \Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway::class,
            \Taxcloud\Magento2\Model\Tax::class,
        ]);

        $collectorList = $this->get(\Magento\Quote\Model\Quote\TotalsCollectorList::class);
        \Closure::bind(
            function () {
                $this->totalCollector = null;
            },
            $collectorList,
            \Magento\Quote\Model\Quote\TotalsCollectorList::class
        )();

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

    private function installCertificateStub(): void
    {
        $test = $this;
        $stub = new class ($test) extends CertificateRepository {
            /** @var OrderCertificateRecordIntegrationTest */
            private $test;

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

        $this->mutateSharedInstances(
            [\Taxcloud\Magento2\Model\Certificate\CertificateResolver::class],
            [CertificateRepository::class => $stub]
        );
    }

    /**
     * @return Certificate[]
     */
    public function supplyCertificates(): array
    {
        return $this->held;
    }

    public function testAnExemptedOrderRecordsTheCertificateThatApplied(): void
    {
        $order = $this->placeExemptOrder();
        $record = $this->get(OrderCertificateRecord::class);

        $this->assertSame(
            self::CERT,
            $record->certificateId($order),
            'the order must record which certificate exempted it'
        );

        $snapshot = $record->snapshot($order);
        $this->assertNotSame([], $snapshot, 'the order must keep a snapshot of the certificate');
        $this->assertSame(
            ['TX'],
            $snapshot['states'] ?? null,
            'the snapshot must carry the states the certificate covered at the time of sale'
        );
    }

    public function testTheRecordSurvivesReloadingTheOrder(): void
    {
        $order = $this->placeExemptOrder();

        $reloaded = $this->get(OrderRepositoryInterface::class)->get((int) $order->getId());

        $this->assertSame(
            self::CERT,
            $this->get(OrderCertificateRecord::class)->certificateId($reloaded),
            'the record must be persisted, not merely set on the in-memory order'
        );
    }

    public function testATaxedOrderCarriesNoRecord(): void
    {
        // Nothing attached and no exempt group: the order is taxed, and there is
        // no certificate to record. An empty record here is the difference
        // between "no exemption" and "we forgot to write it down".
        $order = $this->placeOrderForCustomer(null);

        $this->assertSame(
            '',
            $this->get(OrderCertificateRecord::class)->certificateId($order),
            'an order that was taxed must not claim a certificate'
        );
        $this->assertGreaterThan(0.0, (float) $order->getTaxAmount());
    }

    private function placeExemptOrder(): Order
    {
        $order = $this->placeOrderForCustomer(self::CERT);

        $this->assertSame(
            0.0,
            (float) $order->getTaxAmount(),
            'precondition: the order under test must actually have been exempted'
        );

        return $order;
    }

    private function placeOrderForCustomer(?string $attached): Order
    {
        $repository = $this->get(CustomerRepositoryInterface::class);
        $customer = $repository->getById($this->customerId);

        if ($attached !== null) {
            $this->get(CertificateAttachment::class)->set($customer, $attached, 'phpunit');
            $customer = $repository->getById($this->customerId);
        }

        $quote = $this->newGuestQuote(['email' => $customer->getEmail()]);
        $quote->setCustomerEmail($customer->getEmail())
            ->setCustomerIsGuest(false)
            ->setCustomerId($this->customerId)
            ->setCustomerGroupId(1)
            ->setCustomer($customer);

        $product = $this->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)
            ->get(self::TEST_PRODUCT_SKU, false, null, true);
        $quote->addProduct($product, 1);
        $quote = $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder($quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get((int) $orderId);
    }
}
