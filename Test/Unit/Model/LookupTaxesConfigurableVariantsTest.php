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

require_once __DIR__ . '/../Mocks/MagentoMocks.php';
require_once __DIR__ . '/../../../Model/ProductTicService.php';

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\ProductTicService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order\Item;
use Magento\Framework\Api\AttributeValue;
use Taxcloud\Magento2\Logger\Logger;

/**
 * Configurable / bundle / grouped TIC resolution.
 *
 * Magento upstream picks which product reference ends up on the order/quote item
 * (variant vs. parent), and ProductTicService is the single hop where TaxCloud
 * decides which TIC ships to the SOAP call. These tests pin the contract: whatever
 * product the item carries is the one we ask the repository about, and we never
 * fall back to a sibling product's TIC.
 */
class LookupTaxesConfigurableVariantsTest extends TestCase
{
    private $productTicService;
    private $scopeConfig;
    private $productRepository;
    private $logger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->productTicService = new ProductTicService(
            $this->scopeConfig,
            $this->productRepository,
            $this->logger
        );
    }

    /**
     * Build a mock item whose getProduct()->getId() returns the given variantId,
     * and wire the repository so getById(variantId) returns a product with the given TIC.
     */
    private function buildItemWithRepositoryTic($variantId, $taxcloudTic): Item
    {
        $item = $this->createMock(Item::class);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($variantId);
        $item->method('getProduct')->willReturn($product);
        $item->method('getSku')->willReturn('SKU-' . $variantId);

        $repoProduct = $this->createMock(Product::class);
        if ($taxcloudTic === null) {
            $repoProduct->method('getCustomAttribute')->with('taxcloud_tic')->willReturn(null);
        } else {
            $attr = $this->createMock(AttributeValue::class);
            $attr->method('getValue')->willReturn($taxcloudTic);
            $repoProduct->method('getCustomAttribute')->with('taxcloud_tic')->willReturn($attr);
        }
        $this->productRepository->method('getById')->with($variantId)->willReturn($repoProduct);

        return $item;
    }

    /**
     * Section 2.1: when the quote item carries the simple variant (not the configurable
     * parent), the variant's taxcloud_tic is used — the parent's TIC must not bleed through.
     */
    public function testTicResolvedFromChosenSimpleVariantNotParent()
    {
        $variantId = 4242;
        $item = $this->buildItemWithRepositoryTic($variantId, '20010');

        // If anyone accidentally looked up the parent, it would return a different TIC.
        // We intentionally don't wire that lookup — verifying the parent's TIC can't bleed in.
        $this->scopeConfig->expects($this->never())
            ->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        $tic = $this->productTicService->getProductTic($item, 'lookupTaxes');

        $this->assertSame('20010', $tic, 'Variant TIC must win — parent TIC must not bleed through');
    }

    /**
     * Section 2.2: bundle parent carries the TIC and children don't — bundle TIC wins.
     *
     * In Magento, bundle items reference the bundle product itself on the quote/order item.
     * ProductTicService therefore reads the bundle product's taxcloud_tic from the
     * repository; children are irrelevant to this resolution path.
     */
    public function testTicForBundleProductUsesParentTicWhenChildrenHaveNone()
    {
        $bundleId = 9001;
        $item = $this->buildItemWithRepositoryTic($bundleId, '11111');

        $tic = $this->productTicService->getProductTic($item, 'lookupTaxes');

        $this->assertSame('11111', $tic, 'Bundle parent TIC must be used when children have no TIC');
    }

    /**
     * Section 2.3: grouped product with no TIC anywhere falls back to configured default_tic.
     */
    public function testTicForGroupedProductFallsBackToDefaultWhenNoTicAnywhere()
    {
        $groupedId = 7777;
        $item = $this->buildItemWithRepositoryTic($groupedId, null);

        $this->scopeConfig->method('getValue')
            ->with('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('22222');

        $tic = $this->productTicService->getProductTic($item, 'lookupTaxes');

        $this->assertSame('22222', $tic, 'Grouped product with no TIC anywhere should fall back to default_tic');
    }
}
