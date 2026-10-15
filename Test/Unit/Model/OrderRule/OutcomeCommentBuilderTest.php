<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\OrderRule\Decision;
use Taxcloud\Magento2\Model\OrderRule\OutcomeCommentBuilder;

#[AllowMockObjectsWithoutExpectations]
class OutcomeCommentBuilderTest extends TestCase
{
    private function builder(bool $groupExists = true): OutcomeCommentBuilder
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('US English');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $groups = $this->createMock(GroupRepositoryInterface::class);
        if ($groupExists) {
            $group = $this->createMock(GroupInterface::class);
            $group->method('getCode')->willReturn('Wholesale');
            $groups->method('getById')->willReturn($group);
        } else {
            $groups->method('getById')->willThrowException(new NoSuchEntityException());
        }

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['payment/m2epropayment/title', 'store', 2, 'Amazon (M2E Pro)'],
        ]);

        return new OutcomeCommentBuilder($storeManager, $groups, $scopeConfig);
    }

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(2);
        $order->method('getShippingDescription')->willReturn('Freight - Truck');
        return $order;
    }

    public function testCalculateOnlyNamesTheRuleAndWhatMatched()
    {
        $decision = new Decision('calculate_only', 4, 'Wholesale', [
            Decision::MATCHED_CUSTOMER_GROUP => 3,
            Decision::MATCHED_SHIPPING_METHOD => 'freight_truck',
        ]);

        $comment = $this->builder()->build($decision, $this->order());

        $this->assertStringStartsWith('TaxCloud: Tax was calculated by TaxCloud, but this order is not reported', $comment);
        $this->assertStringContainsString('Order rule "Wholesale" matched', $comment);
        $this->assertStringContainsString('customer group Wholesale', $comment);
        $this->assertStringContainsString('shipping method Freight - Truck (freight_truck)', $comment);
    }

    public function testSkipSaysTaxcloudWasNotUsed()
    {
        $decision = new Decision('skip', 2, 'Amazon', [Decision::MATCHED_PAYMENT_METHOD => 'm2epropayment']);

        $comment = $this->builder()->build($decision, $this->order());

        $this->assertStringContainsString('TaxCloud was not used for this order', $comment);
        $this->assertStringContainsString('store\'s own tax rules', $comment);
        $this->assertStringContainsString('payment method Amazon (M2E Pro) (m2epropayment)', $comment);
    }

    public function testStoreAndPrefixAreDescribed()
    {
        $decision = new Decision('calculate_only', 1, 'Amazon numbers', [
            Decision::MATCHED_STORE => 2,
            Decision::MATCHED_ORDER_PREFIX => 'AMZ-',
        ]);

        $comment = $this->builder()->build($decision, $this->order());

        $this->assertStringContainsString('store view US English', $comment);
        $this->assertStringContainsString('order number prefix "AMZ-"', $comment);
    }

    public function testARuleWithoutFiltersSaysItAppliesToAllOrders()
    {
        $comment = $this->builder()->build(new Decision('skip', 1, 'Everything'), $this->order());

        $this->assertStringContainsString('Order rule "Everything" applies to all orders.', $comment);
    }

    /**
     * A deleted group still yields a comment, with the id instead of a name.
     */
    public function testAMissingGroupFallsBackToItsId()
    {
        $decision = new Decision('calculate_only', 4, 'W', [Decision::MATCHED_CUSTOMER_GROUP => 3]);

        $this->assertStringContainsString(
            'customer group #3',
            $this->builder(false)->build($decision, $this->order())
        );
    }

    /**
     * An unknown payment title shows just the code.
     */
    public function testAnUntitledPaymentMethodShowsItsCode()
    {
        $decision = new Decision('calculate_only', 4, 'W', [Decision::MATCHED_PAYMENT_METHOD => 'customcode']);

        $this->assertStringContainsString(
            'payment method customcode.',
            $this->builder()->build($decision, $this->order())
        );
    }

    public function testNoCommentForAReportingRule()
    {
        $this->assertNull($this->builder()->build(new Decision('report', 1, 'Checks'), $this->order()));
    }

    /**
     * A store view that does not report would otherwise comment on every order.
     */
    public function testNoCommentForTheStoreDefault()
    {
        $this->assertNull($this->builder()->build(new Decision('calculate_only'), $this->order()));
    }
}
