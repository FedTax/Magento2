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

use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Decides how much of TaxCloud an order uses: the first active rule whose
 * filters all match, or the store view's "Report orders to TaxCloud" default.
 *
 * Within a filter any listed value matches; across filters every non-empty
 * one must; an empty filter matches everything. A subject value that is not
 * known (null) never satisfies a non-empty filter.
 */
class Evaluator
{
    /**
     * @var OrderRuleRepositoryInterface
     */
    private $repository;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param OrderRuleRepositoryInterface $repository
     * @param TaxcloudConfig $config
     */
    public function __construct(OrderRuleRepositoryInterface $repository, TaxcloudConfig $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    /**
     * @param MatchSubject $subject
     * @return Decision
     */
    public function evaluate(MatchSubject $subject): Decision
    {
        foreach ($this->repository->getActiveRules() as $rule) {
            $matched = $this->match($rule, $subject);
            if ($matched !== null) {
                return new Decision($rule->getAction(), (int) $rule->getId(), $rule->getName(), $matched);
            }
        }

        // No rule matched: the store view default. The subject's store, never
        // the ambient one.
        return new Decision(
            $this->config->isReportingByDefault($subject->getStoreId())
                ? OrderRuleInterface::ACTION_REPORT
                : OrderRuleInterface::ACTION_CALCULATE_ONLY
        );
    }

    /**
     * The matched filter values when every non-empty filter matches, else null.
     *
     * @param OrderRuleInterface $rule
     * @param MatchSubject $subject
     * @return array<string, int|string>|null
     */
    private function match(OrderRuleInterface $rule, MatchSubject $subject): ?array
    {
        $matched = [];

        $checks = [
            Decision::MATCHED_STORE => [$rule->getStoreIds(), $subject->getStoreId()],
            Decision::MATCHED_CUSTOMER_GROUP => [$rule->getCustomerGroupIds(), $subject->getCustomerGroupId()],
            Decision::MATCHED_PAYMENT_METHOD => [$rule->getPaymentMethods(), $subject->getPaymentMethod()],
            Decision::MATCHED_SHIPPING_METHOD => [$rule->getShippingMethods(), $subject->getShippingMethod()],
        ];
        foreach ($checks as $key => [$allowed, $value]) {
            if (!$allowed) {
                continue;
            }
            if ($value === null || !in_array($value, $allowed, true)) {
                return null;
            }
            $matched[$key] = $value;
        }

        $prefixes = $rule->getOrderPrefixes();
        if ($prefixes) {
            $prefix = $this->matchPrefix($prefixes, $subject->getOrderNumber());
            if ($prefix === null) {
                return null;
            }
            $matched[Decision::MATCHED_ORDER_PREFIX] = $prefix;
        }

        return $matched;
    }

    /**
     * @param string[] $prefixes
     * @param string|null $orderNumber
     * @return string|null The prefix that matched
     */
    private function matchPrefix(array $prefixes, ?string $orderNumber): ?string
    {
        if ($orderNumber === null) {
            return null;
        }
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && stripos($orderNumber, $prefix) === 0) {
                return $prefix;
            }
        }
        return null;
    }
}
