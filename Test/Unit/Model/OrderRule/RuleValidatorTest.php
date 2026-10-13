<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Model\OrderRule\RuleValidator;

#[AllowMockObjectsWithoutExpectations]
class RuleValidatorTest extends TestCase
{
    public function testAValidRulePasses()
    {
        $rule = RuleFixture::rule(['name' => 'Wholesale', 'action' => OrderRuleInterface::ACTION_CALCULATE_ONLY]);

        (new RuleValidator())->validate($rule);

        $this->assertSame('Wholesale', $rule->getName());
    }

    public function testNameIsRequired()
    {
        $this->expectException(LocalizedException::class);
        (new RuleValidator())->validate(RuleFixture::rule(['name' => '   ']));
    }

    public function testUnknownActionIsRefused()
    {
        $this->expectException(LocalizedException::class);
        (new RuleValidator())->validate(RuleFixture::rule(['action' => 'delete_everything']));
    }

    /**
     * Skip is decided before the order has a number; a prefix filter would
     * silently never match, so the rule is refused with an explanation.
     */
    public function testSkipWithAnOrderPrefixIsRefused()
    {
        $rule = RuleFixture::rule(['action' => OrderRuleInterface::ACTION_SKIP, 'order_prefixes' => ['AMZ-']]);

        try {
            (new RuleValidator())->validate($rule);
            $this->fail('A skip rule with a prefix must be refused');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('checkout', $e->getMessage());
        }
    }

    public function testSkipWithOnlyBlankPrefixesIsAccepted()
    {
        $rule = RuleFixture::rule(['action' => OrderRuleInterface::ACTION_SKIP, 'order_prefixes' => [' ', '']]);

        (new RuleValidator())->validate($rule);

        $this->assertSame([], $rule->getOrderPrefixes());
    }

    public function testCalculateOnlyMayUseAPrefix()
    {
        $rule = RuleFixture::rule(['order_prefixes' => ['AMZ-']]);

        (new RuleValidator())->validate($rule);

        $this->assertSame(['AMZ-'], $rule->getOrderPrefixes());
    }

    public function testPrefixesAreTrimmedAndDedupedCaseInsensitively()
    {
        $rule = RuleFixture::rule(['order_prefixes' => [' AMZ- ', 'amz-', '', 'EBAY']]);

        (new RuleValidator())->validate($rule);

        $this->assertSame(['AMZ-', 'EBAY'], $rule->getOrderPrefixes());
    }
}
