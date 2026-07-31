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

namespace Taxcloud\Magento2\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\CategoryTicResolver;

/**
 * The category level of TIC resolution.
 *
 * Categories are represented as plain DataObjects rather than Category mocks:
 * the resolver only reads level/path/id/taxcloud_tic off them, and a DataObject
 * carries all four through its real accessors on every supported PHPUnit
 * version (see Test/Unit/Double/Doubles.php on why we avoid mocking heavy
 * catalog models where a data carrier will do).
 */
#[AllowMockObjectsWithoutExpectations]
class CategoryTicResolverTest extends TestCase
{
    private const STORE_ID = 3;
    private const ATTRIBUTE_ID = 512;

    /** @var CategoryCollectionFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $collectionFactory;

    /** @var EavConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $eavConfig;

    /** @var ResourceConnection&\PHPUnit\Framework\MockObject\MockObject */
    private $resourceConnection;

    /** @var AdapterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    /** @var StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var Logger&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var CategoryTicResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->eavConfig->method('getAttribute')
            ->willReturn(new DataObject(['id' => self::ATTRIBUTE_ID]));

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static function ($name) {
                return $name;
            });

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->resolver = new CategoryTicResolver(
            $this->collectionFactory,
            $this->eavConfig,
            $this->resourceConnection,
            $this->storeManager,
            $this->logger
        );
    }

    /**
     * The short-circuit that keeps stores which never set a category TIC from
     * paying for category loads: one probe query, then nothing.
     */
    public function testSkipsResolutionEntirelyWhenNoCategoryCarriesATic()
    {
        $this->probeFinds(false);
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertNull($this->resolver->resolve($this->productInCategories([41])));
    }

    public function testReturnsNullWhenTheProductIsNotACatalogProduct()
    {
        $this->probeFinds(true);
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertNull($this->resolver->resolve(null));
    }

    public function testReturnsNullWhenTheProductHasNoCategories()
    {
        $this->probeFinds(true);
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertNull($this->resolver->resolve($this->productInCategories([])));
    }

    /**
     * R4: the most specific (deepest) category wins, so a TIC on "Grocery >
     * Candy" beats one on "Grocery".
     */
    public function testDeepestCategoryWins()
    {
        $this->probeFinds(true);
        $this->stubCollections(
            [$this->category(41, 3, '1/2/38/41')],
            [
                $this->category(38, 2, '1/2/38', '20010'),
                $this->category(41, 3, '1/2/38/41', '40030'),
            ]
        );

        $this->assertSame('40030', $this->resolver->resolve($this->productInCategories([41])));
    }

    /**
     * R4 tie-break: same depth, so the lowest entity id decides. Without this the
     * TIC would depend on collection ordering and could differ between requests.
     */
    public function testLowestIdWinsAmongCategoriesAtTheSameDepth()
    {
        $this->probeFinds(true);
        $this->stubCollections(
            [$this->category(55, 2, '1/2/55'), $this->category(44, 2, '1/2/44')],
            [
                // Deliberately iterated highest-id first: the winner must come
                // from the rule, not from the order rows happen to arrive in.
                $this->category(55, 2, '1/2/55', '99999'),
                $this->category(44, 2, '1/2/44', '20010'),
            ]
        );

        $this->assertSame('20010', $this->resolver->resolve($this->productInCategories([44, 55])));
    }

    /**
     * R3: the assigned category has no TIC of its own, so the nearest ancestor
     * that does supplies it.
     */
    public function testAncestorTicAppliesWhenTheAssignedCategoryHasNone()
    {
        $this->probeFinds(true);
        $this->stubCollections(
            [$this->category(41, 3, '1/2/38/41')],
            [
                $this->category(38, 2, '1/2/38', '20010'),
                $this->category(41, 3, '1/2/38/41'),
            ]
        );

        $this->assertSame('20010', $this->resolver->resolve($this->productInCategories([41])));
    }

    /**
     * A TIC on the tree root (level 0) or a store root (level 1) is ignored — a
     * store-wide TIC is what the default_tic setting is for.
     */
    public function testTicOnRootCategoriesIsIgnored()
    {
        $this->probeFinds(true);
        $this->stubCollections(
            [$this->category(41, 2, '1/2/41')],
            [
                $this->category(1, 0, '1', '11111'),
                $this->category(2, 1, '1/2', '22222'),
                $this->category(41, 2, '1/2/41'),
            ]
        );

        $this->assertNull($this->resolver->resolve($this->productInCategories([41])));
    }

