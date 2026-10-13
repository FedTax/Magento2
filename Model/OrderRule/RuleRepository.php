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

use Magento\Framework\Exception\NoSuchEntityException;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Model\OrderRule\ResourceModel\Rule as RuleResource;
use Taxcloud\Magento2\Model\OrderRule\ResourceModel\Rule\CollectionFactory;

class RuleRepository implements OrderRuleRepositoryInterface
{
    /**
     * @var RuleFactory
     */
    private $ruleFactory;

    /**
     * @var RuleResource
     */
    private $resource;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var RuleValidator
     */
    private $validator;

    /**
     * Active rules for this request. Totals are collected several times per
     * checkout request, and every collection evaluates the rules; one query
     * serves them all. Cleared on every write.
     *
     * @var OrderRuleInterface[]|null
     */
    private $activeRules;

    /**
     * @param RuleFactory $ruleFactory
     * @param RuleResource $resource
     * @param CollectionFactory $collectionFactory
     * @param RuleValidator $validator
     */
    public function __construct(
        RuleFactory $ruleFactory,
        RuleResource $resource,
        CollectionFactory $collectionFactory,
        RuleValidator $validator
    ) {
        $this->ruleFactory = $ruleFactory;
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->validator = $validator;
    }

    /**
     * @inheritdoc
     */
    public function getById(int $ruleId): OrderRuleInterface
    {
        $rule = $this->ruleFactory->create();
        $this->resource->load($rule, $ruleId);
        if (!$rule->getId()) {
            throw new NoSuchEntityException(__('The order rule no longer exists.'));
        }
        return $rule;
    }

    /**
     * @inheritdoc
     */
    public function save(OrderRuleInterface $rule): OrderRuleInterface
    {
        /** @var Rule $rule */
        $this->validator->validate($rule);
        if (!$rule->getId()) {
            $rule->setSortOrder($this->nextSortOrder());
        }
        $this->resource->save($rule);
        $this->activeRules = null;
        return $rule;
    }

    /**
     * @inheritdoc
     */
    public function deleteById(int $ruleId): void
    {
        /** @var Rule $rule */
        $rule = $this->getById($ruleId);
        $this->resource->delete($rule);
        $this->activeRules = null;
    }

    /**
     * @inheritdoc
     */
    public function getList(): array
    {
        return $this->load(false);
    }

    /**
     * @inheritdoc
     */
    public function getActiveRules(): array
    {
        if ($this->activeRules === null) {
            $this->activeRules = $this->load(true);
        }
        return $this->activeRules;
    }

    /**
     * @inheritdoc
     */
    public function reorder(array $ruleIds): void
    {
        $ruleIds = array_values(array_unique(array_map('intval', $ruleIds)));
        $ordered = [];
        foreach ($this->getList() as $rule) {
            $position = array_search((int) $rule->getId(), $ruleIds, true);
            // Listed rules first, in the given order; unlisted ones after, in
            // their existing order (getList() is already sorted).
            $ordered[] = [$position === false ? PHP_INT_MAX : $position, count($ordered), $rule];
        }
        usort($ordered, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $connection->beginTransaction();
        try {
            foreach ($ordered as $index => $entry) {
                $connection->update(
                    $table,
                    [OrderRuleInterface::SORT_ORDER => ($index + 1) * 10],
                    [OrderRuleInterface::RULE_ID . ' = ?' => (int) $entry[2]->getId()]
                );
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->activeRules = null;
    }

    /**
     * @param bool $activeOnly
     * @return OrderRuleInterface[]
     */
    private function load(bool $activeOnly): array
    {
        $collection = $this->collectionFactory->create();
        if ($activeOnly) {
            $collection->addFieldToFilter(OrderRuleInterface::IS_ACTIVE, 1);
        }
        $collection->setOrder(OrderRuleInterface::SORT_ORDER, 'ASC');
        $collection->setOrder(OrderRuleInterface::RULE_ID, 'ASC');
        return array_values($collection->getItems());
    }

    /**
     * @return int
     */
    private function nextSortOrder(): int
    {
        $max = 0;
        foreach ($this->getList() as $rule) {
            $max = max($max, $rule->getSortOrder());
        }
        return $max + 10;
    }
}
