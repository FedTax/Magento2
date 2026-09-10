<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration\Rest;

use Magento\Framework\DataObject;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * The three v3 payloads for one order must describe the same goods.
 *
 * v3 is item-id addressed in a way v1 was not: the cart is filed under the item
 * ids in POST /carts, the order under those in POST /orders, and a refund
 * references an order's item ids by name. Any disagreement is not a rounding
 * difference — it is a reference the API cannot resolve, and it fails at refund
 * time, long after the sale.
 *
 * That divergence was real. buildOrderPayload walked getAllVisibleItems() and
 * filed the bundle WRAPPER, while the cart and the refund both name its
 * selections, so a dynamic bundle could be sold and then never cleanly refunded.
 *
 * Pure builders, no API calls: order placement runs on the SOAP mock (the seeded
 * api_type) and the payloads under test are built from the resulting order.
 */
class RestCompositePayloadAgreementTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    private const RATE = 0.10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => $this->flatRateLookupResponder(self::RATE),
        ]));
    }

    /**
     * @return array<string, array{sku: string, qty: int}>
     */
    public static function compositeProvider(): array
    {
        return [
            'bundle dynamic (selections carry the price)' => ['sku' => 'test-bundle-dynamic', 'qty' => 2],
            'bundle fixed (wrapper carries the price)'    => ['sku' => 'test-bundle-fixed', 'qty' => 2],
            'configurable (parent carries the price)'     => ['sku' => 'test-configurable', 'qty' => 2],
            'grouped (independent lines)'                 => ['sku' => 'test-grouped', 'qty' => 2],
            'simple (the control)'                        => ['sku' => 'test-product', 'qty' => 2],
        ];
    }

    /**
     * @dataProvider compositeProvider
     */
    #[DataProvider('compositeProvider')]
    public function testCartOrderAndRefundNameTheSameItems(string $sku, int $qty): void
    {
        $order = $this->placeOrderFor($sku, $qty);

        // What the cart was filed under: the v1 lookup lines this order's quote
        // produced, which buildCartLineItems only reshapes.
        $cartItemIds = $this->itemIdsOf(
            $this->productLines($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? [])
        );

        /** @var RestRequestBuilder $restBuilder */
        $restBuilder = $this->get(RestRequestBuilder::class);

        $orderItemIds = array_values(array_filter(
            array_column($restBuilder->buildOrderPayload($order)['lineItems'], 'itemId'),
            static fn ($itemId) => $itemId !== 'shipping'
        ));

        $this->payInvoice($order);
        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, $order->getData());
        $refundItemIds = array_values(array_filter(
            array_column($restBuilder->buildRefundItems($creditmemo)['items'], 'itemId'),
            static fn ($itemId) => $itemId !== 'shipping'
        ));

        sort($cartItemIds);
        sort($orderItemIds);
        sort($refundItemIds);

        $this->assertSame(
            $cartItemIds,
            $orderItemIds,
            "The v3 order for $sku is filed under different item ids than its cart was."
        );
        $this->assertSame(
            $cartItemIds,
            $refundItemIds,
            "A refund of $sku references item ids the order never filed — v3 cannot resolve those."
        );
    }

    /**
     * And the money has to agree too, not just the names: what the order files
     * as tax is what the shopper was charged.
     *
     * @dataProvider compositeProvider
     */
    #[DataProvider('compositeProvider')]
    public function testOrderPayloadFilesTheTaxTheOrderCharged(string $sku, int $qty): void
    {
        $order = $this->placeOrderFor($sku, $qty);

        $payload = $this->get(RestRequestBuilder::class)->buildOrderPayload($order);

        $filedTax = 0.0;
        foreach ($payload['lineItems'] as $line) {
            $filedTax += (float) $line['tax']['amount'];
        }

        $this->assertEqualsWithDelta(
            (float) $order->getTaxAmount(),
            $filedTax,
            0.02,
            "The v3 order for $sku files a different tax total than the order charged. A composite "
            . 'wrapper counted alongside its children is the usual cause.'
        );
    }

    /**
     * v3 rejects a refund carrying the same reference twice, so whatever the
     * credit memo's shape, each item id may appear at most once.
     *
     * @dataProvider compositeProvider
     */
    #[DataProvider('compositeProvider')]
    public function testRefundReferencesAreUnique(string $sku, int $qty): void
    {
        $order = $this->placeOrderFor($sku, $qty);
        $this->payInvoice($order);

        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, $order->getData());
        $itemIds = array_column($this->get(RestRequestBuilder::class)->buildRefundItems($creditmemo)['items'], 'itemId');

        $this->assertSame(
            array_values(array_unique($itemIds)),
            array_values($itemIds),
            "The v3 refund for $sku repeats an item reference; the API rejects duplicates outright."
        );
    }

    private function placeOrderFor(string $sku, int $qty): Order
    {
        $product = $this->seededProduct($sku);

        $quote = $this->newGuestQuote();
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, $qty)));
        $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * @param array<int, array{0: string, 1: float, 2: float}> $lines
     * @return array<int, string>
     */
    private function itemIdsOf(array $lines): array
    {
        return array_values(array_unique(array_column($lines, 0)));
    }
}
