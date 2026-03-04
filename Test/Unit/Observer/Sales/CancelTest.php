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
        $orderRepository = $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('save');

        $observer = new Cancel($scopeConfig, $tcapi, $logger, $orderRepository);

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
        $orderRepository = $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('save');

        $observer = new Cancel($scopeConfig, $tcapi, $logger, $orderRepository);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('10001');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(1);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $order->method('getData')->with('taxcloud_captured')->willReturn(true);
        $order->method('getData')->with('taxcloud_return_sent')->willReturn(false);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When order was not captured in TaxCloud, returnOrderCancellation is not called.
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
        $orderRepository = $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('save');

        $observer = new Cancel($scopeConfig, $tcapi, $logger, $orderRepository);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('10002');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $order->method('getData')->willReturnMap([
            [['taxcloud_captured'], null],
            [['taxcloud_return_sent'], false]
        ]);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When taxcloud_return_sent is already true, returnOrderCancellation is not called (deduplication).
     */
    public function testProcessOrderCancelSkipsWhenReturnAlreadySent()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrderCancellation');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $orderRepository = $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $orderRepository->expects($this->never())->method('save');

        $observer = new Cancel($scopeConfig, $tcapi, $logger, $orderRepository);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('10003');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $order->method('getData')->willReturnMap([
            [['taxcloud_captured'], true],
            [['taxcloud_return_sent'], true]
        ]);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When all conditions are met, returnOrderCancellation is called and order is saved with flag.
     */
    public function testProcessOrderCancelCallsReturnAndSavesOrderWhenConditionsMet()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
            ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
        ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())->method('returnOrderCancellation')->willReturn(true);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);
        $orderRepository = $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $orderRepository->expects($this->once())->method('save');

        $observer = new Cancel($scopeConfig, $tcapi, $logger, $orderRepository);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('10004');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $order->expects($this->once())->method('setData')->with('taxcloud_return_sent', true);
        $invoiceCollection = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Invoice\Collection::class);
        $invoiceCollection->method('getSize')->willReturn(0);
        $order->method('getInvoiceCollection')->willReturn($invoiceCollection);
        $order->method('getData')->willReturnCallback(function ($key) {
            if ($key === 'taxcloud_captured') {
                return true;
            }
            if ($key === 'taxcloud_return_sent') {
                return false;
            }
            return null;
        });

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getName')->willReturn('order_cancel_after');
        $event->method('getOrder')->willReturn($order);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }
}
