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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Setup\Patch\Data\AddCategoryTicAttribute;

/**
 * Resolves the category-level TIC for a product.
 *
 * The middle level of TaxCloud's three-level TIC resolution (product → category
 * → store default). {@see ProductTicService} owns the order; this class answers
 * one question: "given this product and this store, does any category above it
 * carry a TIC?".
 *
 * Rules, in the order they matter:
 *
 *  - Candidates are the product's assigned categories AND all of their
 *    ancestors, so a TIC set on "Grocery" covers every sub-category beneath it
 *    without the merchant tagging each leaf.
 *  - The tree roots (level 0 and the per-store root at level 1) are excluded.
 *    A store-wide TIC is what the `default_tic` setting is for.
 *  - Ties are broken deterministically: deepest (most specific) category first,
 *    then lowest entity id. Without a rule, a product in two TIC-bearing
 *    categories could get a different TIC from one request to the next.
 *  - Values are read at the given store's scope, so a store view can override
 *    the default-scope TIC.
 *
 * Cost control: stores that don't use the feature pay exactly one cheap
 * "does any category anywhere have a TIC?" query per request ({@see isInUse()}),
 * after which resolution short-circuits. Stores that do use it pay two
 * collection loads per distinct set of category assignments per request, memoized
 * for every later line item and every repeat totals collection.
 */
class CategoryTicResolver
{
    /**
     * Category attribute holding the TIC.
     */
    public const ATTRIBUTE_CODE = AddCategoryTicAttribute::ATTRIBUTE_CODE;

    /**
     * Shallowest category level a TIC is honored on. Level 0 is Magento's
     * internal tree root and level 1 is a store's root category — neither is a
     * merchandising category, and a TIC there would silently duplicate the
     * `default_tic` store setting.
     */
    public const MIN_CATEGORY_LEVEL = 2;

    /**
     * EAV value table backing a varchar category attribute.
     */
    private const VALUE_TABLE = 'catalog_category_entity_varchar';

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Null until probed; see {@see isInUse()}.
     *
     * @var bool|null
     */
    private $inUse;

    /**
     * Resolved TIC keyed "<storeId>:<sorted category ids>". Holds nulls too, so
     * a known-empty answer is not re-queried.
     *
     * @var array<string, string|null>
     */
    private $resolved = [];

    /**
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param EavConfig $eavConfig
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        CategoryCollectionFactory $categoryCollectionFactory,
        EavConfig $eavConfig,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->eavConfig = $eavConfig;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * The TIC inherited from the product's categories, or null when none applies.
     *
     * Never throws: this runs inside quote totals collection, so a catalog or
     * schema problem must degrade to the store default TIC rather than break
     * the cart.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface|null $product
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string|null
     */
    public function resolve($product, $store = null)
    {
        // getCategoryIds() lives on the concrete model, not ProductInterface.
        if (!$product instanceof Product) {
            return null;
        }

        try {
            if (!$this->isInUse()) {
                return null;
            }

            $categoryIds = $this->normalizeIds($product->getCategoryIds());
            if (!$categoryIds) {
                return null;
            }

            $storeId = (int) $this->storeManager->getStore($store)->getId();
            $key = $storeId . ':' . implode(',', $categoryIds);
            if (array_key_exists($key, $this->resolved)) {
                return $this->resolved[$key];
            }

            return $this->resolved[$key] = $this->findTic($categoryIds, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Category TIC resolution failed (' . $e->getMessage() . '); falling back to the default TIC'
            );

            return null;
        }
    }

    /**
     * Drop everything memoized so far.
     *
     * A web request is short-lived enough that the memo can simply live for its
     * duration. Long-running processes — queue consumers, cron, a test run that
     * edits categories between assertions — call this to pick up catalog changes
     * made after the first resolution.
     *
     * @return void
     */
    public function reset()
    {
        $this->inUse = null;
        $this->resolved = [];
    }

    /**
     * Whether any category anywhere carries a non-empty TIC.
     *
     * Read straight off the EAV value table rather than through a collection so
     * the probe is store-agnostic: a merchant who set the TIC only as a
     * store-view override, leaving the default-scope row empty, must still get
     * a "yes" here. Memoized for the request.
     *
     * Returns false when the attribute does not exist yet, which keeps the
     * module working on an install that has not run `setup:upgrade`.
     *
     * @return bool
     */
    private function isInUse()
    {
        if ($this->inUse !== null) {
            return $this->inUse;
        }

        $attributeId = (int) $this->eavConfig->getAttribute(Category::ENTITY, self::ATTRIBUTE_CODE)->getId();
        if ($attributeId === 0) {
            return $this->inUse = false;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::VALUE_TABLE), ['value_id'])
            ->where('attribute_id = ?', $attributeId)
            ->where('value IS NOT NULL')
            ->where('value != ?', '')
            ->limit(1);

        return $this->inUse = (bool) $connection->fetchOne($select);
    }

    /**
     * Pick the winning TIC among the given categories and their ancestors.
     *
     * @param int[] $categoryIds directly assigned category ids
     * @param int $storeId
     * @return string|null
     */
    private function findTic(array $categoryIds, $storeId)
    {
        $candidateIds = $this->withAncestors($categoryIds, $storeId);
        if (!$candidateIds) {
            return null;
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addIdFilter($candidateIds);
        $collection->addAttributeToSelect(self::ATTRIBUTE_CODE);

        $best = null;
        foreach ($collection as $category) {
            $level = (int) $category->getLevel();
            if ($level < self::MIN_CATEGORY_LEVEL) {
                continue;
            }

            $tic = trim((string) $category->getData(self::ATTRIBUTE_CODE));
            if ($tic === '') {
                continue;
            }

            $candidate = ['level' => $level, 'id' => (int) $category->getId(), 'tic' => $tic];
            if ($best === null || $this->beats($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best === null ? null : $best['tic'];
    }

    /**
     * Expand the assigned category ids with every ancestor, read off the
     * materialized `path` column (e.g. "1/2/38/41") so no recursive walk is
     * needed.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return int[]
     */
    private function withAncestors(array $categoryIds, $storeId)
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addIdFilter($categoryIds);

        $expanded = [];
        foreach ($collection as $category) {
            foreach (explode('/', (string) $category->getPath()) as $segment) {
                $id = (int) $segment;
                if ($id > 0) {
                    $expanded[$id] = $id;
                }
            }
        }

        return array_values($expanded);
    }

    /**
     * Tie-break: deepest category wins; equal depth resolves to the lowest id.
     *
     * @param array{level: int, id: int, tic: string} $candidate
     * @param array{level: int, id: int, tic: string} $incumbent
     * @return bool
     */
    private function beats(array $candidate, array $incumbent)
    {
        if ($candidate['level'] !== $incumbent['level']) {
            return $candidate['level'] > $incumbent['level'];
        }

        return $candidate['id'] < $incumbent['id'];
    }

    /**
     * Unique, positive, sorted ints. Sorting is what makes the memo key stable
     * for two products carrying the same assignments in a different order.
     *
     * @param mixed $ids
     * @return int[]
     */
    private function normalizeIds($ids)
    {
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        sort($normalized);

        return $normalized;
    }
}
