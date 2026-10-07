<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\OrderRule\Decision;
use Taxcloud\Magento2\Model\OrderRule\Evaluator;
use Taxcloud\Magento2\Model\OrderRule\MatchSubject;

/**
 * Matching semantics: OR within a filter, AND across filters, empty = any,
 * first active match wins, store default otherwise.
 */
#[AllowMockObjectsWithoutExpectations]
class EvaluatorTest extends TestCase
{
    private const STORE = 2;
    private const OTHER_STORE = 3;

    /**
     * @param OrderRuleInterface[] $rules Active rules, in evaluation order
     * @param array<int, string> $calculationsOnly Store id => stored calculations_only
     */
    private function evaluator(array $rules, array $calculationsOnly = []): Evaluator
    {
        $repository = $this->createMock(OrderRuleRepositoryInterface::class);
        $repository->method('getActiveRules')->willReturn($rules);

        $map = [];
        foreach ($calculationsOnly as $store => $value) {
            $map[] = [TaxcloudConfig::XML_PATH_CALCULATIONS_ONLY, ScopeInterface::SCOPE_STORE, $store, $value];
        }
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($map);

        return new Evaluator($repository, new TaxcloudConfig($scopeConfig));
    }

    private function subject(
        ?int $group = 1,
        ?string $payment = 'checkmo',
        ?string $shipping = 'flatrate_flatrate',
        ?string $number = '000000123',
        int $store = self::STORE
    ): MatchSubject {
        return new MatchSubject($store, $group, $payment, $shipping, $number);
    }

    public function testNoRulesFallsBackToAReportingStoreDefault()
    {
        $decision = $this->evaluator([], [self::STORE => '0'])->evaluate($this->subject());

        $this->assertTrue($decision->isStoreDefault());
        $this->assertSame(OrderRuleInterface::ACTION_REPORT, $decision->getAction());
    }

    public function testNoMatchFallsBackToANonReportingStoreDefault()
    {
        $rules = [RuleFixture::rule(['id' => 1, 'customer_group_ids' => [9]])];

        $decision = $this->evaluator($rules, [self::STORE => '1'])->evaluate($this->subject());

        $this->assertTrue($decision->isStoreDefault());
        $this->assertSame(OrderRuleInterface::ACTION_CALCULATE_ONLY, $decision->getAction());
        $this->assertNull($decision->getRuleId());
    }

    /**
     * The default is the subject's store's, never the ambient one.
     */
    public function testTheDefaultIsReadForTheSubjectStore()
    {
        $evaluator = $this->evaluator([], [self::STORE => '1', self::OTHER_STORE => '0']);

        $this->assertSame('calculate_only', $evaluator->evaluate($this->subject())->getAction());
        $this->assertSame(
            'report',
            $evaluator->evaluate($this->subject(1, 'checkmo', null, null, self::OTHER_STORE))->getAction()
        );
    }

    public function testARuleWithNoFiltersMatchesEverything()
    {
        $rules = [RuleFixture::rule(['id' => 4, 'name' => 'All', 'action' => 'skip'])];

        $decision = $this->evaluator($rules)->evaluate($this->subject(null, null, null, null));

        $this->assertSame('skip', $decision->getAction());
        $this->assertSame(4, $decision->getRuleId());
        $this->assertSame([], $decision->getMatched());
    }

    public function testAnyValueInAFilterMatches()
    {
        $rules = [RuleFixture::rule(['id' => 1, 'payment_methods' => ['banktransfer', 'checkmo']])];

        $decision = $this->evaluator($rules)->evaluate($this->subject());

        $this->assertSame(1, $decision->getRuleId());
        $this->assertSame([Decision::MATCHED_PAYMENT_METHOD => 'checkmo'], $decision->getMatched());
    }

    public function testEveryFilledFilterMustMatch()
    {
        $rules = [RuleFixture::rule([
            'id' => 1,
            'customer_group_ids' => [1],
            'shipping_methods' => ['freight_freight'],
        ])];

        $decision = $this->evaluator($rules, [self::STORE => '0'])->evaluate($this->subject());

        $this->assertTrue($decision->isStoreDefault(), 'group matches but shipping does not');
    }

