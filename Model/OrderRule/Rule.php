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

use Magento\Framework\Model\AbstractModel;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;

/**
 * Order processing rule.
 *
 * The filter fields are stored as JSON arrays. The getters decode whatever is
 * in the data array — a JSON string straight from the database (load or
 * collection) or an array set in code — so models loaded either way behave
 * the same; the setters always store JSON, which is what gets saved.
 */
class Rule extends AbstractModel implements OrderRuleInterface
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Rule::class);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    /**
     * @inheritdoc
     */
    public function setName(string $name)
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * @inheritdoc
     */
    public function isActive(): bool
    {
        return (bool) $this->getData(self::IS_ACTIVE);
    }

    /**
     * @inheritdoc
     */
    public function setIsActive(bool $active)
    {
        return $this->setData(self::IS_ACTIVE, $active ? 1 : 0);
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return (int) $this->getData(self::SORT_ORDER);
    }

    /**
     * @inheritdoc
     */
    public function setSortOrder(int $sortOrder)
    {
        return $this->setData(self::SORT_ORDER, $sortOrder);
    }

    /**
     * @inheritdoc
     */
    public function getAction(): string
    {
        return (string) $this->getData(self::ACTION);
    }

    /**
     * @inheritdoc
     */
    public function setAction(string $action)
    {
        return $this->setData(self::ACTION, $action);
    }

    /**
     * @inheritdoc
     */
    public function getStoreIds(): array
    {
        return array_map('intval', $this->getList(self::STORE_IDS));
    }

    /**
     * @inheritdoc
     */
    public function setStoreIds(array $storeIds)
    {
        return $this->setList(self::STORE_IDS, array_map('intval', $storeIds));
    }

    /**
     * @inheritdoc
     */
    public function getCustomerGroupIds(): array
    {
        return array_map('intval', $this->getList(self::CUSTOMER_GROUP_IDS));
    }

    /**
     * @inheritdoc
     */
    public function setCustomerGroupIds(array $groupIds)
    {
        return $this->setList(self::CUSTOMER_GROUP_IDS, array_map('intval', $groupIds));
    }

    /**
     * @inheritdoc
     */
    public function getPaymentMethods(): array
    {
        return array_map('strval', $this->getList(self::PAYMENT_METHODS));
    }

    /**
     * @inheritdoc
     */
    public function setPaymentMethods(array $methods)
    {
        return $this->setList(self::PAYMENT_METHODS, array_map('strval', $methods));
    }

    /**
     * @inheritdoc
     */
    public function getShippingMethods(): array
    {
        return array_map('strval', $this->getList(self::SHIPPING_METHODS));
    }

    /**
     * @inheritdoc
     */
    public function setShippingMethods(array $methods)
    {
        return $this->setList(self::SHIPPING_METHODS, array_map('strval', $methods));
    }

    /**
     * @inheritdoc
     */
    public function getOrderPrefixes(): array
    {
        return array_map('strval', $this->getList(self::ORDER_PREFIXES));
    }

    /**
     * @inheritdoc
     */
    public function setOrderPrefixes(array $prefixes)
    {
        return $this->setList(self::ORDER_PREFIXES, array_map('strval', $prefixes));
    }

    /**
     * Decode a filter field, tolerating a stored JSON string, an array, or nothing.
     *
     * @param string $key
     * @return array
     */
    private function getList(string $key): array
    {
        $value = $this->getData($key);
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param string $key
     * @param array $values
     * @return $this
     */
    private function setList(string $key, array $values)
    {
        return $this->setData($key, json_encode(array_values(array_unique($values))));
    }
}
