<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Api\OrderGatewayInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Order\CancellationProcessor;

/**
 * Covers when a cancelled order reverses its sale in TaxCloud: the module must
 * be enabled, the order genuinely canceled, uninvoiced, and reported by
 * TaxCloud as captured — and the reversal must happen at most once per order.
 */
#[AllowMockObjectsWithoutExpectations]
class CancellationProcessorTest extends TestCase
{
    private $gateway;
    private $config;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(OrderGatewayInterface::class);
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReportingByDefault')->willReturn(true);
    }

    private function processor(): CancellationProcessor
    {
        return new CancellationProcessor($this->config, $this->gateway, new NullLogger());
    }

    /**
     * @param int|null $invoiceCount null models an order whose collection is never consulted
     */
    private function order(
        $id = 42,
        string $state = Order::STATE_CANCELED,
        int $invoiceCount = 0,
        array $data = []
    ) {
        $invoices = $this->createMock(InvoiceCollection::class);
        $invoices->method('getSize')->willReturn($invoiceCount);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getState')->willReturn($state);
        $order->method('getInvoiceCollection')->willReturn($invoices);
        $order->method('getData')->willReturnCallback(static function ($key) use ($data) {
            return $data[$key] ?? null;
        });

        return $order;
    }

    private function stubCaptured(bool $captured): void
    {
        $this->gateway->method('getOrderDetails')
            ->willReturn($captured ? ['CapturedDate' => '2026-01-15'] : []);
    }

    public function testReversesSaleWhenAllConditionsMet()
    {
        $this->stubCaptured(true);
        $this->gateway->expects($this->once())->method('returnOrderCancellation');

        $this->processor()->process($this->order());
    }

    /**
     * When the local taxcloud_captured flag is set, Returned is called and the
     * license-gated OrderDetails API is never queried.
     */
    public function testReversesSaleUsingLocalCapturedFlagWithoutOrderDetails()
    {
        $this->gateway->expects($this->never())->method('getOrderDetails');
        $this->gateway->expects($this->once())->method('returnOrderCancellation')->willReturn(true);

        $order = $this->order(42, Order::STATE_CANCELED, 0, ['taxcloud_captured' => 1]);

        $this->processor()->process($order);
    }

    public function testDoesNothingWhenModuleDisabled()
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isEnabled')->willReturn(false);

        $this->gateway->expects($this->never())->method('getOrderDetails');
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order());
    }

    public function testDoesNothingWhenOrderHasNoId()
    {
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order(null));
    }

    /**
     * registerCancellation() is a no-op for an order that cannot be cancelled,
     * so the state has to be re-checked rather than assumed.
     */
    public function testDoesNothingWhenStateIsNotCanceled()
    {
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order(42, Order::STATE_PROCESSING));
    }

    public function testDoesNothingWhenOrderHasInvoices()
    {
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order(42, Order::STATE_CANCELED, 1));
    }

    public function testDoesNothingWhenTaxcloudReportsOrderNotCaptured()
    {
        $this->stubCaptured(false);
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order());
    }

    public function testDoesNothingWhenOrderDetailsUnavailable()
    {
        $this->gateway->method('getOrderDetails')->willReturn(false);
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order());
    }

    /**
     * registerCancellation() can be reached more than once for one order in a
     * request; reversing the sale twice would double-refund the tax.
     */
    public function testReversesSaleOnlyOncePerOrder()
    {
        $this->stubCaptured(true);
        $this->gateway->expects($this->once())->method('returnOrderCancellation');

        $processor = $this->processor();
        $order = $this->order();

        $processor->process($order);
        $processor->process($order);
    }

    public function testSeparateOrdersAreEachReversed()
    {
        $this->stubCaptured(true);
        $this->gateway->expects($this->exactly(2))->method('returnOrderCancellation');

        $processor = $this->processor();

        $processor->process($this->order(42));
        $processor->process($this->order(43));
    }

    /**
     * An order whose stored outcome keeps it from TaxCloud: neither the
     * reversal nor the OrderDetails probe that decides on it may reach
     * TaxCloud.
     *
     * @dataProvider notReportedOutcomeProvider
     */
    #[DataProvider('notReportedOutcomeProvider')]
    public function testDoesNothingForAnOrderNotReported(string $outcome)
    {
        $this->gateway->expects($this->never())->method('getOrderDetails');
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order(42, Order::STATE_CANCELED, 0, ['taxcloud_outcome' => $outcome]));
    }

    public static function notReportedOutcomeProvider(): array
    {
        return [
            'calculate only' => ['calculate_only'],
            'skip' => ['skip'],
        ];
    }

    /**
     * A stale taxcloud_captured flag cannot arm a Returned call: the gate sits
     * ahead of the captured check.
     */
    public function testDoesNothingForAnOrderNotReportedEvenWhenCapturedFlagIsSet()
    {
        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($this->order(42, Order::STATE_CANCELED, 0, [
            'taxcloud_outcome' => 'calculate_only',
            'taxcloud_captured' => 1,
        ]));
    }

    /**
     * A stored Report outcome reverses even when the store no longer reports
     * by default — the outcome was decided at placement and stands.
     */
    public function testStoredReportOutcomeWinsOverTheStoreDefault()
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isReportingByDefault')->willReturn(false);
        $this->gateway->expects($this->once())->method('returnOrderCancellation')->willReturn(true);

        $this->processor()->process($this->order(42, Order::STATE_CANCELED, 0, [
            'taxcloud_outcome' => 'report',
            'taxcloud_captured' => 1,
        ]));
    }

    /**
     * An order placed before rules existed follows its store's setting, read
     * against the ORDER's store — cancellations run in admin/cron contexts
     * where the ambient store is the default view.
     */
    public function testLegacyOrderFollowsItsStoreSetting()
    {
        $order = $this->order();
        $order->method('getStoreId')->willReturn(2);

        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->expects($this->once())
            ->method('isReportingByDefault')
            ->with(2)
            ->willReturn(false);

        $this->gateway->expects($this->never())->method('returnOrderCancellation');

        $this->processor()->process($order);
    }
}