    /**
     * Empty and whitespace-only values mean "not set", not a TIC of "".
     */
    public function testBlankCategoryTicIsTreatedAsUnset()
    {
        $this->probeFinds(true);
        $this->stubCollections(
            [$this->category(41, 3, '1/2/38/41')],
            [
                $this->category(38, 2, '1/2/38', '20010'),
                $this->category(41, 3, '1/2/38/41', '   '),
            ]
        );

        $this->assertSame(
            '20010',
            $this->resolver->resolve($this->productInCategories([41])),
            'A blank TIC on the deeper category must not shadow the ancestor that has one.'
        );
    }

    /**
     * R6: values are read at the store the caller passed, not the ambient one.
     */
    public function testCategoriesAreLoadedAtTheRequestedStoreScope()
    {
        $this->probeFinds(true);

        $paths = $this->collection([$this->category(41, 2, '1/2/41')]);
        $values = $this->collection([$this->category(41, 2, '1/2/41', '20010')]);
        foreach ([$paths, $values] as $collection) {
            $collection->expects($this->once())->method('setStoreId')->with(self::STORE_ID);
        }
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls($paths, $values);

        $this->assertSame('20010', $this->resolver->resolve($this->productInCategories([41]), self::STORE_ID));
    }

    /**
     * Totals collection runs the resolver once per line item and again on every
     * recollect; the same category set must not re-query.
     */
    public function testResolutionIsMemoizedPerStoreAndCategorySet()
    {
        $this->probeFinds(true);
        $this->stubCollections(
            [$this->category(41, 2, '1/2/41')],
            [$this->category(41, 2, '1/2/41', '20010')]
        );
        // Two loads for the first resolve, none for the rest.
        $this->collectionFactory->expects($this->exactly(2))->method('create');

        $product = $this->productInCategories([41]);
        $this->assertSame('20010', $this->resolver->resolve($product));
        $this->assertSame('20010', $this->resolver->resolve($product));
        // Same set, different assignment order — the memo key is order-insensitive.
        $this->assertSame('20010', $this->resolver->resolve($this->productInCategories([41])));
    }

    /**
     * The resolver runs inside quote totals collection: a catalog or schema
     * failure has to degrade to the store default TIC, never break the cart.
     */
    public function testCatalogFailureFallsBackToNullAndLogs()
    {
        $this->probeFinds(true);
        $this->collectionFactory->method('create')
            ->willThrowException(new \RuntimeException('catalog_category_entity is gone'));

        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->resolver->resolve($this->productInCategories([41])));
    }

    /**
     * An install that has not run setup:upgrade yet has no attribute, so the
     * feature reports itself unused instead of blowing up mid-checkout.
     */
    public function testMissingAttributeDisablesResolution()
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn(new DataObject());

        $resolver = new CategoryTicResolver(
            $this->collectionFactory,
            $eavConfig,
            $this->resourceConnection,
            $this->storeManager,
            $this->logger
        );

        $this->connection->expects($this->never())->method('fetchOne');
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertNull($resolver->resolve($this->productInCategories([41])));
    }

    // -- helpers ---------------------------------------------------------------

    /**
     * Stub the "does any category have a TIC?" probe.
     */
    private function probeFinds(bool $found): void
    {
        $this->connection->method('fetchOne')->willReturn($found ? '1' : false);
    }

    /**
     * Wire the two collection loads a single resolve() performs: first the
     * assigned categories (read for their paths), then the full candidate set
     * (read for their TICs).
     *
     * @param DataObject[] $pathRows
     * @param DataObject[] $valueRows
     */
    private function stubCollections(array $pathRows, array $valueRows): void
    {
        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->collection($pathRows),
            $this->collection($valueRows)
        );
    }

    /**
     * @param DataObject[] $rows
     * @return CategoryCollection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function collection(array $rows)
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();

        return $collection;
    }

    private function category(int $id, int $level, string $path, ?string $tic = null): DataObject
    {
        return new DataObject([
            'id' => $id,
            'entity_id' => $id,
            'level' => $level,
            'path' => $path,
            CategoryTicResolver::ATTRIBUTE_CODE => $tic,
        ]);
    }

    /**
     * @param int[] $categoryIds
     * @return Product&\PHPUnit\Framework\MockObject\MockObject
     */
    private function productInCategories(array $categoryIds)
    {
        $product = $this->createMock(Product::class);
        $product->method('getCategoryIds')->willReturn($categoryIds);

        return $product;
    }
}
