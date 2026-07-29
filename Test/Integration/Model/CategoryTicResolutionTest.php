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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\CategoryTicResolver;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The category level of TIC resolution, against real categories.
 *
 * Everything this covers depends on machinery a unit test has to fake: the
 * `taxcloud_tic` category attribute actually existing (installed by
 * Setup/Patch/Data/AddCategoryTicAttribute), the EAV store-scope fallback that
 * makes a store-view override win over the default-scope value, and the
 * materialized `path` column the ancestor walk reads. A wrong attribute code,
 * the wrong scope on the install patch, or a category collection that ignores
 * the store id would all pass unit tests and fail here.
 *
 * Fixture tree, built under the default store's root:
 *
 *   Root
 *    └── TaxCloud IT Parent          (level 2)
 *         ├── TaxCloud IT Child      (level 3)
 *         └── TaxCloud IT Sibling    (level 3)
 */
class CategoryTicResolutionTest extends IntegrationTestCase
{
    private const PARENT_NAME  = 'TaxCloud IT Parent';
    private const CHILD_NAME   = 'TaxCloud IT Child';
    private const SIBLING_NAME = 'TaxCloud IT Sibling';

    private const SKU_PLAIN = 'taxcloud-it-cat-plain';
    private const SKU_OWN_TIC = 'taxcloud-it-cat-own-tic';

    private const PARENT_TIC = '20010';
    private const CHILD_TIC = '40030';
    private const SIBLING_TIC = '91099';
    private const OWN_TIC = '11111';
    private const STORE_TIC = '55555';

    /** @var int */
    private $parentId;

    /** @var int */
    private $childId;

    /** @var int */
    private $siblingId;

    /** @var int[] */
    private $createdCategoryIds = [];

    /** @var string[] */
    private $createdSkus = [];

    /**
     * TIC writes made against categories this test does NOT own (the seeded
     * "Test Category", the store root), as [categoryId, storeId] pairs. Cleared
     * in tearDown; categories we created are dropped wholesale instead.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private $foreignTicWrites = [];

    protected function setUp(): void
    {
        parent::setUp();

        $rootId = (int) $this->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getRootCategoryId();

        $this->parentId  = $this->createCategory(self::PARENT_NAME, $rootId);
        $this->childId   = $this->createCategory(self::CHILD_NAME, $this->parentId);
        $this->siblingId = $this->createCategory(self::SIBLING_NAME, $this->parentId);

        // A product with no TIC of its own, in the deepest category.
        $this->createProduct(self::SKU_PLAIN, null, [$this->childId]);
        // Same placement, but carrying its own product-level TIC.
        $this->createProduct(self::SKU_OWN_TIC, self::OWN_TIC, [$this->childId]);
    }

    protected function tearDown(): void
    {
        foreach ($this->foreignTicWrites as [$categoryId, $storeId]) {
            try {
                // Blank, not null: the resolver treats an empty value as unset,
                // and clearing beats leaving a TIC on a shared fixture.
                $this->writeCategoryTic($categoryId, '', $storeId);
            } catch (\Throwable $e) {
                // Category already gone; nothing to restore.
            }
        }
        $this->foreignTicWrites = [];

        $this->inSecureArea(function () {
            foreach ($this->createdSkus as $sku) {
                try {
                    $this->get(ProductRepositoryInterface::class)->deleteById($sku);
                } catch (\Throwable $e) {
                    // Already gone; nothing to undo.
                }
            }

            // Deepest first, so deleting a parent never orphans a child we still hold.
            foreach (array_reverse($this->createdCategoryIds) as $categoryId) {
                try {
                    $this->get(CategoryRepositoryInterface::class)->deleteByIdentifier($categoryId);
                } catch (\Throwable $e) {
                    // Already gone (or removed with its parent); nothing to undo.
                }
            }
        });

        $this->createdSkus = [];
        $this->createdCategoryIds = [];

        $this->resetResolver();

        parent::tearDown();
    }

    /**
     * R3: a TIC on an ancestor covers everything beneath it, so merchants don't
     * have to tag every leaf category.
     */
    public function testAncestorCategoryTicIsInherited(): void
    {
        $this->setCategoryTic($this->parentId, self::PARENT_TIC);

        $this->assertSame(
            self::PARENT_TIC,
            $this->resolveTic(self::SKU_PLAIN),
            'A product in a child category should inherit the parent category TIC.'
        );
    }

