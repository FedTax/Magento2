<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Sales;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Observer\Sales\Complete;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Section 4: capture-trigger routing in Complete observer.
 *
 * Complete.php maps the configured capture_trigger to exactly one Magento event
 * and runs the TaxCloud authorizeCapture call only when that event fires. The
 * tests here pin the routing — wrong-event must be a no-op, repeat invoices /
 * shipments must dedupe, and the disabled/empty-config corners must behave.
 */
#[AllowMockObjectsWithoutExpectations]
class CompleteTest extends TestCase
{
    /**
     * Store id carried by every order built in this test. The config map is
     * keyed on it, so a regression back to ambient-store (null-scope) config
     * reads resolves enabled to null and the tests fail.
     */
    private const ORDER_STORE_ID = 2;

    /**
     * Every event Complete is registered on in etc/events.xml. The routing
     * contract tests sweep all three per trigger, so a trigger that captures on
     * none of them (or on more than one) is caught.
     */
    private const ALL_LIFECYCLE_EVENTS = [
        'sales_order_place_after',
        'sales_order_invoice_pay',
        'sales_order_shipment_save_after',
    ];

    /**
     * Build a TaxcloudConfig whose store-2-scoped values honor the given
     * enabled flag, capture_trigger and calculations_only flag.
     */
    private function buildScopeConfig(
        $enabled,
        $captureTrigger,
        $calculationsOnly = '0'
    ): \Taxcloud\Magento2\Model\Config\TaxcloudConfig {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, $enabled],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '0'],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, $captureTrigger],
            ['tax/taxcloud_settings/calculations_only', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, $calculationsOnly],
        ]);
        return new \Taxcloud\Magento2\Model\Config\TaxcloudConfig($scopeConfig);
    }

    /**
     * Build an Observer wrapping an event of $eventName, with optional callbacks.
     * $eventConfig may contain: 'order', 'invoice', 'shipment'.
     */
    private function buildObserver(string $eventName, array $eventConfig): \Magento\Framework\Event\Observer
    {
        // Real Event: getName() reads _data['name'] and getOrder/getInvoice/
        // getShipment are magic DataObject accessors over the same data array.
        $data = ['name' => $eventName];
        foreach (['order', 'invoice', 'shipment'] as $key) {
            if (isset($eventConfig[$key])) {
                $data[$key] = $eventConfig[$key];
            }
        }
        $event = new \Magento\Framework\Event($data);

        $observer = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    /**
     * Build a sales order resource mock. Tests that do not stub authorizeCapture()
     * to return true never reach saveAttribute, so no expectations are needed here.
     */
    private function makeOrderResource(): \Magento\Sales\Model\ResourceModel\Order
    {
        return $this->createMock(\Magento\Sales\Model\ResourceModel\Order::class);
    }

    /**
     * Build an Order mock with the given invoice / shipment collection sizes.
     */
    private function buildOrder(int $invoiceCollectionSize = 0, int $shipmentCollectionSize = 0): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(self::ORDER_STORE_ID);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn($invoiceCollectionSize);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);

        $shipmentCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class);
        $shipmentCollection->method('getSize')->willReturn($shipmentCollectionSize);
        $order->method('getShipmentsCollection')->willReturn($shipmentCollection);

        return $order;
    }

    /**
     * Section 4.1: trigger=order_creation + sales_order_place_after => authorizeCapture
     */
    public function testExecuteCapturesOnOrderPlaceAfterWhenTriggerIsOrderCreation()
    {
        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::ORDER_CREATION), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * On a successful capture, the taxcloud_captured flag is set on the order and
     * persisted via saveAttribute so the cancel flow can read it without OrderDetails.
     */
    public function testExecutePersistsCapturedFlagWhenCaptureSucceeds()
    {
        $order = $this->buildOrder();
        $order->expects($this->once())->method('setData')->with('taxcloud_captured', 1);
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('authorizeCapture')->with($order)->willReturn(true);

        $orderResource = $this->createMock(\Magento\Sales\Model\ResourceModel\Order::class);
        $orderResource->expects($this->once())
            ->method('saveAttribute')
            ->with($order, 'taxcloud_captured');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', CaptureTrigger::ORDER_CREATION),
            $tcapi,
            $logger,
            $orderResource
        );
        $complete->execute($observer);
    }

    /**
     * When the capture call fails (returns false), the flag must not be persisted.
     */
    public function testExecuteDoesNotPersistFlagWhenCaptureFails()
    {
        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('authorizeCapture')->with($order)->willReturn(false);

        $orderResource = $this->createMock(\Magento\Sales\Model\ResourceModel\Order::class);
        $orderResource->expects($this->never())->method('saveAttribute');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', CaptureTrigger::ORDER_CREATION),
            $tcapi,
            $logger,
            $orderResource
        );
        $complete->execute($observer);
    }

    /**
     * Section 4.2: trigger=payment + sales_order_invoice_pay (first invoice) => authorizeCapture
     */
    public function testExecuteCapturesOnInvoicePayWhenTriggerIsPayment()
    {
        $order = $this->buildOrder(invoiceCollectionSize: 1);
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrder')->willReturn($order);

        $observer = $this->buildObserver('sales_order_invoice_pay', ['invoice' => $invoice]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::PAYMENT), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * Section 4.3: trigger=payment + sales_order_place_after => no-op.
     * The "not before" assertion: with payment trigger configured, order-creation events
     * must never fire authorizeCapture.
     */
    public function testExecuteDoesNotCaptureOnOrderPlaceAfterWhenTriggerIsPayment()
    {
        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('authorizeCapture');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::PAYMENT), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * Section 4.4: trigger=shipment + sales_order_shipment_save_after (first shipment) => authorizeCapture
     */
    public function testExecuteCapturesOnShipmentSaveAfterWhenTriggerIsShipment()
    {
        $order = $this->buildOrder(shipmentCollectionSize: 1);
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);

        $observer = $this->buildObserver('sales_order_shipment_save_after', ['shipment' => $shipment]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::SHIPMENT), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * Section 4.5: trigger=payment + subsequent invoice (collection size > 1) => skip.
     *
     * Note this shape does not occur for invoice #2 in the real flow:
     * sales_order_invoice_pay is dispatched from Invoice::register(), before the
     * invoice is persisted, so the collection is one short and reports 1. See
     * Test/Integration/Observer/Sales/CaptureOnInvoiceShapesTest — the guard
     * this test pins only engages from the third invoice on.
     */
    public function testExecuteSkipsSubsequentInvoicePayEvents()
    {
        $order = $this->buildOrder(invoiceCollectionSize: 2);
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrder')->willReturn($order);

        $observer = $this->buildObserver('sales_order_invoice_pay', ['invoice' => $invoice]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('authorizeCapture');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::PAYMENT), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * Section 4.6: trigger=shipment + subsequent shipment (collection size > 1) => skip.
     */
    public function testExecuteSkipsSubsequentShipmentEvents()
    {
        $order = $this->buildOrder(shipmentCollectionSize: 2);
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);

        $observer = $this->buildObserver('sales_order_shipment_save_after', ['shipment' => $shipment]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('authorizeCapture');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::SHIPMENT), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * A second qualifying event on an already-captured order must not capture
     * the sale again.
     *
     * The observer's only dedupe is a count of invoices / shipments on the
     * order, and there are real journeys where that count does not distinguish
     * the second visit from the first. Two are proven at the integration level
     * (see CaptureOnInvoiceShapesTest and CaptureTriggerChangedMidLifecycleTest):
     *
     *   - a second invoice, because sales_order_invoice_pay is dispatched from
     *     Invoice::register() BEFORE the invoice is persisted, so the collection
     *     the guard counts is always one short;
     *   - an order captured at placement whose store then switches to capturing
     *     on shipment, which makes the shipment event newly eligible.
     *
     * The signal that survives all of them is the taxcloud_captured flag the
     * observer itself writes. Capturing twice files the same sale twice on the
     * merchant's TaxCloud account.
     */
    public function testExecuteDoesNotCaptureAgainWhenTheSameEventArrivesTwice()
    {
        $order = $this->buildOrder(shipmentCollectionSize: 1);
        $captured = 0;
        $order->method('getData')->willReturnCallback(
            static function ($key) use (&$captured) {
                return $key === 'taxcloud_captured' ? $captured : null;
            }
        );
        $order->method('setData')->willReturnCallback(
            static function ($key, $value = null) use (&$captured) {
                if ($key === 'taxcloud_captured') {
                    $captured = $value;
                }
            }
        );

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $observer = $this->buildObserver('sales_order_shipment_save_after', ['shipment' => $shipment]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())
            ->method('authorizeCapture')
            ->with($order)
            ->willReturn(true);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', CaptureTrigger::SHIPMENT),
            $tcapi,
            $logger,
            $this->makeOrderResource()
        );

        $complete->execute($observer);
        $complete->execute($observer);
    }

    /**
     * The same guard from the other side: an order already flagged as captured
     * must not be captured again on a later lifecycle event, whatever put the
     * flag there.
     */
    public function testExecuteSkipsCaptureWhenTheOrderIsAlreadyFlaggedAsCaptured()
    {
        $order = $this->buildOrder(shipmentCollectionSize: 1);
        $order->method('getData')->willReturnCallback(
            static fn ($key) => $key === 'taxcloud_captured' ? 1 : null
        );

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $observer = $this->buildObserver('sales_order_shipment_save_after', ['shipment' => $shipment]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('authorizeCapture');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', CaptureTrigger::SHIPMENT),
            $tcapi,
            $logger,
            $this->makeOrderResource()
        );
        $complete->execute($observer);
    }

    /**
     * Section 4.7: enabled=0 — all three events must skip.
     */
    public function testExecuteDoesNothingWhenModuleDisabled()
    {
        $eventNames = [
            'sales_order_place_after',
            'sales_order_invoice_pay',
            'sales_order_shipment_save_after',
        ];
        foreach ($eventNames as $eventName) {
            $order = $this->buildOrder(invoiceCollectionSize: 1, shipmentCollectionSize: 1);
            $invoice = $this->createMock(Invoice::class);
            $invoice->method('getOrder')->willReturn($order);
            $shipment = $this->createMock(Shipment::class);
            $shipment->method('getOrder')->willReturn($order);
            $observer = $this->buildObserver($eventName, [
                'order' => $order,
                'invoice' => $invoice,
                'shipment' => $shipment,
            ]);

            $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
            $tcapi->expects($this->never())->method('authorizeCapture');

            $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

            $complete = new Complete(
                $this->buildScopeConfig('0', CaptureTrigger::ORDER_CREATION),
                $tcapi,
                $logger,
                $this->makeOrderResource()
            );
            $complete->execute($observer);
        }
    }

    /**
     * Section 11.3: with logging=0, nothing the observer emits reaches the
     * TaxCloud channel. The observer logs unconditionally and the injected
     * GatewayLogger is what suppresses it, so this drives the observer through
     * the real proxy rather than a bare Logger.
     */
    public function testNothingReachesTheTaxcloudChannelWhenLoggingDisabled()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '0'],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, CaptureTrigger::ORDER_CREATION],
        ]);
        $config = new \Taxcloud\Magento2\Model\Config\TaxcloudConfig($scopeConfig);

        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);

        // The channel behind the proxy — must never receive anything.
        $channel = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $channel->expects($this->never())->method('log');
        $channel->expects($this->never())->method('info');

        $gatedLogger = new \Taxcloud\Magento2\Model\Logging\GatewayLogger($channel, $config);

        (new Complete($config, $tcapi, $gatedLogger, $this->makeOrderResource()))->execute($observer);
    }

    /**
     * Section 4.8: empty/null capture_trigger falls back to ORDER_CREATION.
     * Covers Complete.php:109-111.
     */
    public function testExecuteFallsBackToOrderCreationTriggerWhenConfigEmpty()
    {
        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        // capture_trigger is null — must default to order_creation
        $complete = new Complete($this->buildScopeConfig('1', null), $tcapi, $logger, $this->makeOrderResource());
        $complete->execute($observer);
    }

    /**
     * A stored value that is not one of the three declared options must land on
     * the order-creation default rather than disabling capture outright.
     *
     * Two different fallbacks are in play and both have to hold: TaxcloudConfig
     * substitutes the default for null/'', and the observer's isset() on the
     * trigger-to-event map catches anything else — a hand-edited core_config_data
     * row, or a value left behind by an older release. Losing capture entirely
     * means the sale is never reported, which is the expensive direction to fail
     * in.
     *
     * @dataProvider unrecognizedTriggerProvider
     */
    #[DataProvider('unrecognizedTriggerProvider')]
    public function testExecuteFallsBackToOrderCreationForUnrecognizedTriggerValues($stored)
    {
        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', $stored),
            $tcapi,
            $logger,
            $this->makeOrderResource()
        );
        $complete->execute($observer);
    }

    /**
     * @return array
     */
    public static function unrecognizedTriggerProvider(): array
    {
        return [
            'empty string' => [''],
            'unknown keyword' => ['bogus'],
            // The field was a select before it grew a third option; a boolean-ish
            // leftover must not silently stop capture.
            'legacy boolean' => ['1'],
            'wrong case' => ['ORDER_CREATION'],
        ];
    }

    /**
     * Every option the admin dropdown offers must actually be routable.
     *
     * The source model and the observer's trigger-to-event map are two separate
     * lists that have to agree. Adding a fourth option to the dropdown without
     * teaching the observer about it would not fail any existing test — the
     * unknown value would quietly fall back to capturing on order creation,
     * which is precisely the behavior the merchant chose against.
     *
     * @dataProvider declaredTriggerProvider
     */
    #[DataProvider('declaredTriggerProvider')]
    public function testEveryDeclaredTriggerCapturesOnAnEventOfItsOwn(string $trigger)
    {
        $eventsForTrigger = [];

        foreach (self::ALL_LIFECYCLE_EVENTS as $eventName) {
            if ($this->capturesOn($trigger, $eventName)) {
                $eventsForTrigger[] = $eventName;
            }
        }

        $this->assertNotEmpty(
            $eventsForTrigger,
            sprintf(
                'The "%s" option is offered in the admin dropdown but no lifecycle event captures for it — '
                . 'Complete::$triggerToEvent is missing an entry, so the setting silently degrades to '
                . 'capturing on order creation.',
                $trigger
            )
        );
        $this->assertCount(
            1,
            $eventsForTrigger,
            sprintf('The "%s" option must capture on exactly one event, got: %s', $trigger, implode(', ', $eventsForTrigger))
        );
    }

    /**
     * Distinct options must not collide on the same event, or one of them is
     * unreachable in practice.
     */
    public function testTheThreeTriggersCaptureOnThreeDifferentEvents()
    {
        $captureEvents = [];

        foreach (self::declaredTriggerProvider() as [$trigger]) {
            foreach (self::ALL_LIFECYCLE_EVENTS as $eventName) {
                if ($this->capturesOn($trigger, $eventName)) {
                    $captureEvents[$trigger] = $eventName;
                }
            }
        }

        $this->assertCount(
            3,
            array_unique($captureEvents),
            'Each capture trigger must own a distinct lifecycle event; got ' . json_encode($captureEvents)
        );
    }

    /**
     * @return array
     */
    public static function declaredTriggerProvider(): array
    {
        $cases = [];
        foreach ((new CaptureTrigger())->toOptionArray() as $option) {
            $cases[(string) $option['value']] = [(string) $option['value']];
        }

        return $cases;
    }

    /**
     * capture_trigger itself is read against the ORDER's store.
     *
     * Same hazard as enabled and calculations_only: the invoice and shipment
     * events run in admin, where the ambient store is the default view. Here the
     * default scope says order_creation and the order's store says shipment — an
     * implementation that dropped the $store argument would read order_creation,
     * decide sales_order_shipment_save_after is the wrong event, and never
     * capture the sale at all.
     */
    public function testCaptureTriggerIsReadAgainstTheOrderStore()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '0'],
            ['tax/taxcloud_settings/calculations_only', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '0'],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, CaptureTrigger::SHIPMENT],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, CaptureTrigger::ORDER_CREATION],
        ]);

        $order = $this->buildOrder(shipmentCollectionSize: 1);
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $observer = $this->buildObserver('sales_order_shipment_save_after', ['shipment' => $shipment]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            new \Taxcloud\Magento2\Model\Config\TaxcloudConfig($scopeConfig),
            $tcapi,
            $logger,
            $this->makeOrderResource()
        );
        $complete->execute($observer);
    }

    /**
     * A failure to persist taxcloud_captured must not escape the observer.
     *
     * The capture already succeeded in TaxCloud by this point; letting the
     * exception out would abort the order placement (or the invoice/shipment
     * save) over a bookkeeping column, turning a reported sale into a failed
     * checkout. The cancel flow degrades to the OrderDetails fallback instead.
     */
    public function testExecuteSwallowsAndLogsAFailureToPersistTheCapturedFlag()
    {
        $order = $this->buildOrder();
        $order->method('getIncrementId')->willReturn('100000042');
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('authorizeCapture')->with($order)->willReturn(true);

        $orderResource = $this->createMock(\Magento\Sales\Model\ResourceModel\Order::class);
        $orderResource->method('saveAttribute')
            ->willThrowException(new \RuntimeException('deadlock on sales_order'));

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('100000042'));

        $complete = new Complete(
            $this->buildScopeConfig('1', CaptureTrigger::ORDER_CREATION),
            $tcapi,
            $logger,
            $orderResource
        );

        $complete->execute($observer);
    }

    /**
     * Calculations-only mode suppresses the capture on every trigger.
     *
     * The setting is checked after the trigger routing, so this has to hold for
     * whichever event the store is configured to capture on — a gate that only
     * covered order_creation would still push the sale for a store configured
     * to capture on payment or shipment.
     *
     * @dataProvider calculationsOnlyTriggerProvider
     */
    #[DataProvider('calculationsOnlyTriggerProvider')]
    public function testExecuteSkipsCaptureInCalculationsOnlyMode(string $trigger, string $eventName)
    {
        $observer = $this->buildObserverForTrigger($eventName);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('authorizeCapture');

        // No capture means no capture flag: the cancel flow reads taxcloud_captured
        // to decide whether to reverse, so writing it here would arm a Returned
        // call for a sale TaxCloud never received.
        $orderResource = $this->createMock(\Magento\Sales\Model\ResourceModel\Order::class);
        $orderResource->expects($this->never())->method('saveAttribute');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', $trigger, '1'),
            $tcapi,
            $logger,
            $orderResource
        );
        $complete->execute($observer);
    }

    /**
     * @return array
     */
    public static function calculationsOnlyTriggerProvider(): array
    {
        return [
            'order creation' => [CaptureTrigger::ORDER_CREATION, 'sales_order_place_after'],
            'payment' => [CaptureTrigger::PAYMENT, 'sales_order_invoice_pay'],
            'shipment' => [CaptureTrigger::SHIPMENT, 'sales_order_shipment_save_after'],
        ];
    }

    /**
     * With the setting off, the same store still captures — this is what pins
     * the gate to the setting rather than to some unrelated early return.
     */
    public function testExecuteStillCapturesWhenCalculationsOnlyIsDisabled()
    {
        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('authorizeCapture')->with($order);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            $this->buildScopeConfig('1', CaptureTrigger::ORDER_CREATION, '0'),
            $tcapi,
            $logger,
            $this->makeOrderResource()
        );
        $complete->execute($observer);
    }

    /**
     * The setting is read against the ORDER's store, not the ambient one.
     *
     * Only store 2 (the order's) is mapped to calculations-only; the default
     * scope says otherwise. An implementation that dropped the $store argument
     * would read the unmapped default and go on to capture.
     */
    public function testCalculationsOnlyIsReadAgainstTheOrderStore()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '0'],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, CaptureTrigger::ORDER_CREATION],
            ['tax/taxcloud_settings/calculations_only', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '1'],
            ['tax/taxcloud_settings/calculations_only', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('authorizeCapture');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $complete = new Complete(
            new \Taxcloud\Magento2\Model\Config\TaxcloudConfig($scopeConfig),
            $tcapi,
            $logger,
            $this->makeOrderResource()
        );
        $complete->execute($observer);
    }

    /**
     * Run the observer once for the given trigger/event pair and report whether
     * it reached authorizeCapture. Used by the routing-contract tests to probe
     * the whole trigger x event matrix without asserting inside the loop.
     */
    private function capturesOn(string $trigger, string $eventName): bool
    {
        $captured = false;

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('authorizeCapture')->willReturnCallback(
            static function () use (&$captured) {
                $captured = true;
                return true;
            }
        );

        $complete = new Complete(
            $this->buildScopeConfig('1', $trigger),
            $tcapi,
            $this->createMock(\Taxcloud\Magento2\Logger\Logger::class),
            $this->makeOrderResource()
        );
        $complete->execute($this->buildObserverForTrigger($eventName));

        return $captured;
    }

    /**
     * Build the observer each capture trigger listens on, with the order shaped
     * so the observer's first-invoice / first-shipment dedupe lets it through.
     */
    private function buildObserverForTrigger(string $eventName): \Magento\Framework\Event\Observer
    {
        if ($eventName === 'sales_order_invoice_pay') {
            $order = $this->buildOrder(invoiceCollectionSize: 1);
            $invoice = $this->createMock(Invoice::class);
            $invoice->method('getOrder')->willReturn($order);
            return $this->buildObserver($eventName, ['invoice' => $invoice]);
        }

        if ($eventName === 'sales_order_shipment_save_after') {
            $order = $this->buildOrder(shipmentCollectionSize: 1);
            $shipment = $this->createMock(Shipment::class);
            $shipment->method('getOrder')->willReturn($order);
            return $this->buildObserver($eventName, ['shipment' => $shipment]);
        }

        return $this->buildObserver($eventName, ['order' => $this->buildOrder()]);
    }
}
