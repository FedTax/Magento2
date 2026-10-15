<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Sales;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\OrderRule\Decision;
use Taxcloud\Magento2\Model\OrderRule\OutcomeRecorder;
use Taxcloud\Magento2\Observer\Sales\RecordOrderOutcome;

#[AllowMockObjectsWithoutExpectations]
class RecordOrderOutcomeTest extends TestCase
{
    private function observer(array $data): Observer
    {
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn(new Event($data));
        return $observer;
    }

    public function testRecordsWithTheQuoteTheOrderCameFrom()
    {
        $order = $this->createMock(Order::class);
        $quote = $this->createMock(Quote::class);

        $recorder = $this->createMock(OutcomeRecorder::class);
        $recorder->expects($this->once())->method('record')->with($order, $quote)
            ->willReturn(new Decision('calculate_only', 2, 'Wholesale'));

        (new RecordOrderOutcome($recorder, $this->createMock(LoggerInterface::class)))
            ->execute($this->observer(['order' => $order, 'quote' => $quote]));
    }

    public function testDoesNothingWithoutAnOrder()
    {
        $recorder = $this->createMock(OutcomeRecorder::class);
        $recorder->expects($this->never())->method('record');

        (new RecordOrderOutcome($recorder, $this->createMock(LoggerInterface::class)))
            ->execute($this->observer(['quote' => $this->createMock(Quote::class)]));
    }

    /**
     * Order placement must never fail because of the rules.
     */
    public function testAFailureIsLoggedNotThrown()
    {
        $recorder = $this->createMock(OutcomeRecorder::class);
        $recorder->method('record')->willThrowException(new \RuntimeException('rules table missing'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('rules table missing'));

        (new RecordOrderOutcome($recorder, $logger))
            ->execute($this->observer(['order' => $this->createMock(Order::class)]));
    }
}