    /**
     * R4: the deepest category wins, so a leaf overrides what it inherits.
     */
    public function testDeepestCategoryWinsOverAncestor(): void
    {
        $this->setCategoryTic($this->parentId, self::PARENT_TIC);
        $this->setCategoryTic($this->childId, self::CHILD_TIC);

        $this->assertSame(
            self::CHILD_TIC,
            $this->resolveTic(self::SKU_PLAIN),
            'The most specific category carrying a TIC should win over its ancestors.'
        );
    }

    /**
     * R4 tie-break: two categories at the same depth both carry a TIC, so the
     * lowest entity id decides — deterministically, request after request.
     */
    public function testLowestCategoryIdWinsAtEqualDepth(): void
    {
        $this->setCategoryTic($this->childId, self::CHILD_TIC);
        $this->setCategoryTic($this->siblingId, self::SIBLING_TIC);

        $this->assignCategories(self::SKU_PLAIN, [$this->childId, $this->siblingId]);

        $expected = $this->childId < $this->siblingId ? self::CHILD_TIC : self::SIBLING_TIC;

        $this->assertSame(
            $expected,
            $this->resolveTic(self::SKU_PLAIN),
            'With both categories at the same depth, the lower category id must win.'
        );
    }

    /**
     * R2: the product's own TIC outranks anything its categories say.
     */
    public function testProductTicWinsOverCategoryTic(): void
    {
        $this->setCategoryTic($this->childId, self::CHILD_TIC);

        $this->assertSame(
            self::OWN_TIC,
            $this->resolveTic(self::SKU_OWN_TIC),
            'A TIC set on the product itself must beat the category TIC.'
        );
    }

    /**
     * R2: with nothing at the product or category level, the store's configured
     * default TIC applies.
     */
    public function testFallsBackToDefaultTicWhenNoCategoryCarriesOne(): void
    {
        $this->assertSame(
            (string) $this->get(ProductTicService::class)->getDefaultTic(),
            $this->resolveTic(self::SKU_PLAIN),
            'No product and no category TIC should leave the store default in place.'
        );
    }

    /**
     * R6: the category attribute is store-scoped, so a store-view override wins
     * for that store while other stores keep the default-scope value.
     */
    public function testStoreViewOverrideWinsForThatStoreOnly(): void
    {
        $secondStoreId = $this->secondStoreId();

        $this->setCategoryTic($this->childId, self::CHILD_TIC);
        $this->setCategoryTic($this->childId, self::STORE_TIC, $secondStoreId);

        $this->assertSame(
            self::STORE_TIC,
            $this->resolveTic(self::SKU_PLAIN, $secondStoreId),
            'The second store view should see its own category TIC override.'
        );

        $this->resetResolver();

        $this->assertSame(
            self::CHILD_TIC,
            $this->resolveTic(self::SKU_PLAIN, 'default'),
            'The default store view should still see the default-scope category TIC.'
        );
    }

    /**
     * A TIC on the store's root category is deliberately ignored: a store-wide
     * TIC is what the Default TIC setting is for, and honoring the root would
     * silently give merchants two places to set the same thing.
     */
    public function testTicOnTheStoreRootCategoryIsIgnored(): void
    {
        $rootId = (int) $this->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getRootCategoryId();

        $this->setCategoryTic($rootId, '77777');

        $this->assertSame(
            (string) $this->get(ProductTicService::class)->getDefaultTic(),
            $this->resolveTic(self::SKU_PLAIN),
            'A TIC on the store root category must not be applied to products.'
        );
    }

    /**
     * End to end: the category TIC has to survive the whole path into the SOAP
     * payload, not just come out of the resolver. Everything between
     * ProductTicService and the Lookup request (the tax collector, the request
     * builder, the store the quote belongs to) is real here.
     */
    public function testCategoryTicReachesTheLookupPayload(): void
    {
        // The seeded test-product (no TIC of its own, already assigned to the
        // seeded "Test Category") is used here rather than this test's own
        // fixtures: a freshly created product is not salable until the stock
        // index catches up, and addProduct() rejects it.
        $this->setCategoryTic($this->seededProductCategoryId(), self::CHILD_TIC);

        $soap = $this->installSoapMock();

        $this->buildQuoteWithTestProduct();

        $this->assertGreaterThanOrEqual(
            1,
            $soap->callCount('lookup'),
            'Collecting totals should have triggered a lookup SOAP call.'
        );

        $cartItems = $soap->firstCallArgs('lookup')['cartItems'] ?? [];
        $productLines = array_values(array_filter(
            $cartItems,
            static fn ($line) => ($line['ItemID'] ?? null) !== 'shipping'
        ));

        $this->assertCount(1, $productLines, 'Expected exactly one product line in the lookup payload.');
        $this->assertSame(
            self::CHILD_TIC,
            (string) ($productLines[0]['TIC'] ?? null),
            'The lookup payload must carry the TIC inherited from the product\'s category.'
        );
    }

