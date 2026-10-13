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

namespace Taxcloud\Magento2\Api\Data;

/**
 * An order processing rule: which orders it matches, and how much of TaxCloud
 * those orders use.
 *
 * Every filter is a list; an empty list matches every order, several values
 * match any one of them, and a rule matches only when all its non-empty
 * filters do. Rules are evaluated in sort order and the first active match
 * decides.
 */
interface OrderRuleInterface
{
    /**#@+
     * Actions: how much of TaxCloud a matching order uses.
     */
    /** Calculated by TaxCloud and reported — normal behaviour. */
    public const ACTION_REPORT = 'report';
    /** Calculated by TaxCloud, never reported. */
    public const ACTION_CALCULATE_ONLY = 'calculate_only';
    /** No TaxCloud involvement; taxed natively as if TaxCloud were off. */
    public const ACTION_SKIP = 'skip';
    /**#@-*/

    /**#@+
     * Field names.
     */
    public const RULE_ID = 'rule_id';
    public const NAME = 'name';
    public const IS_ACTIVE = 'is_active';
    public const SORT_ORDER = 'sort_order';
    public const ACTION = 'action';
    public const STORE_IDS = 'store_ids';
    public const CUSTOMER_GROUP_IDS = 'customer_group_ids';
    public const PAYMENT_METHODS = 'payment_methods';
    public const SHIPPING_METHODS = 'shipping_methods';
    public const ORDER_PREFIXES = 'order_prefixes';
    /**#@-*/

    /**
     * @return int|null
     */
    public function getId();

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name);

    /**
     * @return bool
     */
    public function isActive(): bool;

    /**
     * @param bool $active
     * @return $this
     */
    public function setIsActive(bool $active);

    /**
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * @param int $sortOrder
     * @return $this
     */
    public function setSortOrder(int $sortOrder);

    /**
     * @return string One of the ACTION_* constants
     */
    public function getAction(): string;

    /**
     * @param string $action
     * @return $this
     */
    public function setAction(string $action);

    /**
     * @return int[]
     */
    public function getStoreIds(): array;

    /**
     * @param int[] $storeIds
     * @return $this
     */
    public function setStoreIds(array $storeIds);

    /**
     * @return int[]
     */
    public function getCustomerGroupIds(): array;

    /**
     * @param int[] $groupIds
     * @return $this
     */
    public function setCustomerGroupIds(array $groupIds);

    /**
     * @return string[]
     */
    public function getPaymentMethods(): array;

    /**
     * @param string[] $methods
     * @return $this
     */
    public function setPaymentMethods(array $methods);

    /**
     * @return string[] carrier_method codes
     */
    public function getShippingMethods(): array;

    /**
     * @param string[] $methods
     * @return $this
     */
    public function setShippingMethods(array $methods);

    /**
     * @return string[]
     */
    public function getOrderPrefixes(): array;

    /**
     * @param string[] $prefixes
     * @return $this
     */
    public function setOrderPrefixes(array $prefixes);
}
