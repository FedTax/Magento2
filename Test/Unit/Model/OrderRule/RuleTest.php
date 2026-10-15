<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Filters are stored as JSON; a rule must read the same whether its data came
 * from the database (a JSON string) or was set in code.
 */
#[AllowMockObjectsWithoutExpectations]
class RuleTest extends TestCase
{
    public function testSettersStoreJsonThatGettersDecode()
    {
        $rule = RuleFixture::rule([
            'store_ids' => ['1', 2],
            'customer_group_ids' => [3],
            'payment_methods' => ['checkmo'],
            'shipping_methods' => ['flatrate_flatrate'],
            'order_prefixes' => ['AMZ-'],
        ]);

        $this->assertSame('[1,2]', $rule->getData('store_ids'));
        $this->assertSame([1, 2], $rule->getStoreIds());
        $this->assertSame([3], $rule->getCustomerGroupIds());
        $this->assertSame(['checkmo'], $rule->getPaymentMethods());
        $this->assertSame(['flatrate_flatrate'], $rule->getShippingMethods());
        $this->assertSame(['AMZ-'], $rule->getOrderPrefixes());
    }

    /**
     * What a collection load puts in the data array.
     */
    public function testJsonFromTheDatabaseDecodes()
    {
        $rule = RuleFixture::rule();
        $rule->setData('payment_methods', '["m2epropayment","checkmo"]');

        $this->assertSame(['m2epropayment', 'checkmo'], $rule->getPaymentMethods());
    }

    /**
     * @dataProvider emptyStoredValueProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyStoredValueProvider')]
    public function testMissingOrBrokenFilterIsEmpty($stored)
    {
        $rule = RuleFixture::rule();
        $rule->setData('customer_group_ids', $stored);

        $this->assertSame([], $rule->getCustomerGroupIds());
    }

    public static function emptyStoredValueProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'not json' => ['{oops'],
            'json scalar' => ['5'],
        ];
    }

    public function testDuplicatesAreDropped()
    {
        $rule = RuleFixture::rule(['payment_methods' => ['checkmo', 'checkmo']]);

        $this->assertSame(['checkmo'], $rule->getPaymentMethods());
    }

    public function testActiveFlagRoundTrips()
    {
        $this->assertFalse(RuleFixture::rule(['active' => false])->isActive());
        $this->assertTrue(RuleFixture::rule(['active' => true])->isActive());
    }
}
