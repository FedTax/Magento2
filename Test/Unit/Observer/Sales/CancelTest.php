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
use Taxcloud\Magento2\Observer\Sales\Cancel;
use Magento\Sales\Model\Order;

class CancelTest extends TestCase
{
    /**
     * When extension is disabled, execute returns early and processOrderCancel is not called.
     */
    public function testExecuteDoesNothingWhenExtensionDisabled()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
            ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When order has invoices, returnOrderCancellation is not called.
     */
    public function testProcessOrderCancelSkipsWhenOrderHasInvoices()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('10001');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(1);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When order was not captured in TaxCloud (OrderDetails has no CapturedDate), returnOrderCancellation is not called.
     */
    public function testProcessOrderCancelSkipsWhenNotTaxcloudCaptured()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('10002');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $tcapi->method('getOrderDetails')->with($order)->willReturn(null);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When OrderDetails returns result but CapturedDate is empty, returnOrderCancellation is not called.
     */
    public function testProcessOrderCancelSkipsWhenCapturedDateEmpty()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');
        $tcapi->method('getOrderDetails')->willReturn(['ResponseType' => 'OK', 'CapturedDate' => '']);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('10003');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);
        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When all conditions are met (including TaxCloud OrderDetails shows CapturedDate), returnOrderCancellation is called.
     */
    public function testProcessOrderCancelCallsReturnWhenConditionsMet()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('returnOrderCancellation')->willReturn(true);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('10004');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $tcapi->method('getOrderDetails')->with($order)->willReturn(['CapturedDate' => '2024-01-01']);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * Section 6.1: stress-test the implicit dedupe between order_cancel_after and
     * sales_order_save_after.
     *
     * Same observer instance, same order. First fires order_cancel_after; then fires
     * sales_order_save_after with getOrigData('state')=processing (i.e. simulating a
     * world where the prior save did NOT happen first). Without an explicit dedupe in
     * Cancel.php, both invocations would call returnOrderCancellation — this test
     * asserts the call happens only once across both events.
     *
     * If the assertion fails, the dedupe is not implicit and we have a real double-
     * cancellation bug (capture this in the PR).
     */
    public function testCancelDoesNotDoubleProcessWhenBothOrderCancelAndSaveAfterFire()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('getOrderDetails')->willReturn(['CapturedDate' => '2026-01-01']);
        $tcapi->expects($this->once())
            ->method('returnOrderCancellation')
            ->willReturn(true);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('10005');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        // The save-after branch fires when origState transitions from non-canceled to canceled.
        $order->method('getOrigData')->with('state')->willReturn('processing');
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);

        // First event: order_cancel_after
        $event1 = $this->createMock(\Magento\Framework\Event::class);
        $event1->method('getName')->willReturn('order_cancel_after');
        $event1->method('getOrder')->willReturn($order);
        $observerObj1 = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj1->method('getEvent')->willReturn($event1);

        // Second event: sales_order_save_after, same observer instance, same order
        $event2 = $this->createMock(\Magento\Framework\Event::class);
        $event2->method('getName')->willReturn('sales_order_save_after');
        $event2->method('getOrder')->willReturn($order);
        $observerObj2 = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj2->method('getEvent')->willReturn($event2);

        $observer->execute($observerObj1);
        $observer->execute($observerObj2);
    }

    // ─── Section E: remaining Cancel observer branches ──────────────────────

    /**
     * E1: sales_order_save_after where the order was ALREADY canceled before this save
     * (origState=canceled). The transition guard must skip processOrderCancel.
     */
    public function testCancelDoesNothingOnSaveAfterWhenOrigStateAlreadyCanceled()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(2);
        $order->method('getIncrementId')->willReturn('10010');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $order->method('getOrigData')->with('state')->willReturn(Order::STATE_CANCELED);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('sales_order_save_after');
        $event->method('getOrder')->willReturn($order);
        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * E2: order_cancel_after where the order has no ID — early return before any work.
     */
    public function testCancelDoesNothingOnOrderCancelWhenOrderHasNoId()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');
        $tcapi->expects($this->never())->method('getOrderDetails');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);
        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);
        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * E3: explicit coverage of the post-fix dedupe log line in processOrderCancel.
     * Same order_cancel_after fires twice for the same order; second call is a no-op
     * and emits the "already processed" log line.
     */
    public function testCancelDedupeSkipsSecondInvocation()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('getOrderDetails')->willReturn(['CapturedDate' => '2026-01-01']);
        $tcapi->expects($this->once())
            ->method('returnOrderCancellation')
            ->willReturn(true);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $observer = new Cancel($scopeConfig, $tcapi, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(3);
        $order->method('getIncrementId')->willReturn('10020');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);
        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        // Two invocations on the same observer — second must be deduped.
        $observer->execute($observerObj);
        $observer->execute($observerObj);
    }
}
