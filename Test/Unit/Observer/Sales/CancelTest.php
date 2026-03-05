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
        // getOrderDetails is never called when order has invoices (we skip before that)

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
}
