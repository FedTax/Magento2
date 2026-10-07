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

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Model\OrderRule\Source\CustomerGroups;
use Taxcloud\Magento2\Model\OrderRule\Source\PaymentMethods;
use Taxcloud\Magento2\Model\OrderRule\Source\ShippingMethods;

/**
 * Describes a rule's filters in words, e.g. "Customer group: Wholesale ·
 * Shipping: [freight] Freight". Shared by the rules list and diagnostics so a
 * rule reads the same everywhere.
 */
class RuleSummary
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var array<string, OptionSourceInterface>
     */
    private $sources;

    /**
     * @var array<string, array<string, string>>
     */
    private $labels = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param CustomerGroups $groups
     * @param PaymentMethods $payments
     * @param ShippingMethods $shippings
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        CustomerGroups $groups,
        PaymentMethods $payments,
        ShippingMethods $shippings
    ) {
        $this->storeManager = $storeManager;
        $this->sources = [
            OrderRuleInterface::CUSTOMER_GROUP_IDS => $groups,
            OrderRuleInterface::PAYMENT_METHODS => $payments,
            OrderRuleInterface::SHIPPING_METHODS => $shippings,
        ];
    }

    /**
     * One phrase per non-empty filter, or "All orders" when there are none.
     *
     * @param OrderRuleInterface $rule
     * @return string[]
     */
    public function describe(OrderRuleInterface $rule): array
    {
        $parts = [];
        $filters = [
            OrderRuleInterface::STORE_IDS => [__('Store view'), $rule->getStoreIds()],
            OrderRuleInterface::CUSTOMER_GROUP_IDS => [__('Customer group'), $rule->getCustomerGroupIds()],
            OrderRuleInterface::PAYMENT_METHODS => [__('Payment'), $rule->getPaymentMethods()],
            OrderRuleInterface::SHIPPING_METHODS => [__('Shipping'), $rule->getShippingMethods()],
        ];
        foreach ($filters as $field => [$title, $values]) {
            if (!$values) {
                continue;
            }
            $labels = array_map(function ($value) use ($field) {
                return $this->label($field, (string) $value);
            }, $values);
            $parts[] = $title . ': ' . implode(', ', $labels);
        }
        if ($rule->getOrderPrefixes()) {
            $parts[] = __('Order number starts with') . ': ' . implode(', ', $rule->getOrderPrefixes());
        }
        return $parts ?: [(string) __('All orders')];
    }

    /**
     * @param string $field
     * @param string $value
     * @return string The option label, or the raw value when it is not (or no longer) an option
     */
    private function label(string $field, string $value): string
    {
        if ($field === OrderRuleInterface::STORE_IDS) {
            return $this->storeLabel((int) $value);
        }
        if (!isset($this->labels[$field])) {
            $this->labels[$field] = [];
            try {
                $this->flatten($this->sources[$field]->toOptionArray(), $this->labels[$field]);
            } catch (\Throwable $e) {
                // A broken carrier or payment method must not take the list down;
                // codes are still meaningful on their own.
                $this->labels[$field] = [];
            }
        }
        return $this->labels[$field][$value] ?? $value;
    }

    /**
     * @param int $storeId
     * @return string The store view name, or the id when it no longer exists
     */
    private function storeLabel(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable $e) {
            return (string) $storeId;
        }
    }

    /**
     * @param array $options
     * @param array<string, string> $into
     * @return void
     */
    private function flatten(array $options, array &$into): void
    {
        foreach ($options as $option) {
            $value = $option['value'] ?? null;
            if (is_array($value)) {
                $this->flatten($value, $into);
                continue;
            }
            if ($value !== null && $value !== '') {
                $into[(string) $value] = trim((string) ($option['label'] ?? $value));
            }
        }
    }
}
