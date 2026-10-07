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

namespace Taxcloud\Magento2\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;

/**
 * Stores order processing rules and their evaluation order.
 */
interface OrderRuleRepositoryInterface
{
    /**
     * @param int $ruleId
     * @return OrderRuleInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $ruleId): OrderRuleInterface;

    /**
     * Validate and save a rule. A new rule is appended after every existing one.
     *
     * @param OrderRuleInterface $rule
     * @return OrderRuleInterface
     * @throws LocalizedException When the rule is invalid; the message is shown to the administrator
     */
    public function save(OrderRuleInterface $rule): OrderRuleInterface;

    /**
     * @param int $ruleId
     * @return void
     * @throws NoSuchEntityException
     */
    public function deleteById(int $ruleId): void;

    /**
     * All rules, in evaluation order.
     *
     * @return OrderRuleInterface[]
     */
    public function getList(): array;

    /**
     * Active rules, in evaluation order. Read once per request.
     *
     * @return OrderRuleInterface[]
     */
    public function getActiveRules(): array;

    /**
     * Rewrite the evaluation order. Rules missing from the list keep their
     * relative order after the listed ones.
     *
     * @param int[] $ruleIds In the new evaluation order
     * @return void
     */
    public function reorder(array $ruleIds): void;
}
