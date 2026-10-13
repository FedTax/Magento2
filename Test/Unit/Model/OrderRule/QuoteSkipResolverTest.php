<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\OrderRule\Decision;
use Taxcloud\Magento2\Model\OrderRule\Evaluator;
use Taxcloud\Magento2\Model\OrderRule\MatchSubject;
use Taxcloud\Magento2\Model\OrderRule\QuoteSkipResolver;
use Taxcloud\Magento2\Model\OrderRule\SubjectReader;
use Taxcloud\Magento2\Test\Unit\Double\QuoteDouble;

#[AllowMockObjectsWithoutExpectations]
class QuoteSkipResolverTest extends TestCase
{
    private $paymentMethod = 'checkmo';

    /**
     * A quote that keeps transient data like the real one (the resolver caches
     * on it) while its payment method can change between collections.
     */
    private function quote(int $storeId = 2): \Magento\Quote\Model\Quote
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturnCallback(function () {
            return $this->paymentMethod;
        });
        $address = $this->createMock(Address::class);
        $address->method('getShippingMethod')->willReturn('flatrate_flatrate');

        $quote = $this->getMockBuilder(QuoteDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getCustomerGroupId', 'getPayment', 'isVirtual', 'getShippingAddress'])
            ->getMock();
        $quote->method('getStoreId')->willReturn($storeId);
        $quote->method('getCustomerGroupId')->willReturn(1);
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        return $quote;
    }

    private function config(bool $enabled, ?int $expectedStore = null): TaxcloudConfig
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $method = $config->method('isEnabled');
        if ($expectedStore !== null) {
            $method->with($expectedStore);
        }
        $method->willReturn($enabled);
        return $config;
    }

    /**
     * Skips when a skip rule matches, and follows the quote back to TaxCloud
     * when the customer switches to a method the rule does not cover.
     */
    public function testFollowsThePaymentMethodAsItChanges()
    {
        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(static function (MatchSubject $subject) {
            return $subject->getPaymentMethod() === 'm2epropayment'
                ? new Decision('skip', 3, 'Amazon', [Decision::MATCHED_PAYMENT_METHOD => 'm2epropayment'])
                : new Decision('report');
        });
        $resolver = new QuoteSkipResolver($evaluator, $this->config(true), new SubjectReader());
        $quote = $this->quote();

        $this->assertNull($resolver->resolve($quote));

        $this->paymentMethod = 'm2epropayment';
        $this->assertSame('Amazon', $resolver->resolve($quote)->getRuleName());

        $this->paymentMethod = 'checkmo';
        $this->assertNull($resolver->resolve($quote));
    }

    /**
     * Only skip matters at checkout; calculate-only is decided on the order.
     */
    public function testCalculateOnlyIsNotASkip()
    {
        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->method('evaluate')->willReturn(new Decision('calculate_only', 1, 'Wholesale'));

        $this->assertNull((new QuoteSkipResolver($evaluator, $this->config(true), new SubjectReader()))->resolve($this->quote()));
    }

    public function testUnchangedQuoteIsEvaluatedOnce()
    {
        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->expects($this->once())->method('evaluate')->willReturn(new Decision('skip', 1, 'A'));
        $resolver = new QuoteSkipResolver($evaluator, $this->config(true), new SubjectReader());
        $quote = $this->quote();

        $resolver->resolve($quote);
        $resolver->resolve($quote);
        $resolver->resolve($quote);
    }

    /**
     * Rules have no effect where TaxCloud is off — checked for the QUOTE's
     * store, not the ambient one.
     */
    public function testDisabledQuoteStoreIsNeverSkippedOrEvaluated()
    {
        $evaluator = $this->createMock(Evaluator::class);
        $evaluator->expects($this->never())->method('evaluate');

        $resolver = new QuoteSkipResolver($evaluator, $this->config(false, 7), new SubjectReader());

        $this->assertNull($resolver->resolve($this->quote(7)));
    }
}
