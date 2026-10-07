<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\OrderRule;

use Magento\Framework\Exception\LocalizedException;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;

/**
 * Normalises and validates a rule before it is saved.
 *
 * Lives on the repository's save path rather than in the admin form so the
 * controller, any programmatic caller and the tests share one set of rules.
 */
class RuleValidator
{
    private const ACTIONS = [
        OrderRuleInterface::ACTION_REPORT,
        OrderRuleInterface::ACTION_CALCULATE_ONLY,
        OrderRuleInterface::ACTION_SKIP,
    ];

    /**
     * @param OrderRuleInterface $rule
     * @return void
     * @throws LocalizedException With a message fit to show the administrator
     */
    public function validate(OrderRuleInterface $rule): void
    {
        $rule->setName(trim($rule->getName()));
        $rule->setOrderPrefixes($this->normalisePrefixes($rule->getOrderPrefixes()));

        if ($rule->getName() === '') {
            throw new LocalizedException(__('Give the rule a name.'));
        }

        if (!in_array($rule->getAction(), self::ACTIONS, true)) {
            throw new LocalizedException(__('Choose what the rule does with matching orders.'));
        }

        // Skip is decided at checkout, before the order has a number: a prefix
        // filter could never match there, so the rule would silently not do
        // what it says.
        if ($rule->getAction() === OrderRuleInterface::ACTION_SKIP && $rule->getOrderPrefixes()) {
            throw new LocalizedException(__(
                'A rule that skips TaxCloud cannot filter on the order number: the decision is made at checkout, '
                . 'before the order is numbered. Remove the order number prefixes, or choose "Calculate only".'
            ));
        }
    }

    /**
     * Trim, drop empties and duplicates (case-insensitively, since matching is).
     *
     * @param string[] $prefixes
     * @return string[]
     */
    private function normalisePrefixes(array $prefixes): array
    {
        $result = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix === '') {
                continue;
            }
            // First spelling wins.
            $result[strtolower($prefix)] = $result[strtolower($prefix)] ?? $prefix;
        }
        return array_values($result);
    }
}
