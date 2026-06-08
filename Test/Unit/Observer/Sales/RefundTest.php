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
use Taxcloud\Magento2\Observer\Sales\Refund;

/**
 * Coverage for the Refund observer — fires on sales_order_creditmemo_refund and
 * forwards the credit memo to Api::returnOrder.
 */
class RefundTest extends TestCase
{
    private function buildObserver($creditmemo): \Magento\Framework\Event\Observer
    {
        $event = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCreditmemo'])
            ->getMock();
        $event->method('getCreditmemo')->willReturn($creditmemo);

        $observer = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    /**
     * A1.1: when the module is disabled, the credit memo must not be forwarded.
     */
    public function testExecuteDoesNothingWhenModuleDisabled()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('returnOrder');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $observer = $this->buildObserver($creditmemo);

        $refund = new Refund($scopeConfig, $tcapi, $logger);
        $refund->execute($observer);
    }

    /**
     * A1.2: enabled — Api::returnOrder is called exactly once with the credit memo
     * from the event.
     */
    public function testExecuteCallsReturnOrderWithCreditmemo()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ]);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->once())
            ->method('returnOrder')
            ->with($creditmemo);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = $this->buildObserver($creditmemo);

        $refund = new Refund($scopeConfig, $tcapi, $logger);
        $refund->execute($observer);
    }

    /**
     * A1.3: enabled — even when Api::returnOrder throws, the observer must not
     * propagate the exception (the refund flow has already committed in Magento;
     * a TaxCloud SOAP failure should not block the refund UX).
     *
     * Note: as of this writing the Refund observer does NOT have a try/catch
     * around returnOrder(). This test pins the intended behavior; if it fails,
     * Refund.php should be wrapped (mirror of the Address.php fix).
     */
    public function testExecuteSwallowsThrownExceptions()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ]);

        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('returnOrder')->willThrowException(new \RuntimeException('TaxCloud unavailable'));

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = $this->buildObserver($creditmemo);

        $refund = new Refund($scopeConfig, $tcapi, $logger);
        $refund->execute($observer);
        $this->assertTrue(true, 'Refund observer must not propagate Api::returnOrder exceptions');
    }
}
