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

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that ProductTicService reads `taxcloud_tic` from the real EAV
 * attribute the module installs (Setup/Patch/Data/InstallTaxcloudData) — not a
 * stubbed getCustomAttribute(). The product is created and reloaded through the
 * real repository, so a wrong attribute code, scope, or a missing install patch
 * would surface here.
 */
class ProductTicResolutionFromEavTest extends IntegrationTestCase
{
    private const SKU = 'taxcloud-it-tic-product';
    private const TIC = '20010';

    protected function tearDown(): void
    {
        try {
            $this->get(ProductRepositoryInterface::class)->deleteById(self::SKU);
        } catch (\Throwable $e) {
            // Product may not have been created; ignore.
        }
        parent::tearDown();
    }

    public function testProductTicReadsRealEavAttribute(): void
    {
        $this->createProductWithTic(self::SKU, self::TIC);

        // Load fresh (forceReload) so we read the persisted EAV value, not the
        // in-memory object we just saved.
        $product = $this->get(ProductRepositoryInterface::class)->get(self::SKU, false, null, true);

        // ProductTicService::getProductTic() takes an *item* exposing getProduct()
        // and getSku(); a DataObject is a faithful stand-in for a quote/order item.
        $item = new DataObject();
        $item->setProduct($product);
        $item->setSku(self::SKU);

        $tic = $this->get(ProductTicService::class)->getProductTic($item, 'integration-test');

        $this->assertSame(
            self::TIC,
            $tic,
            'ProductTicService should return the taxcloud_tic value stored on the real EAV attribute.'
        );
    }

    private function createProductWithTic(string $sku, string $tic): void
    {
        $om = $this->objectManager();
        $websiteId = (int) $this->get(StoreManagerInterface::class)->getStore('default')->getWebsiteId();

        /** @var Product $product */
        $product = $this->get(ProductFactory::class)->create();
        $product->setSku($sku)
            ->setName('TaxCloud IT TIC Product')
            ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
            ->setPrice(10.00)
            ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([$websiteId])
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1])
            ->setData('taxcloud_tic', $tic);

        $this->get(ProductRepositoryInterface::class)->save($product);
    }
}