    public function testAllFiltersMatchingRecordsEachMatchedValue()
    {
        $rules = [RuleFixture::rule([
            'id' => 1,
            'store_ids' => [self::STORE],
            'customer_group_ids' => [1],
            'payment_methods' => ['checkmo'],
            'shipping_methods' => ['flatrate_flatrate'],
            'order_prefixes' => ['000'],
        ])];

        $decision = $this->evaluator($rules)->evaluate($this->subject());

        $this->assertSame([
            Decision::MATCHED_STORE => self::STORE,
            Decision::MATCHED_CUSTOMER_GROUP => 1,
            Decision::MATCHED_PAYMENT_METHOD => 'checkmo',
            Decision::MATCHED_SHIPPING_METHOD => 'flatrate_flatrate',
            Decision::MATCHED_ORDER_PREFIX => '000',
        ], $decision->getMatched());
    }

    public function testTheFirstMatchingRuleWins()
    {
        $rules = [
            RuleFixture::rule(['id' => 1, 'name' => 'First', 'action' => 'report', 'payment_methods' => ['checkmo']]),
            RuleFixture::rule(['id' => 2, 'name' => 'Second', 'action' => 'skip']),
        ];

        $this->assertSame('First', $this->evaluator($rules)->evaluate($this->subject())->getRuleName());
    }

    /**
     * Report rules carve exceptions out of broader rules below them.
     */
    public function testAReportRuleAboveABroaderRuleCarvesAnException()
    {
        $rules = [
            RuleFixture::rule(['id' => 1, 'action' => 'report', 'customer_group_ids' => [2], 'payment_methods' => ['checkmo']]),
            RuleFixture::rule(['id' => 2, 'action' => 'calculate_only', 'customer_group_ids' => [2]]),
        ];
        $evaluator = $this->evaluator($rules);

        $this->assertSame('report', $evaluator->evaluate($this->subject(2, 'checkmo'))->getAction());
        $this->assertSame('calculate_only', $evaluator->evaluate($this->subject(2, 'paypal_express'))->getAction());
    }

    public function testThePrefixIsMatchedCaseInsensitivelyAtTheStart()
    {
        $rules = [RuleFixture::rule(['id' => 1, 'order_prefixes' => ['amz-']])];
        $evaluator = $this->evaluator($rules, [self::STORE => '0']);

        $this->assertSame(1, $evaluator->evaluate($this->subject(1, 'checkmo', null, 'AMZ-114-2231'))->getRuleId());
        $this->assertNull($evaluator->evaluate($this->subject(1, 'checkmo', null, 'X-AMZ-1'))->getRuleId());
    }

    /**
     * At checkout the order number is unknown: a prefix rule cannot match,
     * so a skip rule below it still applies.
     */
    public function testAPrefixRuleCannotMatchWithoutAnOrderNumber()
    {
        $rules = [
            RuleFixture::rule(['id' => 1, 'action' => 'calculate_only', 'order_prefixes' => ['AMZ-']]),
            RuleFixture::rule(['id' => 2, 'action' => 'skip', 'payment_methods' => ['m2epropayment']]),
        ];

        $decision = $this->evaluator($rules)->evaluate($this->subject(1, 'm2epropayment', null, null));

        $this->assertSame(2, $decision->getRuleId());
        $this->assertTrue($decision->isSkip());
    }

    /**
     * A filter on a value the order does not have yet (no payment chosen)
     * does not match.
     */
    public function testAnUnknownValueNeverMatchesAFilledFilter()
    {
        $rules = [RuleFixture::rule(['id' => 1, 'payment_methods' => ['checkmo']])];

        $decision = $this->evaluator($rules, [self::STORE => '0'])->evaluate($this->subject(1, null));

        $this->assertTrue($decision->isStoreDefault());
    }

    public function testStoreViewFilterMatchesTheSubjectStore()
    {
        $rules = [RuleFixture::rule(['id' => 1, 'store_ids' => [self::OTHER_STORE]])];
        $evaluator = $this->evaluator($rules, [self::STORE => '0']);

        $this->assertNull($evaluator->evaluate($this->subject())->getRuleId());
        $this->assertSame(
            1,
            $evaluator->evaluate($this->subject(1, 'checkmo', null, null, self::OTHER_STORE))->getRuleId()
        );
    }

    public function testGuestGroupZeroIsMatchable()
    {
        $rules = [RuleFixture::rule(['id' => 1, 'customer_group_ids' => [0]])];

        $this->assertSame(1, $this->evaluator($rules)->evaluate($this->subject(0))->getRuleId());
    }
}
