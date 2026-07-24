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
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Observer\Sales\Refund;

/**
 * Coverage for the Refund observer — fires on sales_order_creditmemo_refund and
 * forwards the credit memo to Api::returnOrder.
 */
#[AllowMockObjectsWithoutExpectations]
class RefundTest extends TestCase
{
    /**
     * Store id carried by the credit memo's order. The config map is keyed on
     * it, so a regression back to ambient-store (null-scope) config reads
     * resolves enabled to null and the tests fail.
     */
    private const ORDER_STORE_ID = 2;

    private function buildObserver($creditmemo): \Magento\Framework\Event\Observer
    {
        $event = new \Magento\Framework\Event(['creditmemo' => $creditmemo]);

        $observer = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    /**
     * A credit memo whose order lives on store ORDER_STORE_ID.
     */
    private function buildCreditmemo(): \Magento\Sales\Model\Order\Creditmemo
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getStoreId')->willReturn(self::ORDER_STORE_ID);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        return $creditmemo;
    }

    /**
     * A TaxcloudConfig whose store-2-scoped enabled flag is $enabled.
     */
    private function buildConfig(string $enabled): TaxcloudConfig
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, $enabled],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::ORDER_STORE_ID, '0'],
            ]);
        return new TaxcloudConfig($scopeConfig);
    }

    /**
     * A1.1: when the module is disabled on the order's store, the credit memo
     * must not be forwarded.
     */
    public function testExecuteDoesNothingWhenModuleDisabled()
    {
        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrder');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = $this->buildObserver($this->buildCreditmemo());

        $refund = new Refund($this->buildConfig('0'), $tcapi, $logger);
        $refund->execute($observer);
    }

    /**
     * A1.2: enabled — Api::returnOrder is called exactly once with the credit memo
     * from the event.
     */
    public function testExecuteCallsReturnOrderWithCreditmemo()
    {
        $creditmemo = $this->buildCreditmemo();

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())
            ->method('returnOrder')
            ->with($creditmemo);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = $this->buildObserver($creditmemo);

        $refund = new Refund($this->buildConfig('1'), $tcapi, $logger);
        $refund->execute($observer);
    }

    /**
     * A1.3: enabled — even when Api::returnOrder throws, the observer must not
     * propagate the exception (the refund flow has already committed in Magento;
     * a TaxCloud SOAP failure should not block the refund UX).
     */
    public function testExecuteSwallowsThrownExceptions()
    {
        $creditmemo = $this->buildCreditmemo();

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('returnOrder')->willThrowException(new \RuntimeException('TaxCloud unavailable'));

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = $this->buildObserver($creditmemo);

        $refund = new Refund($this->buildConfig('1'), $tcapi, $logger);
        $refund->execute($observer);
        $this->assertTrue(true, 'Refund observer must not propagate Api::returnOrder exceptions');
    }
}