    // -- fixtures --------------------------------------------------------------

    /**
     * The TIC ProductTicService resolves for a SKU, through the real service.
     *
     * @param int|string|null $store
     */
    private function resolveTic(string $sku, $store = null): string
    {
        $this->resetResolver();

        // forceReload so category assignments written after the product was
        // created are actually seen.
        $product = $this->get(ProductRepositoryInterface::class)->get($sku, false, null, true);

        $item = new DataObject();
        $item->setProduct($product);
        $item->setSku($sku);

        return (string) $this->get(ProductTicService::class)->getProductTic($item, 'integration-test', $store);
    }

    /**
     * Clear the resolver's per-request memo. Integration tests edit categories
     * between assertions inside one PHP process, which a web request never does.
     */
    private function resetResolver(): void
    {
        $this->get(CategoryTicResolver::class)->reset();
    }

    /**
     * The category the seeded test-product lives in.
     */
    private function seededProductCategoryId(): int
    {
        /** @var Product $product */
        $product = $this->get(ProductRepositoryInterface::class)->get(self::TEST_PRODUCT_SKU);
        $categoryIds = array_map('intval', (array) $product->getCategoryIds());

        $this->assertNotEmpty(
            $categoryIds,
            'Seed data missing: "' . self::TEST_PRODUCT_SKU . '" should be assigned to a category '
            . '(run scripts/seed-test-data.php).'
        );

        return (int) reset($categoryIds);
    }

    private function createCategory(string $name, int $parentId): int
    {
        /** @var Category $category */
        $category = $this->get(CategoryFactory::class)->create();
        $category->setName($name)
            ->setParentId($parentId)
            ->setIsActive(true)
            ->setIncludeInMenu(false)
            ->setAvailableSortBy('name')
            ->setDefaultSortBy('name');

        $category = $this->get(CategoryRepositoryInterface::class)->save($category);
        $categoryId = (int) $category->getId();
        $this->createdCategoryIds[] = $categoryId;

        return $categoryId;
    }

    /**
     * Write the category TIC at the given store scope (default scope when
     * $storeId is omitted).
     *
     * @param int|null $storeId
     */
    private function setCategoryTic(int $categoryId, string $tic, ?int $storeId = null): void
    {
        $storeId = $storeId ?? \Magento\Store\Model\Store::DEFAULT_STORE_ID;

        if (!in_array($categoryId, $this->createdCategoryIds, true)) {
            $this->foreignTicWrites[] = [$categoryId, $storeId];
        }

        $this->writeCategoryTic($categoryId, $tic, $storeId);
    }

    /**
     * The write itself.
     *
     * CategoryRepository::save() takes the scope from the *ambient* store, not
     * from the category's store_id — it overwrites store_id with
     * `storeManager->getStore()->getId()` before saving. Writing a store-view
     * override therefore means switching the current store around the save,
     * which is exactly what the admin does.
     */
    private function writeCategoryTic(int $categoryId, string $tic, int $storeId): void
    {
        $storeManager = $this->get(StoreManagerInterface::class);
        $previousStoreId = $storeManager->getStore()->getId();

        $storeManager->setCurrentStore($storeId);
        try {
            /** @var Category $category */
            $category = $this->get(CategoryRepositoryInterface::class)->get($categoryId, $storeId);
            $category->setStoreId($storeId);
            $category->setData(CategoryTicResolver::ATTRIBUTE_CODE, $tic);
            $this->get(CategoryRepositoryInterface::class)->save($category);
        } finally {
            $storeManager->setCurrentStore($previousStoreId);
        }

        $this->resetResolver();
    }

    /**
     * @param int[] $categoryIds
     */
    private function createProduct(string $sku, ?string $tic, array $categoryIds): void
    {
        $websiteId = (int) $this->get(StoreManagerInterface::class)->getStore('default')->getWebsiteId();

        /** @var Product $product */
        $product = $this->get(ProductFactory::class)->create();
        $product->setSku($sku)
            ->setName($sku)
            ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
            ->setPrice(10.00)
            ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([$websiteId])
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        if ($tic !== null) {
            $product->setData('taxcloud_tic', $tic);
        }

        $this->get(ProductRepositoryInterface::class)->save($product);
        $this->createdSkus[] = $sku;

        $this->assignCategories($sku, $categoryIds);
    }

    /**
     * @param int[] $categoryIds
     */
    private function assignCategories(string $sku, array $categoryIds): void
    {
        $this->get(CategoryLinkManagementInterface::class)
            ->assignProductToCategories($sku, $categoryIds);
    }
}
