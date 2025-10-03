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

// Load Magento mocks before any other includes
require_once __DIR__ . '/../Mocks/MagentoMocks.php';

// Load the ProductTicService class directly
require_once __DIR__ . '/../../../Model/ProductTicService.php';

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\ProductTicService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order\Item;
use Magento\Framework\Api\AttributeValue;
use Taxcloud\Magento2\Logger\Logger;

class ProductTicServiceTest extends TestCase
{
    private $productTicService;
    private $scopeConfig;
    private $productFactory;
    private $logger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->productFactory = $this->createMock(ProductFactory::class);
        $this->logger = $this->createMock(Logger::class);

        $this->productTicService = new ProductTicService(
            $this->scopeConfig,
            $this->productFactory,
            $this->logger
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

        $productModel->method('load')->with(123)->willReturnSelf();
        $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn($customAttribute);

        $customAttribute->method('getValue')->willReturn('20000');

        $this->productFactory->method('create')->willReturn($productModel);

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
            ->method('info')
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
            ->method('info')
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

        $productModel->method('load')->with(456)->willReturnSelf();
        $productModel->method('getCustomAttribute')->with('taxcloud_tic')->willReturn(null);

        $this->productFactory->method('create')->willReturn($productModel);

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
            ->method('info')
            ->with('Product not found for item TEST_SKU in , using default TIC');

        $result = $this->productTicService->getProductTic($item, '');

        $this->assertEquals('00000', $result, 'Should return default TIC when context is empty');
    }

    /**
     * Test getDefaultTic with configured value
     */
    public function testGetDefaultTicWithConfiguredValue()
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('12345');

        // Execute the method
        $result = $this->productTicService->getDefaultTic();

        $this->assertEquals('12345', $result, 'Should return configured default TIC value');
    }

    /**
     * Test getDefaultTic with null configuration (fallback)
     */
    public function testGetDefaultTicWithNullConfiguration()
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        $result = $this->productTicService->getDefaultTic();

        $this->assertEquals('00000', $result, 'Should return fallback default TIC value when configuration is null');
    }

    /**
     * Test isProductValid with valid product
     */
    public function testIsProductValidWithValidProduct()
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);
        
        $item->method('getProduct')->willReturn($product);
        $product->method('getId')->willReturn(789);

        $result = $this->productTicService->isProductValid($item);

        $this->assertTrue($result, 'Should return true for valid product with ID');
    }

    /**
     * Test isProductValid with null product
     */
    public function testIsProductValidWithNullProduct()
    {
        $item = $this->createMock(Item::class);
        $item->method('getProduct')->willReturn(null);

        $result = $this->productTicService->isProductValid($item);

        $this->assertFalse($result, 'Should return false for null product');
    }

    /**
     * Test isProductValid with product having no ID
     */
    public function testIsProductValidWithProductHavingNoId()
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);
        
        $item->method('getProduct')->willReturn($product);
        $product->method('getId')->willReturn(null);

        $result = $this->productTicService->isProductValid($item);

        $this->assertFalse($result, 'Should return false for product with no ID');
    }

    /**
     * Test getShippingTic with configured value
     */
    public function testGetShippingTicWithConfiguredValue()
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('11010');

        $result = $this->productTicService->getShippingTic();

        $this->assertEquals('11010', $result, 'Should return configured shipping TIC value');
    }

    /**
     * Test getShippingTic with null configuration (fallback)
     */
    public function testGetShippingTicWithNullConfiguration()
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        $result = $this->productTicService->getShippingTic();

        $this->assertEquals('11010', $result, 'Should return fallback shipping TIC value when configuration is null');
    }

    /**
     * Test getShippingTic with custom configured value
     */
    public function testGetShippingTicWithCustomConfiguredValue()
    {
        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('99999');

        $result = $this->productTicService->getShippingTic();

        $this->assertEquals('99999', $result, 'Should return custom configured shipping TIC value');
    }

}
