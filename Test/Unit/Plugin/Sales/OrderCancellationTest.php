<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Plugin\Sales;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Order\CancellationProcessor;
use Taxcloud\Magento2\Plugin\Sales\OrderCancellation;

/**
 * Covers the interception itself: the cancelled order reaches the processor,
 * and the plugin returns the subject's result untouched so it cannot disturb
 * the cancellation it observes.
 */
#[AllowMockObjectsWithoutExpectations]
class OrderCancellationTest extends TestCase
{
    public function testPassesTheCancelledOrderToTheProcessor()
    {
        $order = $this->createMock(Order::class);

        $processor = $this->createMock(CancellationProcessor::class);
        $processor->expects($this->once())->method('process')->with($order);

        (new OrderCancellation($processor))->afterRegisterCancellation($order, $order);
    }

    public function testReturnsTheOriginalResultUnchanged()
    {
        $order = $this->createMock(Order::class);
        $result = $this->createMock(Order::class);

        $plugin = new OrderCancellation($this->createMock(CancellationProcessor::class));

        $this->assertSame(
            $result,
            $plugin->afterRegisterCancellation($order, $result),
            'the plugin must hand back registerCancellation() result untouched'
        );
    }

    /**
     * di.xml is what makes the interception exist at all; a plugin class with no
     * registration is dead code.
     */
    public function testPluginIsRegisteredOnTheOrderModel()
    {
        $diXml = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        $this->assertNotFalse($diXml, 'etc/di.xml must be parseable');

        $plugin = $diXml->xpath(
            '/config/type[@name="Magento\Sales\Model\Order"]/plugin[@type="'
            . OrderCancellation::class . '"]'
        );

        $this->assertCount(1, $plugin, 'the plugin must be registered on Magento\Sales\Model\Order');
    }

    /**
     * The plugin replaces the event wiring outright; leaving either cancellation
     * observer registered would reverse the sale a second time.
     */
    public function testCancellationEventsAreNoLongerRegistered()
    {
        $eventsXml = simplexml_load_file(__DIR__ . '/../../../../etc/events.xml');
        $this->assertNotFalse($eventsXml, 'etc/events.xml must be parseable');

        $this->assertCount(
            0,
            $eventsXml->xpath('/config/event[@name="sales_order_save_after"]'),
            'sales_order_save_after must not be registered'
        );
        $this->assertCount(
            0,
            $eventsXml->xpath('/config/event[@name="order_cancel_after"]'),
            'order_cancel_after must not be registered alongside the plugin'
        );
    }
}
