<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration\Rest;

use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Bundle\Model\Product\Price as BundlePrice;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\ItemIdResolver;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;
use Taxcloud\Magento2\Test\Integration\TestEnvironment;

/**
 * A bundle with Dynamic SKU is ordered under its own SKU plus every selected
 * selection's — routinely past the 50 characters TaxCloud accepts as an item
 * ID, at which point the whole lookup is rejected. Field case: a shelf planner
 * whose bundle SKUs run to 90 characters.
 *
 * The shortened ID is derived from the SKU, so it is only safe if Magento
 * really carries one SKU through quote, order and credit memo, and if every
 * payload derives from it. Both are facts about real Magento, which is why
 * this is an integration test: the bundles are real, the SKU is Magento's own
 * composition, and every payload for one order is built from the placed order.
 *
 * Pure builders, no API calls: placement runs on the SOAP mock (the seeded
 * api_type), as in RestCompositePayloadAgreementTest.
 */
class LongSkuItemIdAgreementTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    /** Fixed price, Dynamic SKU: the parent is the line — 55 characters with its selection. */
    private const FIXED_SKU = 'test-bundle-fixed-dynsku-planner-closet-it';

    /** Dynamic price, Dynamic SKU: the selections are the lines; the 67-character wrapper never is. */
    private const DYNAMIC_SKU = 'test-bundle-dynamic-dynsku-planner-closet';

    private const RATE = 0.10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => $this->flatRateLookupResponder(self::RATE),
        ]));
        $this->ensureBundle(self::FIXED_SKU, BundlePrice::PRICE_TYPE_FIXED, [['test-product', 1]]);
        $this->ensureBundle(
            self::DYNAMIC_SKU,
            BundlePrice::PRICE_TYPE_DYNAMIC,
            [['test-product', 1], ['test-virtual', 2]]
        );
    }

    public static function tearDownAfterClass(): void
    {
        $registry = TestEnvironment::get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            foreach ([self::FIXED_SKU, self::DYNAMIC_SKU] as $sku) {
                try {
                    TestEnvironment::get(ProductRepositoryInterface::class)->deleteById($sku);
                } catch (\Throwable $e) {
                    // Never created, or already gone.
                }
            }
        } finally {
            $registry->unregister('isSecureArea');
        }
        parent::tearDownAfterClass();
    }

    /**
     * @return array<string, array{sku: string, longLine: bool}>
     */
    public static function bundleProvider(): array
    {
        return [
            'fixed price: the long parent is the line' => ['sku' => self::FIXED_SKU, 'longLine' => true],
            'dynamic price: the long wrapper is never a line' => ['sku' => self::DYNAMIC_SKU, 'longLine' => false],
        ];
    }

    /**
     * @dataProvider bundleProvider
     */
    #[DataProvider('bundleProvider')]
    public function testEveryPayloadNamesTheLinesByTheSameIdsWithinTheLimit(string $sku, bool $longLine): void
    {
        $order = $this->placeOrderFor($sku);
        $parentSku = (string) $this->parentItem($order)->getSku();
        $this->assertGreaterThan(
            ItemIdResolver::MAX_LENGTH,
            strlen($parentSku),
            'Precondition: Magento must have composed a Dynamic SKU longer than TaxCloud accepts.'
        );

        $lookupIds = $this->productIds(array_column($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? [], 'ItemID'));
        $this->assertNotEmpty($lookupIds, 'The lookup must have sent product lines.');
        foreach ($lookupIds as $itemId) {
            $this->assertLessThanOrEqual(ItemIdResolver::MAX_LENGTH, strlen($itemId), "Item ID over the limit: $itemId");
        }
        if ($longLine) {
            $this->assertSame([ItemIdResolver::fromSku($parentSku)], $lookupIds);
        } else {
            $this->assertNotContains($parentSku, $lookupIds);
            $this->assertNotContains(ItemIdResolver::fromSku($parentSku), $lookupIds);
        }

        $restBuilder = $this->get(RestRequestBuilder::class);
        $requestBuilder = $this->get(RequestBuilder::class);

        $this->assertSame(
            $lookupIds,
            $this->productIds(array_column($restBuilder->buildOrderPayload($order)['lineItems'], 'itemId')),
            'The v3 order is filed under different item ids than the lookup sent.'
        );
        $this->assertSame(
            $lookupIds,
            $this->productIds(array_column($requestBuilder->buildCartItemsFromOrder($order), 'ItemID')),
            'The full-return / exempt re-create cart names different item ids than the lookup.'
        );

        // Adjustment-only: no lines, no shipping, a small amount spread over
        // what remains. Built before the full memo below takes everything.
        $this->payInvoice($order);
        $adjustment = $this->get(CreditmemoFactory::class)->createByOrder($order, [
            'qtys' => array_fill_keys(array_map(static fn ($item) => (int) $item->getId(), $order->getAllItems()), 0),
            'shipping_amount' => 0,
            'adjustment_positive' => 1.00,
        ]);
        $distribution = $this->get(RefundDistributor::class)->distribute($adjustment);
        $this->assertSame(RefundDistributor::ACTION_DISTRIBUTE, $distribution['action'], $distribution['reason']);
        $this->assertSame(
            $lookupIds,
            $this->productIds(array_column($distribution['cartItems'], 'ItemID')),
            'An adjustment-only refund names different item ids than the lookup — the bundle wrapper is the usual cause.'
        );

        $full = $this->get(CreditmemoFactory::class)->createByOrder($order, $order->getData());
        $this->assertSame(
            $lookupIds,
            $this->productIds(array_column($requestBuilder->buildReturnCartItems($full)['cartItems'], 'ItemID')),
            'The v1 return names different item ids than the lookup.'
        );
        $this->assertSame(
            $lookupIds,
            $this->productIds(array_column($restBuilder->buildRefundItems($full)['items'], 'itemId')),
            'The v3 refund references item ids the order was never filed under.'
        );
    }

    private function placeOrderFor(string $sku): Order
    {
        $product = $this->get(ProductRepositoryInterface::class)->get($sku, false, null, true);

        $quote = $this->newGuestQuote();
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, 2)));
        $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }

    private function parentItem(Order $order): Order\Item
    {
        foreach ($order->getAllItems() as $item) {
            if (!$item->getParentItemId() && $item->getProductType() === ProductType::TYPE_BUNDLE) {
                return $item;
            }
        }
        $this->fail('The order has no bundle line.');
    }

    /**
     * Product item ids — shipping and the delivery-fee sentinel dropped —
     * de-duplicated and sorted, so payloads can be compared as sets.
     *
     * @param array<int, mixed> $itemIds
     * @return array<int, string>
     */
    private function productIds(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $itemIds),
            static fn (string $id) => $id !== 'shipping'
                && $id !== \Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService::ITEM_ID
        )));
        sort($ids);

        return $ids;
    }

    /**
     * A Dynamic SKU bundle over seeded selections, created once per run the way
     * scripts/seed-test-data.php builds the seeded bundles.
     *
     * @param array<int, array{0: string, 1: int}> $selections [sku, qty] pairs
     */
    private function ensureBundle(string $sku, int $priceType, array $selections): void
    {
        $repository = $this->get(ProductRepositoryInterface::class);
        try {
            $repository->get($sku);
            return;
        } catch (NoSuchEntityException $e) {
            // create below
        }

        foreach ($selections as [$selectionSku]) {
            $this->seededProduct($selectionSku);
        }

        $links = [];
        $position = 1;
        foreach ($selections as [$selectionSku, $selectionQty]) {
            $link = $this->get(LinkInterfaceFactory::class)->create();
            $link->setSku($selectionSku)
                ->setQty($selectionQty)
                ->setCanChangeQuantity(0)
                ->setIsDefault(true)
                ->setPosition($position++)
                ->setPriceType($priceType)
                ->setPrice(0.00);
            $links[] = $link;
        }
        $option = $this->get(OptionInterfaceFactory::class)->create();
        $option->setTitle('Planner Selections')
            ->setType('checkbox')
            ->setRequired(true)
            ->setPosition(1)
            ->setSku($sku)
            ->setProductLinks($links);

        $product = $this->get(ProductFactory::class)->create();
        $product->setSku($sku)
            ->setName('Planner ' . $sku)
            ->setUrlKey($sku . '-taxcloud-it')
            ->setTypeId(ProductType::TYPE_BUNDLE)
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())
            ->setStatus(Status::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([(int) $this->get(StoreManagerInterface::class)->getStore('default')->getWebsiteId()])
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1])
            ->setPriceType($priceType)
            ->setSkuType(0)      // Dynamic SKU: the order SKU grows every selection's
            ->setPriceView(0)
            ->setShipmentType(\Magento\Bundle\Model\Product\Type::SHIPMENT_TOGETHER);
        if ($priceType === BundlePrice::PRICE_TYPE_FIXED) {
            $product->setPrice(50.00)->setWeightType(1)->setWeight(1);
        } else {
            $product->setWeightType(0);
        }

        $extension = $product->getExtensionAttributes();
        $extension->setBundleProductOptions([$option]);
        $product->setExtensionAttributes($extension);

        $productId = (int) $repository->save($product)->getId();

        // The install runs its indexers on schedule, so a product saved here has
        // no stock-status row and loads as unsalable ("Product that you are
        // trying to add is not available") until its row is indexed. The seeded
        // catalog is fully reindexed by the seed script; this one is not.
        $indexers = $this->get(\Magento\Framework\Indexer\IndexerRegistry::class);
        foreach (['cataloginventory_stock', 'inventory', 'catalog_product_price'] as $indexerId) {
            try {
                $indexers->get($indexerId)->reindexRow($productId);
            } catch (\InvalidArgumentException $e) {
                // 'inventory' exists only where MSI is installed.
            }
        }
    }
}
