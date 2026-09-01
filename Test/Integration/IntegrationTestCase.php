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
     * client cached from an earlier test. This walks the graph from the
     * \SoapClient outward: SoapGateway holds (and caches) the client;
     * SoapCertificateGateway and Api hold the SoapGateway; Tax and the observers
     * hold Api. Evicting all of them forces the next resolution to rebuild the
     * whole chain around the mock ClientFactory.
     *
     * @var string[]
     */
    private const SOAP_DEPENDENT_TYPES = [
        \Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway::class,
        \Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway::class,
        \Taxcloud\Magento2\Model\Api::class,
        // The router is the di preference for every gateway interface and holds
        // the Api instance; left cached, consumers would keep reaching a SOAP
        // client from before the mock swap.
        \Taxcloud\Magento2\Model\Gateway\Router::class,
        \Taxcloud\Magento2\Model\Tax::class,
        \Taxcloud\Magento2\Observer\Sales\Complete::class,
        \Taxcloud\Magento2\Observer\Sales\Refund::class,
        \Taxcloud\Magento2\Observer\Sales\Address::class,
        // Cancellation runs through a plugin, not an observer: the plugin holds
        // the processor, the processor holds the gateway (Api). Both cached
        // singletons must be evicted or a cancel reversal talks to a client
        // from an earlier test's mock.
        \Taxcloud\Magento2\Model\Order\CancellationProcessor::class,
        \Taxcloud\Magento2\Plugin\Sales\OrderCancellation::class,
    ];

    /** Store view code created by scripts/seed-test-data.php (TaxCloud disabled there). */
    protected const SECOND_STORE_CODE = 'second';

    private ?RecordingSoapClient $soapClient = null;

    /**
     * Prior core_config_data state for every row written via
     * {@see setScopedConfig()}, keyed "scope|scopeId|path". Restored (or
     * deleted, when the row didn't exist) in tearDown so config changes never
     * leak across tests.
     *
     * @var array<string, array{scope: string, scopeId: int, path: string, value: string|null}>
     */
    private array $configSnapshots = [];

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

    protected function tearDown(): void
    {
        $this->restoreScopedConfig();
        parent::tearDown();
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
        // instances live in a protected array. Evict the SOAP-dependent
        // singletons and seed the mock factory so the next resolution rebuilds
        // the graph around the mock.
        $this->mutateSharedInstances(self::SOAP_DEPENDENT_TYPES, [ClientFactory::class => $factory]);

        // The tax total collector (Taxcloud\Model\Tax) is built once via
        // TotalFactory::create() and then cached inside TotalsCollectorList — so
        // evicting the shared Api alone does NOT reach it. Drop that cache so the
        // next collectTotals() rebuilds the collector around this test's mock
        // (its freshly-evicted Api) instead of the first test's client.
        $this->resetTotalsCollector();

        // Plugin instances are ALSO cached inside the shared PluginList
        // (Interception\PluginList::$_pluginInstances), independently of the
        // ObjectManager's shared-instance array. Without clearing it, the
        // OrderCancellation plugin (and its processor -> gateway chain) built
        // around the FIRST test's mock keeps serving every later cancel.
        $this->resetPluginInstances();

        // lookup/verifyAddress results are cached (cache_lifetime defaults to
        // 86400s, in Redis) keyed by request hash — and an unsaved quote has a
        // null cartID, so the key collides across tests. Flush the TaxCloud cache
        // type so each test's first lookup actually reaches the (mock) SOAP layer.
        // clean() on this frontend is scoped to the TaxCloud tag, so it leaves
        // every other cache type intact.
        $this->get(\Taxcloud\Magento2\Model\Cache\Type\Taxcloud::class)->clean();

        return $client;
    }

    /**
     * Clear the interception layer's cached plugin instances so plugins are
     * re-resolved from the (freshly mutated) ObjectManager on next use.
     */
    private function resetPluginInstances(): void
    {
        $pluginList = $this->get(\Magento\Framework\Interception\PluginListInterface::class);
        if (!$pluginList instanceof \Magento\Framework\Interception\PluginList\PluginList) {
            return;
        }
        \Closure::bind(
            function () {
                $this->_pluginInstances = [];
            },
            $pluginList,
            \Magento\Framework\Interception\PluginList\PluginList::class
        )();
    }

    /**
     * Force the quote tax-total collector to be rebuilt on the next totals
     * collection by clearing TotalsCollectorList's cached collector.
     */
    private function resetTotalsCollector(): void
    {
        $collectorList = $this->get(\Magento\Quote\Model\Quote\TotalsCollectorList::class);
        \Closure::bind(
            function () {
                $this->totalCollector = null;
            },
            $collectorList,
            \Magento\Quote\Model\Quote\TotalsCollectorList::class
        )();
    }

    /**
     * Drop and/or replace shared (singleton) instances held by the production
     * ObjectManager. Used to force parts of the object graph to rebuild — e.g.
     * around the SOAP mock, or to clear Magento's in-request tax-rate cache so a
     * freshly created tax rule is seen.
     *
     * @param string[]              $unset class names to evict
     * @param array<string, object> $set   class name => instance to seed
     */
    protected function mutateSharedInstances(array $unset, array $set = []): void
    {
        // Captured into locals because the bound closure runs in the
        // ObjectManager's scope, not this class's.
        $mutate = \Closure::bind(
            function () use ($unset, $set) {
                foreach ($unset as $type) {
                    unset($this->_sharedInstances[$type]);
                }
                foreach ($set as $type => $instance) {
                    $this->_sharedInstances[$type] = $instance;
                }
            },
            $this->objectManager(),
            ConcreteObjectManager::class
        );
        $mutate();
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
    /**
     * The happy-path response set with individual operations swapped out. Use
     * this rather than hand-building the array when a test only cares about one
     * operation — installSoapMock() replaces the whole set when given a
     * non-empty array, so a partial array would leave the other operations
     * unstubbed.
     *
     * @param array<string, mixed> $overrides SOAP method => response
     * @return array<string, mixed>
     */
    protected function soapResponsesWith(array $overrides): array
    {
        return array_merge($this->defaultSoapResponses(), $overrides);
    }

    /**
     * Load a canned response from Test/Integration/_files/soap_responses/.
     *
     * @return mixed
     */
    protected function soapResponseFixture(string $name)
    {
        return require __DIR__ . '/_files/soap_responses/' . $name . '.php';
    }

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

    /**
     * Write (or delete, with $value = null) a core_config_data row at an
     * arbitrary scope, snapshotting the prior state so tearDown restores it
     * exactly — including deleting rows that didn't exist before. This is what
     * multi-store tests use to flip per-store values without leaking config
     * into later tests.
     *
     * @param string      $path      e.g. 'tax/taxcloud_settings/enabled'
     * @param string|null $value     null deletes the row
     * @param string      $scopeType 'default', 'websites' or 'stores'
     * @param int         $scopeId   0 for default scope
     */
    protected function setScopedConfig(
        string $path,
        ?string $value,
        string $scopeType = 'default',
        int $scopeId = 0
    ): void {
        $key = $scopeType . '|' . $scopeId . '|' . $path;
        if (!array_key_exists($key, $this->configSnapshots)) {
            $this->configSnapshots[$key] = [
                'scope'   => $scopeType,
                'scopeId' => $scopeId,
                'path'    => $path,
                'value'   => $this->readConfigRow($path, $scopeType, $scopeId),
            ];
        }

        $this->applyConfigRow($path, $value, $scopeType, $scopeId);
        $this->get(ReinitableConfigInterface::class)->reinit();
    }

    /**
     * Convenience: store-scope write for the seeded second store view.
     */
    protected function setSecondStoreConfig(string $path, ?string $value): void
    {
        $this->setScopedConfig($path, $value, 'stores', $this->secondStoreId());
    }

    /**
     * The seeded second store view's id (resolved, not hard-coded).
     */
    protected function secondStoreId(): int
    {
        return (int) $this->get(StoreManagerInterface::class)
            ->getStore(self::SECOND_STORE_CODE)
            ->getId();
    }

    /**
     * Pin the ambient (current) store to the default store view.
     *
     * Multi-store tests use this before an admin-side action on a second-store
     * order, so the ambient store provably differs from the order's store —
     * a correct outcome can then only come from order-store config resolution.
     *
     * The ambient store cannot merely be *asserted*: on Magento 2.4.7,
     * Order\Address\Renderer::format() calls setCurrentStore() during order
     * placement and never restores it, so after placing a second-store order
     * the ambient store IS the second store. (2.4.8+ restores it.) Pinning
     * makes the tests deterministic across versions.
     */
    protected function pinAmbientStoreToDefault(): void
    {
        $storeManager = $this->get(StoreManagerInterface::class);
        $storeManager->setCurrentStore('default');

        $this->assertNotSame(
            self::SECOND_STORE_CODE,
            (string) $storeManager->getStore()->getCode(),
            'Test precondition: the ambient store must differ from the second store after pinning.'
        );
    }

    /**
     * The raw core_config_data value at exactly this scope row, or null when
     * absent. Reads the DB directly — scopeConfig would apply fallback.
     */
    private function readConfigRow(string $path, string $scopeType, int $scopeId): ?string
    {
        $connection = $this->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('core_config_data'), ['value'])
            ->where('scope = ?', $scopeType)
            ->where('scope_id = ?', $scopeId)
            ->where('path = ?', $path);
        $value = $connection->fetchOne($select);

        return $value === false ? null : (string) $value;
    }

    private function applyConfigRow(string $path, ?string $value, string $scopeType, int $scopeId): void
    {
        /** @var \Magento\Framework\App\Config\Storage\WriterInterface $writer */
        $writer = $this->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
        if ($value === null) {
            $writer->delete($path, $scopeType, $scopeId);
        } else {
            $writer->save($path, $value, $scopeType, $scopeId);
        }
    }

    private function restoreScopedConfig(): void
    {
        if (!$this->configSnapshots) {
            return;
        }
        foreach ($this->configSnapshots as $snapshot) {
            $this->applyConfigRow(
                $snapshot['path'],
                $snapshot['value'],
                $snapshot['scope'],
                $snapshot['scopeId']
            );
        }
        $this->configSnapshots = [];
        $this->get(ReinitableConfigInterface::class)->reinit();
    }

    // -- Catalog fixtures ------------------------------------------------------

    /**
     * Run $callback with Magento's `isSecureArea` flag set.
     *
     * Catalog deletes go through Magento\Framework\Validator\Model\ActionValidator,
     * which forbids removing a product or category outside the admin area unless
     * that flag is registered — so a fixture cleanup that skips it silently fails
     * ("Delete operation is forbidden for current area") and leaks rows into the
     * next test, where they resurface as url-key collisions.
     *
     * @param callable $callback
     * @return mixed whatever $callback returns
     */
    protected function inSecureArea(callable $callback)
    {
        $registry = $this->get(\Magento\Framework\Registry::class);
        $previous = $registry->registry('isSecureArea');

        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);

        try {
            return $callback();
        } finally {
            $registry->unregister('isSecureArea');
            if ($previous !== null) {
                $registry->register('isSecureArea', $previous);
            }
        }
    }

    // -- Sales-flow helpers ----------------------------------------------------

    /**
     * Build a quote for the seeded test product and place a real order through
     * Magento\Quote\Api\CartManagementInterface::placeOrder(). Placing the
     * order fires sales_order_place_after.
     */
    /**
     * Build a guest quote (store, billing/shipping address, checkmo payment) with
     * no items yet. Caller adds products, then calls {@see collectAndSaveQuote()}.
     *
     * @param array<string, mixed> $addressOverride fields to override on the
     *        ship-to/bill-to address (e.g. region_id/region/postcode) — used by
     *        the native-tax test to ship to a state no other test touches, so it
     *        doesn't collide on Magento's region|postcode-keyed tax-rate cache.
     * @param string $storeCode store view the quote belongs to — multi-store
     *        tests pass SECOND_STORE_CODE to build a second-store cart.
     */
    protected function newGuestQuote(array $addressOverride = [], string $storeCode = 'default'): Quote
    {
        $om = $this->objectManager();

        /** @var StoreManagerInterface $storeManager */
        $storeManager = $this->get(StoreManagerInterface::class);
        $store = $storeManager->getStore($storeCode);

        // Ship-to in Austin TX (region 57), matching the seeded origin. SOAP is
        // mocked, so the actual tax/destination is immaterial to most assertions
        // (the native-tax test overrides what matters with its own tax rule).
        $addressData = array_merge([
            'firstname'  => 'Test',
            'lastname'   => 'Buyer',
            'street'     => '1401 Lavaca St',
            'city'       => 'Austin',
            'country_id' => 'US',
            'region_id'  => 57, // Texas
            'region'     => 'Texas',
            'postcode'   => '78701',
            'telephone'  => '5125550100',
            'email'      => 'guest@example.com',
        ], $addressOverride);

        // Build addresses as Quote\Address objects (Magento's own headless-order
        // fixtures do this) rather than addData() on lazily-created addresses.
        $billingAddress = $om->create(\Magento\Quote\Model\Quote\Address::class, ['data' => $addressData]);
        $billingAddress->setAddressType('billing');
        $shippingAddress = clone $billingAddress;
        $shippingAddress->setId(null)->setAddressType('shipping');

        /** @var Quote $quote */
        $quote = $om->create(Quote::class);
        $quote->setCustomerIsGuest(true)
            ->setStoreId((int) $store->getId())
            ->setCustomerEmail('guest@example.com')
            ->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress);

        // setMethod() (not importData()) avoids getMethodInstance() touching the
        // quote before it has an ID — see Quote\Payment::getMethodInstance().
        $quote->getPayment()->setMethod('checkmo');
        $quote->setIsMultiShipping('0');

        return $quote;
    }

    /**
     * Set the shipping method, run totals collection (which drives the tax
     * collector pipeline), and persist the quote.
     */
    protected function collectAndSaveQuote(Quote $quote): Quote
    {
        $quote->getShippingAddress()
            ->setShippingMethod('flatrate_flatrate')
            ->setCollectShippingRates(true);

        $quote->collectTotals();
        $this->get(CartRepositoryInterface::class)->save($quote);

        return $quote;
    }

    /**
     * Build, collect and save a guest quote containing the seeded test product.
     *
     * @param array<string, mixed> $addressOverride see {@see newGuestQuote()}
     * @param string $storeCode see {@see newGuestQuote()}
     */
    protected function buildQuoteWithTestProduct(
        int $qty = 1,
        array $addressOverride = [],
        string $storeCode = 'default'
    ): Quote {
        $quote = $this->newGuestQuote($addressOverride, $storeCode);
        $product = $this->get(ProductRepositoryInterface::class)->get(self::TEST_PRODUCT_SKU);
        $quote->addProduct($product, $qty);

        return $this->collectAndSaveQuote($quote);
    }

    protected function placeOrder(string $storeCode = 'default', int $qty = 1): Order
    {
        $quote = $this->buildQuoteWithTestProduct($qty, [], $storeCode);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * Re-read the order straight from the database, bypassing the repository's
     * identity map.
     *
     * Observer\Sales\Complete writes taxcloud_captured with
     * ResourceModel\Order::saveAttribute() on its own order instance, so a test
     * holding an earlier instance can see a stale value either way. Loading a
     * fresh model is what makes an assertion on that column mean "this is what
     * the cancel flow will read".
     */
    protected function reloadOrder(Order $order): Order
    {
        /** @var Order $fresh */
        $fresh = $this->objectManager()->create(Order::class);
        $this->get(\Magento\Sales\Model\ResourceModel\Order::class)->load($fresh, (int) $order->getId());

        return $fresh;
    }

    /**
     * Assert whether the order carries the taxcloud_captured flag the cancel
     * flow reads. Always reloads, so callers cannot accidentally assert against
     * an in-memory instance the observer never touched.
     */
    protected function assertOrderCapturedFlag(Order $order, bool $expected, string $message = ''): void
    {
        $actual = (bool) $this->reloadOrder($order)->getData('taxcloud_captured');

        $this->assertSame($expected, $actual, $message ?: sprintf(
            'Expected taxcloud_captured to be %s on order %s.',
            $expected ? 'set' : 'unset',
            $order->getIncrementId()
        ));
    }

    /**
     * Invoice only part of the order and pay it. The first partial invoice still
     * fires sales_order_invoice_pay with an invoice collection of exactly one,
     * which is all the capture observer's dedupe looks at.
     *
     * @param array<int, float> $qtys order item id => qty to invoice
     */
    protected function payPartialInvoice(Order $order, array $qtys): InvoiceInterface
    {
        $om = $this->objectManager();

        /** @var Invoice $invoice */
        $invoice = $this->get(InvoiceService::class)->prepareInvoice($order, $qtys);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();

        $transaction = $om->create(\Magento\Framework\DB\Transaction::class);
        $transaction->addObject($invoice)->addObject($invoice->getOrder())->save();

        return $invoice;
    }

    /**
     * Invoice the order asking for NO capture — the case an admin picks for a
     * payment settled outside Magento.
     *
     * Note this does not guarantee the invoice goes unpaid: Invoice::register()
     * only honors the requested capture case when canCapture() is true, and
     * pays anyway for non-gateway (offline) methods. Named for what the caller
     * requests, not for the outcome.
     */
    protected function createUnpaidInvoice(Order $order): InvoiceInterface
    {
        $om = $this->objectManager();

        /** @var Invoice $invoice */
        $invoice = $this->get(InvoiceService::class)->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::NOT_CAPTURE);
        $invoice->register();

        $transaction = $om->create(\Magento\Framework\DB\Transaction::class);
        $transaction->addObject($invoice)->addObject($invoice->getOrder())->save();

        return $invoice;
    }

    /**
     * Attach a tracking number to an existing shipment and save it, mirroring
     * Magento\Shipping\Controller\Adminhtml\Order\Shipment\AddTrack.
     *
     * The track is what makes this reproduce the admin action: AbstractModel
     * skips the save (and therefore the sales_order_shipment_save_after
     * dispatch) when nothing on the model changed, so re-saving an untouched
     * shipment would silently prove nothing.
     */
    protected function addTrackingToShipment(ShipmentInterface $shipment, string $number = '1Z-TEST-0001'): void
    {
        $track = $this->objectManager()->create(\Magento\Sales\Model\Order\Shipment\Track::class)
            ->setNumber($number)
            ->setCarrierCode('custom')
            ->setTitle('Test carrier');

        $shipment->addTrack($track);

        $this->get(ShipmentRepositoryInterface::class)->save($shipment);
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
     * Ship part of an order: one shipment covering only the given item
     * quantities, leaving the rest to ship later. Each save fires
     * sales_order_shipment_save_after, which is what drives the capture
     * observer on the shipment trigger.
     *
     * @param array<int, float> $qtys order item id => qty to ship
     */
    protected function createPartialShipment(Order $order, array $qtys): ShipmentInterface
    {
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
