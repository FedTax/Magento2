<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\OrderRule\RuleSummary;
use Taxcloud\Magento2\Model\OrderRule\Source\CustomerGroups;
use Taxcloud\Magento2\Model\OrderRule\Source\PaymentMethods;
use Taxcloud\Magento2\Model\OrderRule\Source\ShippingMethods;

#[AllowMockObjectsWithoutExpectations]
class RuleSummaryTest extends TestCase
{
    private function summary(bool $shippingBroken = false): RuleSummary
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('US & Canada');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->with(1)->willReturn($store);
        $groups = $this->createMock(CustomerGroups::class);
        $groups->method('toOptionArray')->willReturn([['value' => '2', 'label' => 'Wholesale']]);
        $payments = $this->createMock(PaymentMethods::class);
        $payments->method('toOptionArray')->willReturn([['value' => 'checkmo', 'label' => 'Check / Money order']]);
        $shippings = $this->createMock(ShippingMethods::class);
        if ($shippingBroken) {
            $shippings->method('toOptionArray')->willThrowException(new \RuntimeException('carrier broke'));
        } else {
            $shippings->method('toOptionArray')->willReturn([
                ['label' => 'Freight', 'value' => [['value' => 'freight_truck', 'label' => '[freight] Truck']]],
            ]);
        }
        return new RuleSummary($stores, $groups, $payments, $shippings);
    }

    public function testEachFilledFilterIsDescribedWithLabels()
    {
        $rule = RuleFixture::rule([
            'store_ids' => [1],
            'customer_group_ids' => [2],
            'payment_methods' => ['checkmo'],
            'shipping_methods' => ['freight_truck'],
            'order_prefixes' => ['AMZ-', 'EBAY-'],
        ]);

        $this->assertSame([
            'Store view: US & Canada',
            'Customer group: Wholesale',
            'Payment: Check / Money order',
            'Shipping: [freight] Truck',
            'Order number starts with: AMZ-, EBAY-',
        ], $this->summary()->describe($rule));
    }

    public function testARuleWithoutFiltersMatchesAllOrders()
    {
        $this->assertSame(['All orders'], $this->summary()->describe(RuleFixture::rule()));
    }

    /**
     * A method that no longer exists, or a source that throws, still reads as
     * its code rather than breaking the list.
     */
    public function testUnknownValuesFallBackToTheirCode()
    {
        $rule = RuleFixture::rule(['payment_methods' => ['gone'], 'shipping_methods' => ['freight_truck']]);

        $this->assertSame(
            ['Payment: gone', 'Shipping: freight_truck'],
            $this->summary(true)->describe($rule)
        );
    }
}
