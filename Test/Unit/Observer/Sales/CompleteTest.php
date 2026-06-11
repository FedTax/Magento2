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
class CompleteTest extends TestCase
{
    /**
     * Build a scopeConfig mock honoring the given enabled flag and capture_trigger.
     */
    private function buildScopeConfig($enabled, $captureTrigger): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $enabled],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $captureTrigger],
        ]);
        return $scopeConfig;
    }

    /**
     * Build an Observer wrapping an event of $eventName, with optional callbacks.
     * $eventConfig may contain: 'order', 'invoice', 'shipment'.
     */
    private function buildObserver(string $eventName, array $eventConfig): \Magento\Framework\Event\Observer
    {
        // getName() is a real method on Event; getOrder/getInvoice/getShipment
        // are magic DataObject accessors and need addMethods().
        $event = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getName'])
            ->addMethods(['getInvoice', 'getOrder', 'getShipment'])
            ->getMock();
        $event->method('getName')->willReturn($eventName);
        if (isset($eventConfig['order'])) {
            $event->method('getOrder')->willReturn($eventConfig['order']);
        }
        if (isset($eventConfig['invoice'])) {
            $event->method('getInvoice')->willReturn($eventConfig['invoice']);
        }
        if (isset($eventConfig['shipment'])) {
            $event->method('getShipment')->willReturn($eventConfig['shipment']);
        }

        $observer = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    /**
     * Build an Order mock with the given invoice / shipment collection sizes.
     */
    private function buildOrder(int $invoiceCollectionSize = 0, int $shipmentCollectionSize = 0): Order
    {
        $order = $this->createMock(Order::class);
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

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::ORDER_CREATION), $tcapi, $logger);
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

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::PAYMENT), $tcapi, $logger);
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

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::PAYMENT), $tcapi, $logger);
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

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::SHIPMENT), $tcapi, $logger);
        $complete->execute($observer);
    }

    /**
     * Section 4.5: trigger=payment + subsequent invoice (collection size > 1) => skip.
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

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::PAYMENT), $tcapi, $logger);
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

        $complete = new Complete($this->buildScopeConfig('1', CaptureTrigger::SHIPMENT), $tcapi, $logger);
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
                $logger
            );
            $complete->execute($observer);
        }
    }

    /**
     * Section 11.3: when logging=0, the constructor swaps the real Logger for an
     * anonymous no-op stub. Calls that would normally log on the real Logger must
     * never reach it.
     *
     * Verifies Complete.php:69-77.
     */
    public function testLoggerIsNoOpStubWhenLoggingDisabled()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/capture_trigger', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, CaptureTrigger::ORDER_CREATION],
        ]);

        $order = $this->buildOrder();
        $observer = $this->buildObserver('sales_order_place_after', ['order' => $order]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);

        // Real Logger mock — must never receive any call when logging=0.
        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('error');
        $logger->expects($this->never())->method('debug');

        $complete = new Complete($scopeConfig, $tcapi, $logger);

        // Drive execute() through a code path that would normally log
        // (line 121: "Running Observer ..."). The no-op stub must absorb it.
        $complete->execute($observer);
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
        $complete = new Complete($this->buildScopeConfig('1', null), $tcapi, $logger);
        $complete->execute($observer);
    }
}
