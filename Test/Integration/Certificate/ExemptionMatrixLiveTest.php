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
use Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The exemption decision against the real TaxCloud API.
 *
 * ExemptionMatrixTest covers the decision exhaustively with a stubbed
 * certificate source; this exists to prove that stub tells the truth. Three
 * rows are enough for that: an exemption that must apply, the same cart with
 * nothing claimed (so a zero in the first row means something), and a
 * certificate that does not cover where the order is going.
 *
 * No persisted customer is needed — the quote carries a customer data object,
 * so a run-unique TaxCloud identity plus a certificate created under it is the
 * whole fixture. The seeded exempt customer is never touched, following
 * LiveCertificateLifecycleTest: a certificate leaked onto it would change what
 * every other exemption test sees.
 *
 * SOAP only. Certificate transport parity is already proven by
 * LiveCertificateLifecycleTest, which creates over SOAP and reads over REST,
 * and the stubbed matrix shows the decision itself is transport-independent —
 * a second live transport here would spend API calls re-proving both.
 */
class ExemptionMatrixLiveTest extends IntegrationTestCase
{
    /** @var string|null */
    private $certificateId;

    /** @var string */
    private $identity = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Run-unique: a certificate leaked by an interrupted run must never be
        // picked up by a later one and quietly make an assertion pass.
        $this->identity = 'it-matrix-' . uniqid('', false);
        $this->certificateId = null;
    }

    protected function tearDown(): void
    {
        if ($this->certificateId !== null) {
            try {
                $this->gateway()->deleteCertificate($this->certificateId, $this->identity);
            } catch (\Throwable $e) {
                // Cleanup only; the assertion has already been made.
            }
        }

        parent::tearDown();
    }

    private function gateway(): SoapCertificateGateway
    {
        return $this->get(SoapCertificateGateway::class);
    }

    private function createTexasCertificate(): string
    {
        $this->certificateId = $this->gateway()->createCertificate($this->identity, [
            'states' => ['TX'],
            'firstName' => 'Matrix',
            'lastName' => 'Probe',
            'title' => 'Owner',
            'address1' => '1100 Congress Ave',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'businessType' => 'WholesaleTrade',
            'reason' => 'Resale',
            'reasonDescription' => 'Resale',
            'taxId' => '12-3456789',
            'taxType' => 'FEIN',
        ]);

        return $this->certificateId;
    }

    public function testCartWithNoCertificateIsTaxed(): void
    {
        $tax = $this->taxFor(null, 'TX', 'live-control@example.com');

        // The baseline every other row here depends on: if this were zero, a
        // zero in the exempt row would prove nothing at all.
        $this->assertGreaterThan(
            0.0,
            $tax,
            'the control cart must attract real tax, or the exemption assertions are vacuous'
        );
    }

    public function testAttachedCertificateExemptsTheOrder(): void
    {
        $certificateId = $this->createTexasCertificate();

        $tax = $this->taxFor($certificateId, 'TX', 'live-exempt@example.com');

        $this->assertSame(
            0.0,
            $tax,
            'TaxCloud must return no tax for a cart claiming a certificate that covers the destination'
        );
    }

    public function testCertificateIsNotAppliedWhereItDoesNotCover(): void
    {
        $certificateId = $this->createTexasCertificate();

        // TaxCloud records the covered states but does not enforce them — this
        // module does. So this row proves OUR coverage check, and it is the one
        // that would silently exempt every state if that check regressed.
        $tax = $this->taxFor($certificateId, 'CA', 'live-wrongstate@example.com');

        $this->assertGreaterThan(
            0.0,
            $tax,
            'a Texas certificate must not exempt an order shipped to California'
        );
    }

    private function taxFor(?string $attached, string $destination, string $email): float
    {
        $address = $destination === 'CA'
            ? ['region_id' => 12, 'region' => 'California', 'city' => 'Los Angeles', 'postcode' => '90001']
            : [];
        $address['email'] = $email;

        $quote = $this->newGuestQuote($address);
        $quote->setCustomerEmail($email)
            ->setCustomerIsGuest(false)
            ->setCustomerId(1)
            ->setCustomerGroupId(1)
            ->setCustomer($this->customerData($attached));

        $product = $this->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)
            ->get(self::TEST_PRODUCT_SKU, false, null, true);
        $quote->addProduct($product, 1);

        $quote = $this->collectAndSaveQuote($quote);

        return (float) $quote->getShippingAddress()->getTaxAmount();
    }

    private function customerData(?string $attached)
    {
        $customer = $this->get(CustomerInterfaceFactory::class)->create();
        $customer->setId(1);
        $customer->setGroupId(1);
        $customer->setEmail('matrix-live@example.com');
        // The identity the certificate was filed under, so the module looks in
        // the right place rather than under the Magento entity id.
        $customer->setCustomAttribute('taxcloud_customer_id', $this->identity);

        if ($attached !== null) {
            $customer->setCustomAttribute('taxcloud_certificate_id', $attached);
        }

        return $customer;
    }
}
