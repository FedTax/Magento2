<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order\Status\History;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\OrderRule\Decision;
use Taxcloud\Magento2\Model\OrderRule\Evaluator;
use Taxcloud\Magento2\Model\OrderRule\OutcomeCommentBuilder;
use Taxcloud\Magento2\Model\OrderRule\OutcomeRecorder;
use Taxcloud\Magento2\Model\OrderRule\QuoteSkipResolver;
use Taxcloud\Magento2\Test\Unit\Double\OrderDouble;

#[AllowMockObjectsWithoutExpectations]
class OutcomeRecorderTest extends TestCase
{
    private $evaluator;
    private $skipResolver;
    private $commentBuilder;
    private $config;
    private $comments = [];

    protected function setUp(): void
    {
        $this->evaluator = $this->createMock(Evaluator::class);
        $this->skipResolver = $this->createMock(QuoteSkipResolver::class);
        $this->commentBuilder = $this->createMock(OutcomeCommentBuilder::class);
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
    }

    private function recorder(): OutcomeRecorder
    {
        return new OutcomeRecorder(
            $this->evaluator,
            $this->skipResolver,
            $this->commentBuilder,
            $this->config,
            new \Taxcloud\Magento2\Model\OrderRule\SubjectReader()
        );
    }

    private function order(array $data = []): OrderDouble
    {
        $order = $this->getMockBuilder(OrderDouble::class)
            ->onlyMethods(['getStoreId', 'getCustomerGroupId', 'getPayment', 'getIsVirtual', 'getShippingMethod',
                'getIncrementId', 'addCommentToStatusHistory'])
            ->getMock();
        $order->method('getStoreId')->willReturn(2);
        $order->method('getIncrementId')->willReturn('AMZ-9');
        $order->method('addCommentToStatusHistory')->willReturnCallback(function ($comment, $status, $visible) {
            $this->comments[] = [$comment, $status, $visible];
            $history = $this->createMock(History::class);
            $history->expects($this->once())->method('setIsCustomerNotified')->with(0)->willReturnSelf();
            return $history;
        });
        $order->setData($data);
        return $order;
    }

    public function testStoresTheDecisionOnTheOrder()
    {
        $this->evaluator->method('evaluate')->willReturn(new Decision('calculate_only', 4, 'Wholesale'));
        $order = $this->order();

        $this->recorder()->record($order);

        $this->assertSame('calculate_only', $order->getData('taxcloud_outcome'));
        $this->assertSame(4, $order->getData('taxcloud_outcome_rule_id'));
        $this->assertSame('Wholesale', $order->getData('taxcloud_outcome_rule_name'));
    }

    public function testStoreDefaultIsStoredWithoutARule()
    {
        $this->evaluator->method('evaluate')->willReturn(new Decision('report'));
        $order = $this->order();

        $this->recorder()->record($order);

        $this->assertSame('report', $order->getData('taxcloud_outcome'));
        $this->assertNull($order->getData('taxcloud_outcome_rule_id'));
    }

    /**
     * The tax already charged is Magento's: a skipped quote makes the order
     * Skip without asking the rules again, so a prefix rule matching the new
     * order number cannot contradict it.
     */
    public function testASkippedQuoteMakesTheOrderSkip()
    {
        $this->skipResolver->method('resolve')->willReturn(new Decision('skip', 2, 'Amazon'));
        $this->evaluator->expects($this->never())->method('evaluate');
        $order = $this->order();

        $this->recorder()->record($order, $this->createMock(Quote::class));

        $this->assertSame('skip', $order->getData('taxcloud_outcome'));
        $this->assertSame('Amazon', $order->getData('taxcloud_outcome_rule_name'));
    }

    public function testAQuoteNotSkippedIsDecidedOnThePlacedOrder()
    {
        $this->skipResolver->method('resolve')->willReturn(null);
        $this->evaluator->expects($this->once())->method('evaluate')
            ->with($this->callback(static function ($subject) {
                return $subject->getOrderNumber() === 'AMZ-9';
            }))
            ->willReturn(new Decision('calculate_only', 1, 'Amazon prefix'));
        $order = $this->order();

        $this->recorder()->record($order, $this->createMock(Quote::class));

        $this->assertSame('calculate_only', $order->getData('taxcloud_outcome'));
    }

    /**
     * Decided once: a later call (placement fallback) never overwrites.
     */
    public function testAnExistingOutcomeIsLeftAlone()
    {
        $this->evaluator->expects($this->never())->method('evaluate');
        $order = $this->order(['taxcloud_outcome' => 'skip']);

        $this->assertNull($this->recorder()->record($order));
        $this->assertSame('skip', $order->getData('taxcloud_outcome'));
    }

    public function testNothingIsRecordedWhereTaxcloudIsOff()
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isEnabled')->with(2)->willReturn(false);
        $this->evaluator->expects($this->never())->method('evaluate');
        $order = $this->order();

        $this->assertNull($this->recorder()->record($order));
        $this->assertNull($order->getData('taxcloud_outcome'));
    }

    public function testTheCommentIsAddedHiddenAndUnnotified()
    {
        $this->evaluator->method('evaluate')->willReturn(new Decision('calculate_only', 4, 'Wholesale'));
        $this->commentBuilder->method('build')->willReturn('TaxCloud: explained');

        $this->recorder()->record($this->order());

        $this->assertSame([['TaxCloud: explained', false, false]], $this->comments);
    }

    public function testNoCommentWhenTheBuilderHasNone()
    {
        $this->evaluator->method('evaluate')->willReturn(new Decision('calculate_only'));
        $this->commentBuilder->method('build')->willReturn(null);

        $this->recorder()->record($this->order());

        $this->assertSame([], $this->comments);
    }
}
