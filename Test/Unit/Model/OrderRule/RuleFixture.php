<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Taxcloud\Magento2\Model\OrderRule\Rule;

/**
 * Real Rule models without Magento's model constructor: the getters, setters
 * and JSON handling under test are plain data-array code, so only the id
 * field name — normally set by _init() through the resource — is filled in.
 */
final class RuleFixture
{
    /**
     * @param array $fields Setter-style values: name, active, action, store_ids, ...
     * @return Rule
     */
    public static function rule(array $fields = []): Rule
    {
        $reflection = new \ReflectionClass(Rule::class);
        /** @var Rule $rule */
        $rule = $reflection->newInstanceWithoutConstructor();
        $idField = new \ReflectionProperty(\Magento\Framework\Model\AbstractModel::class, '_idFieldName');
        $idField->setValue($rule, 'rule_id');

        if (isset($fields['id'])) {
            $rule->setData('rule_id', $fields['id']);
        }
        $rule->setName($fields['name'] ?? 'Rule');
        $rule->setIsActive($fields['active'] ?? true);
        $rule->setAction($fields['action'] ?? Rule::ACTION_CALCULATE_ONLY);
        $rule->setSortOrder($fields['sort_order'] ?? 0);
        $rule->setStoreIds($fields['store_ids'] ?? []);
        $rule->setCustomerGroupIds($fields['customer_group_ids'] ?? []);
        $rule->setPaymentMethods($fields['payment_methods'] ?? []);
        $rule->setShippingMethods($fields['shipping_methods'] ?? []);
        $rule->setOrderPrefixes($fields['order_prefixes'] ?? []);
        return $rule;
    }
}
