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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ObjectManager as ConcreteObjectManager;
use Magento\Framework\Webapi\Soap\ClientFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Test\Integration\Doubles\RecordingSoapClient;

/**
 * Base class for integration tests that run against the actual installed
 * Magento booted by Test/Integration/bootstrap.php.
 *
 * Provides two things every observer-wiring test needs:
 *
 *  1. A SOAP mock harness ({@see installSoapMock()}) that swaps the real
 *     \SoapClient for a {@see RecordingSoapClient} while keeping the rest of
 *     Magento real. See docs/INTEGRATION_TESTS.md ("Mocking the TaxCloud SOAP
 *     client") for the rationale.
 *
 *  2. Sales-flow helpers ({@see placeOrder()}, {@see payInvoice()},
 *     {@see createShipment()}, {@see cancelOrder()}, {@see refundOrder()}) that
 *     drive the real Magento order lifecycle so the events our observers listen
 *     for actually fire.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** SKU seeded by scripts/seed-test-data.php. */
    protected const TEST_PRODUCT_SKU = 'test-product';

    /**
     * Module classes whose shared (singleton) instances must be rebuilt after
     * the SOAP factory is swapped, so they pick up the mock instead of a
     * client cached from an earlier test. Everything here either holds the
     * \SoapClient (Api) or constructor-injects Api.
     *
     * @var string[]
     */
    private const SOAP_DEPENDENT_TYPES = [
        \Taxcloud\Magento2\Model\Api::class,
        \Taxcloud\Magento2\Model\Tax::class,
        \Taxcloud\Magento2\Observer\Sales\Complete::class,
        \Taxcloud\Magento2\Observer\Sales\Cancel::class,
        \Taxcloud\Magento2\Observer\Sales\Refund::class,
        \Taxcloud\Magento2\Observer\Sales\Address::class,
    ];

    private ?RecordingSoapClient $soapClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Order placement, invoicing, shipping and refunds all need an area
        // code. setAreaCode() may only be called once per process, so guard it
        // — the first test in the run sets it, the rest no-op.
        try {
            $this->get(State::class)->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set by an earlier test — fine.
        }
    }

    // -- ObjectManager access --------------------------------------------------

    final protected function objectManager()
    {
        return TestEnvironment::getObjectManager();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    final protected function get(string $class)
    {
        return TestEnvironment::get($class);
    }

    // -- SOAP mock harness -----------------------------------------------------

    /**
     * Replace \Magento\Framework\Webapi\Soap\ClientFactory in the DI container
     * with one that hands back a {@see RecordingSoapClient}, then evict the
     * cached singletons that would otherwise still hold the real client.
     *
     * Call this from a test's setUp (or at the top of the test) before the code
     * under test resolves Api. Returns the recorder so the test can both
     * pre-seed responses and assert on recorded calls.
     *
     * @param array<string, mixed> $responses SOAP method => canned response.
     *        Defaults to the full happy-path set from
     *        Test/Integration/_files/soap_responses/.
     */
    protected function installSoapMock(array $responses = []): RecordingSoapClient
    {
        $client = new RecordingSoapClient($responses ?: $this->defaultSoapResponses());
        $this->soapClient = $client;

        $factory = new class ($client) extends ClientFactory {
            private \SoapClient $client;

            public function __construct(\SoapClient $client)
            {
                $this->client = $client;
            }

            // Signature must match the parent (no return type declared there).
            public function create($wsdl, array $options = [])
            {
                return $this->client;
            }
        };

        // The production ObjectManager has no addSharedInstance(); its shared
        // instances live in a protected array. A closure bound to its class
        // scope lets us evict the SOAP-dependent singletons and seed the mock
        // factory so the next resolution rebuilds the graph around the mock.
        // The const is captured into locals because the bound closure runs in
        // the ObjectManager's scope, not this class's, and couldn't read a
        // private member of IntegrationTestCase.
        $typesToEvict = self::SOAP_DEPENDENT_TYPES;
        $factoryKey   = ClientFactory::class;
        $evictAndSeed = \Closure::bind(
            function () use ($typesToEvict, $factoryKey, $factory) {
                foreach ($typesToEvict as $type) {
                    unset($this->_sharedInstances[$type]);
                }
                $this->_sharedInstances[$factoryKey] = $factory;
            },
            $this->objectManager(),
            ConcreteObjectManager::class
        );
        $evictAndSeed();

        return $client;
    }

    protected function soapClient(): RecordingSoapClient
    {
        if ($this->soapClient === null) {
            throw new \LogicException('installSoapMock() must be called before soapClient().');
        }
        return $this->soapClient;
    }

    /**
     * The full happy-path canned-response set, loaded from the fixture files.
     *
     * @return array<string, mixed>
     */
    private function defaultSoapResponses(): array
    {
        $dir = __DIR__ . '/_files/soap_responses/';

        return [
            'lookup'                 => require $dir . 'lookup_ok_empty.php',
            'verifyAddress'          => require $dir . 'verify_address_ok.php',
            'GetExemptCertificates'  => require $dir . 'get_exempt_certificates_empty.php',
            'authorizedWithCapture'  => require $dir . 'authorized_with_capture_ok.php',
            'Returned'               => require $dir . 'returned_ok.php',
            'OrderDetails'           => require $dir . 'order_details_captured.php',
        ];
    }

    // -- Configuration ---------------------------------------------------------

    /**
     * Persist a core_config_data value (default scope) and reinitialize the
     * shared config so the already-booted scopeConfig sees it immediately.
     * Goes through PreparedValueFactory — the same path `bin/magento config:set`
     * uses — so any backend model on the field is honored.
     */
    protected function writeConfig(string $path, string $value): void
    {
        $prepared = $this->get(\Magento\Config\Model\PreparedValueFactory::class)->create(
            $path,
            $value,
            \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if ($prepared instanceof \Magento\Framework\App\Config\Value) {
            $prepared->getResource()->save($prepared);
        }

        $this->get(ReinitableConfigInterface::class)->reinit();
    }

    /**
     * Set when TaxCloud capture happens: CaptureTrigger::ORDER_CREATION,
     * ::PAYMENT or ::SHIPMENT.
     */
    protected function setCaptureTrigger(string $value): void
    {
        $this->writeConfig('tax/taxcloud_settings/capture_trigger', $value);
    }

    // -- Sales-flow helpers ----------------------------------------------------

    /**
     * Build a quote for the seeded test product and place a real order through
     * Magento\Quote\Api\CartManagementInterface::placeOrder(). Placing the
     * order fires sales_order_place_after.
     */
    protected function placeOrder(): Order
    {
        $om = $this->objectManager();

        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->get(StoreManagerInterface::class);
        $store = $storeManager->getStore('default');

        $product = $this->get(ProductRepositoryInterface::class)->get(self::TEST_PRODUCT_SKU);

        /** @var Quote $quote */
        $quote = $om->create(Quote::class);
        $quote->setStoreId((int) $store->getId());
        $quote->setIsActive(true);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('guest@example.com');
        $quote->addProduct($product, 1);

        $address = [
            'firstname'  => 'Test',
            'lastname'   => 'Buyer',
            'street'     => ['1401 Lavaca St'],
            'city'       => 'Austin',
            'country_id' => 'US',
            'region_id'  => 57, // Texas
            'region'     => 'Texas',
            'postcode'   => '78701',
            'telephone'  => '5125550100',
            'email'      => 'guest@example.com',
        ];
        $quote->getBillingAddress()->addData($address);

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($address);
        $shippingAddress->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod('flatrate_flatrate');

        $quote->setPaymentMethod('checkmo');
        $quote->getPayment()->importData(['method' => 'checkmo']);

        $quote->collectTotals();
        $this->get(CartRepositoryInterface::class)->save($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * Invoice the whole order and pay it offline. Paying the invoice fires
     * sales_order_invoice_pay (checkmo is an offline method, so registering the
     * invoice pays it).
     */
    protected function payInvoice(Order $order): InvoiceInterface
    {
        $om = $this->objectManager();

        /** @var Invoice $invoice */
        $invoice = $this->get(InvoiceService::class)->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();

        $transaction = $om->create(\Magento\Framework\DB\Transaction::class);
        $transaction->addObject($invoice)->addObject($invoice->getOrder())->save();

        return $invoice;
    }

    /**
     * Ship the whole order. Saving the shipment fires
     * sales_order_shipment_save_after.
     */
    protected function createShipment(Order $order): ShipmentInterface
    {
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            $qtys[(int) $item->getId()] = $item->getQtyOrdered();
        }

        $shipment = $this->get(ShipmentFactory::class)->create($order, $qtys);
        $shipment->register();
        $shipment->getOrder()->setIsInProcess(true);

        $this->get(ShipmentRepositoryInterface::class)->save($shipment);

        return $shipment;
    }

    /**
     * Cancel the order through the real order-management service. Cancellation
     * fires both order_cancel_after and sales_order_save_after.
     */
    protected function cancelOrder(Order $order): void
    {
        $this->get(OrderManagementInterface::class)->cancel((int) $order->getId());
    }

    /**
     * Create and process (offline) a full credit memo for the order's invoice.
     * Refunding fires sales_order_creditmemo_refund.
     */
    protected function refundOrder(Order $order): CreditmemoInterface
    {
        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, $order->getData());
        $this->get(CreditmemoService::class)->refund($creditmemo, true);

        return $creditmemo;
    }
}
