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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

// Load the ProductTicService class directly
require_once __DIR__ . '/../../../Model/ProductTicService.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\CategoryTicResolver;
use Taxcloud\Magento2\Model\ProductTicService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order\Item;
use Magento\Framework\Api\AttributeValue;
use Taxcloud\Magento2\Logger\Logger;

#[AllowMockObjectsWithoutExpectations]
class ProductTicServiceTest extends TestCase
{
    private $productTicService;
    private $scopeConfig;
    private $productRepository;
    private $logger;
    private $categoryTicResolver;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);
        // Unstubbed, resolve() returns null — i.e. "no category carries a TIC",
        // which is the state every pre-existing assertion here was written for.
        $this->categoryTicResolver = $this->createMock(CategoryTicResolver::class);

        $this->productTicService = new ProductTicService(
            new \Taxcloud\Magento2\Model\Config\TaxcloudConfig($this->scopeConfig),
            $this->productRepository,
            $this->logger,
            $this->categoryTicResolver
        );
    }

    /**
     * Test getProductTic with valid product having custom TIC
     */
    public function testGetProductTicWithValidProductAndCustomTic()
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);
        $productModel = $this->createMock(Product::class);
        $customAttribute = $this->createMock(AttributeValue::class);

        $item->method('getSku')->willReturn('TEST_SKU');
        $item->method('getProduct')->willReturn($product);

        $product->method('getId')->willReturn(123);

        $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn($customAttribute);

        $customAttribute->method('getValue')->willReturn('20000');

        $this->productRepository->method('getById')->with(123)->willReturn($productModel);

        $result = $this->productTicService->getProductTic($item, 'testContext');

        $this->assertEquals('20000', $result, 'Should return custom TIC value when product has custom TIC');
    }

    /**
     * Test getProductTic with deleted/null product
     */
    public function testGetProductTicWithDeletedProduct()
    {
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn('DELETED_SKU');
        $item->method('getProduct')->willReturn(null);

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('00000');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Product not found for item DELETED_SKU in testContext, using default TIC');

        $result = $this->productTicService->getProductTic($item, 'testContext');

        $this->assertEquals('00000', $result, 'Should return default TIC when product is null');
    }

    /**
     * Test getProductTic with product having no ID
     */
    public function testGetProductTicWithProductHavingNoId()
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);
        
        $item->method('getSku')->willReturn('NO_ID_SKU');
        $item->method('getProduct')->willReturn($product);
        $product->method('getId')->willReturn(null);

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('00000');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Product not found for item NO_ID_SKU in testContext, using default TIC');

        $result = $this->productTicService->getProductTic($item, 'testContext');

        $this->assertEquals('00000', $result, 'Should return default TIC when product has no ID');
    }

    /**
     * Test getProductTic with product having no custom TIC attribute
     */
    public function testGetProductTicWithNoCustomTicAttribute()
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);
        $productModel = $this->createMock(Product::class);

        $item->method('getSku')->willReturn('NO_TIC_SKU');
        $item->method('getProduct')->willReturn($product);

        $product->method('getId')->willReturn(456);

        $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn(null);

        $this->productRepository->method('getById')->with(456)->willReturn($productModel);

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('00000');

        $result = $this->productTicService->getProductTic($item, 'testContext');

        $this->assertEquals('00000', $result, 'Should return default TIC when product has no custom TIC attribute');
    }

    /**
     * Test getProductTic with empty context parameter
     */
    public function testGetProductTicWithEmptyContext()
    {
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn('TEST_SKU');
        $item->method('getProduct')->willReturn(null);

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('00000');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Product not found for item TEST_SKU in , using default TIC');

        $result = $this->productTicService->getProductTic($item, '');

        $this->assertEquals('00000', $result, 'Should return default TIC when context is empty');
    }

    /**
     * Test getDefaultTic with various configurations
     * @dataProvider defaultTicProvider
     */
    #[DataProvider('defaultTicProvider')]
    public function testGetDefaultTic($configValue, $expectedResult, $description)
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn($configValue);

        $result = $this->productTicService->getDefaultTic();

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function defaultTicProvider()
    {
        return [
            'configured value' => ['12345', '12345', 'Should return configured default TIC value'],
            'null configuration' => [null, '00000', 'Should return fallback default TIC value when configuration is null'],
            'empty string' => ['', '00000', 'Should return fallback default TIC value when configuration is empty'],
            'zero value' => ['0', '0', 'Should return zero if configured as zero'],
        ];
    }

    /**
     * Test isProductValid with various product states
     * @dataProvider productValidationProvider
     */
    #[DataProvider('productValidationProvider')]
    public function testIsProductValid($productId, $expectedResult, $description)
    {
        $item = $this->createMock(Item::class);
        
        if ($productId === 'null_product') {
            $item->method('getProduct')->willReturn(null);
        } else {
            $product = $this->createMock(Product::class);
            $item->method('getProduct')->willReturn($product);
            $product->method('getId')->willReturn($productId);
        }

        $result = $this->productTicService->isProductValid($item);

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function productValidationProvider()
    {
        return [
            'valid product with ID' => [789, true, 'Should return true for valid product with ID'],
            'null product' => ['null_product', false, 'Should return false for null product'],
            'product with no ID' => [null, false, 'Should return false for product with no ID'],
            'product with zero ID' => [0, false, 'Should return false for product with zero ID'],
        ];
    }

    /**
     * Test getShippingTic with various configurations
     * @dataProvider shippingTicProvider
     */
    #[DataProvider('shippingTicProvider')]
    public function testGetShippingTic($configValue, $expectedResult, $description)
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn($configValue);

        $result = $this->productTicService->getShippingTic();

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function shippingTicProvider()
    {
        return [
            'default configured value' => ['11010', '11010', 'Should return configured shipping TIC value'],
            'null configuration' => [null, '11010', 'Should return fallback shipping TIC value when configuration is null'],
            'custom configured value' => ['99999', '99999', 'Should return custom configured shipping TIC value'],
            'empty string' => ['', '11010', 'Should return fallback shipping TIC value when configuration is empty'],
        ];
    }

    /**
     * T6a: the item's product still has an id, but the repository can no longer
     * load it (NoSuchEntityException) — getProductTic must log and fall back to
     * the default TIC rather than surface the exception.
     */
    public function testGetProductTicUsesDefaultWhenRepositoryThrowsNoSuchEntity()
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);

        $item->method('getSku')->willReturn('GONE_SKU');
        $item->method('getProduct')->willReturn($product);
        $product->method('getId')->willReturn(999);
        // getTypeId() defaults to null → treated as a non-configurable (simple) line.

        $this->productRepository->method('getById')->with(999)
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException(__('gone')));

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('00000');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Product ID 999 not found in repository for returnOrder, using default TIC');

        $result = $this->productTicService->getProductTic($item, 'returnOrder');

        $this->assertEquals('00000', $result, 'Repository miss must fall back to the default TIC');
    }

    /**
     * T6b: a configurable line item carries the configurable parent, but the TIC
     * must come from the purchased child simple. getProductTic must redirect to
     * the child (via getChildren()) and return that variant's TIC.
     */
    public function testGetProductTicRedirectsConfigurableLineToChildVariant()
    {
        $childProduct = $this->createMock(Product::class);
        $childProduct->method('getId')->willReturn(555);

        // A quote line exposes its purchased variant via getChildren().
        $child = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $child->method('getProduct')->willReturn($childProduct);

        $configurable = $this->createMock(Product::class);
        $configurable->method('getTypeId')->willReturn(ProductTicService::TYPE_CONFIGURABLE);

        $item = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $item->method('getSku')->willReturn('CONFIG_SKU');
        $item->method('getProduct')->willReturn($configurable);
        $item->method('getChildren')->willReturn([$child]);

        $productModel = $this->createMock(Product::class);
        $customAttribute = $this->createMock(AttributeValue::class);
        $customAttribute->method('getValue')->willReturn('30000');
        $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn($customAttribute);
        // The child variant's id (555), not the configurable parent, drives the lookup.
        $this->productRepository->method('getById')->with(555)->willReturn($productModel);

        $result = $this->productTicService->getProductTic($item, 'lookupTaxes');

        $this->assertEquals('30000', $result, 'Configurable line must resolve the child variant TIC');
    }

    /**
     * R2, level 1: a TIC on the product itself wins outright — the category
     * level is never consulted.
     */
    public function testProductTicWinsOverCategoryTic()
    {
        $item = $this->itemWithProduct(123);
        $this->productRepository->method('getById')->with(123)
            ->willReturn($this->productModelWithTic('20000'));

        $this->categoryTicResolver->expects($this->never())->method('resolve');

        $this->assertEquals('20000', $this->productTicService->getProductTic($item, 'lookupTaxes'));
    }

    /**
     * R2, level 2: no product TIC, so the category level supplies one and the
     * store default is never reached.
     */
    public function testCategoryTicUsedWhenProductHasNoTicOfItsOwn()
    {
        $item = $this->itemWithProduct(123);
        $this->productRepository->method('getById')->with(123)
            ->willReturn($this->productModelWithTic(null));

        $this->categoryTicResolver->method('resolve')->willReturn('40030');

        $this->assertEquals('40030', $this->productTicService->getProductTic($item, 'lookupTaxes'));
    }

    /**
     * A product TIC set to blank means "not set" — it must fall through to the
     * category level rather than shipping an empty TIC to TaxCloud.
     */
    public function testBlankProductTicFallsThroughToTheCategoryLevel()
    {
        $item = $this->itemWithProduct(123);
        $this->productRepository->method('getById')->with(123)
            ->willReturn($this->productModelWithTic('  '));

        $this->categoryTicResolver->method('resolve')->willReturn('40030');

        $this->assertEquals('40030', $this->productTicService->getProductTic($item, 'lookupTaxes'));
    }

    /**
     * R2, level 3: nothing at the product or category level, so the store
     * default applies.
     */
    public function testDefaultTicUsedWhenNeitherProductNorCategoryHasOne()
    {
        $item = $this->itemWithProduct(123);
        $this->productRepository->method('getById')->with(123)
            ->willReturn($this->productModelWithTic(null));

        $this->categoryTicResolver->method('resolve')->willReturn(null);

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('11111');

        $this->assertEquals('11111', $this->productTicService->getProductTic($item, 'lookupTaxes'));
    }

    /**
     * R5: a configurable's TIC comes from the purchased child simple, but child
     * simples are typically assigned to no categories. When the child yields no
     * category TIC, the parent the line carries is consulted before dropping to
     * the store default.
     */
    public function testConfigurableFallsBackToTheParentsCategories()
    {
        $childProduct = $this->createMock(Product::class);
        $childProduct->method('getId')->willReturn(555);

        $child = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $child->method('getProduct')->willReturn($childProduct);

        $parent = $this->createMock(Product::class);
        $parent->method('getId')->willReturn(100);
        $parent->method('getTypeId')->willReturn(ProductTicService::TYPE_CONFIGURABLE);

        $item = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $item->method('getSku')->willReturn('CONFIG_SKU');
        $item->method('getProduct')->willReturn($parent);
        $item->method('getChildren')->willReturn([$child]);

        $variantModel = $this->productModelWithTic(null);
        $variantModel->method('getId')->willReturn(555);
        $this->productRepository->method('getById')->with(555)->willReturn($variantModel);

        // The variant is in no categories; the configurable parent is.
        $this->categoryTicResolver->method('resolve')
            ->willReturnCallback(static function ($product) use ($parent) {
                return $product === $parent ? '20010' : null;
            });

        $this->assertEquals(
            '20010',
            $this->productTicService->getProductTic($item, 'lookupTaxes'),
            'Configurable variant with no categories must inherit the parent line product\'s category TIC'
        );
    }

    /**
     * A deleted product has no categories to consult — resolution stops at the
     * store default without touching the category level.
     */
    public function testDeletedProductSkipsTheCategoryLevel()
    {
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn('DELETED_SKU');
        $item->method('getProduct')->willReturn(null);

        $this->categoryTicResolver->expects($this->never())->method('resolve');

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('00000');

        $this->assertEquals('00000', $this->productTicService->getProductTic($item, 'lookupTaxes'));
    }

    /**
     * An order/quote line carrying a simple product with the given id.
     */
    private function itemWithProduct($productId)
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);

        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn('TEST_SKU');
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    /**
     * A product model whose taxcloud_tic custom attribute holds $tic, or has no
     * such attribute at all when $tic is null.
     */
    private function productModelWithTic($tic)
    {
        $productModel = $this->createMock(Product::class);

        if ($tic === null) {
            $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn(null);

            return $productModel;
        }

        $attribute = $this->createMock(AttributeValue::class);
        $attribute->method('getValue')->willReturn($tic);
        $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn($attribute);

        return $productModel;
    }
}
