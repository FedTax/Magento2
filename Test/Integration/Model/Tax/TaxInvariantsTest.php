<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration\Model\Tax;

use Magento\Framework\DataObject;
use PHPUnit\Framework\Attributes\DataProvider;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * Properties that must hold for every catalog type, at every stage of an order's
 * life, whatever the rate.
 *
 * These are the tests worth having. A per-type expectation only catches the
 * types someone thought to list; an invariant catches the type nobody has added
 * yet. Both defects found in the bundle work break an invariant here:
 *
 *  - the shopper was charged tax on a third of the goods (sum invariant)
 *  - TaxCloud was told the store sold $70 of goods out of a $50 cart, and the
 *    captured transaction kept that number (lifecycle invariant)
 *
 * Neither needed a rate to detect.
 */
class TaxInvariantsTest extends IntegrationTestCase
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
     * The shapes that differ structurally. Simple, virtual, downloadable and
     * gift card all behave as "one line, one product" and are covered per-type
     * in CatalogTypeLookupTest; repeating them through a full order lifecycle
     * would buy coverage of Magento, not of this module.
     *
     * @return array<string, array{sku: string, qty: int}>
     */
    public static function structuralShapeProvider(): array
    {
        return [
            'bundle dynamic (children carry the basis)' => ['sku' => 'test-bundle-dynamic', 'qty' => 3],
            'bundle fixed (parent carries the basis)'   => ['sku' => 'test-bundle-fixed', 'qty' => 2],
            'configurable (parent carries the basis)'   => ['sku' => 'test-configurable', 'qty' => 2],
            'grouped (independent lines)'               => ['sku' => 'test-grouped', 'qty' => 1],
            'simple (the control)'                      => ['sku' => 'test-product', 'qty' => 2],
        ];
    }

    /**
     * A refund has to describe the same goods the order authorized.
     *
     * The two sides read different tables — a quote child stores its quantity per
     * parent, an order child stores it absolutely — so it is entirely possible
     * for both to look locally correct and still disagree. When they do, TaxCloud
     * is asked to reverse something the store never reported selling.
     *
     * @dataProvider structuralShapeProvider
     */
    #[DataProvider('structuralShapeProvider')]
    public function testReturnDescribesTheSameGoodsAsTheLookup(string $sku, int $qty): void
    {
        $order = $this->placeOrderFor($sku, $qty);
        $soap = $this->soapClient();

        $lookupLines = $this->productLines($soap->firstCallArgs('lookup')['cartItems'] ?? []);

        // Nothing is refundable until it is paid for.
        $this->payInvoice($order);
        $soap->resetCalls();

        $this->refundOrder($order);

        $this->assertSame(
            1,
            $soap->callCount('Returned'),
            'Refunding should call Returned exactly once.'
        );

        $returnLines = $this->productLines($soap->firstCallArgs('Returned')['cartItems'] ?? []);

        $this->assertSame(
            $this->sortLines($lookupLines),
            $this->sortLines($returnLines),
            "The full refund of $sku returns different goods than the order looked up. "
            . 'One side is reading a composite line the other is not.'
        );
    }

    /**
     * The order's tax total has to be the tax on its lines.
     *
     * A children-priced bundle displays its wrapper's tax as the sum of its
     * children's, so counting the wrapper as well double-counts it — which is
     * exactly why Magento leaves it out of the address total, and why this sum
     * has to skip it too.
     *
     * @dataProvider structuralShapeProvider
     */
    #[DataProvider('structuralShapeProvider')]
    public function testOrderTaxEqualsItsLineTaxPlusShipping(string $sku, int $qty): void
    {
        $order = $this->placeOrderFor($sku, $qty);

        $lineTax = 0.0;
        foreach ($order->getAllItems() as $item) {
            // A wrapper's tax is an echo of its children's, not tax of its own.
            if (!$item->getParentItemId() && $item->isChildrenCalculated()) {
                continue;
            }
            $lineTax += (float) $item->getTaxAmount();
        }

        $this->assertEqualsWithDelta(
            (float) $order->getTaxAmount(),
            $lineTax + (float) $order->getShippingTaxAmount(),
            0.02,
            "Order $sku reports a tax total its own line items do not add up to. "
            . 'Either a line is taxed and not counted, or counted twice.'
        );
    }

    /**
     * Nothing may be reported to TaxCloud that the shopper was not charged for.
     *
     * The basis is checked against the quote in CatalogTypeLookupTest; here it is
     * checked against the placed order, after conversion — the point at which the
     * captured transaction becomes what gets filed.
     *
     * @dataProvider structuralShapeProvider
     */
    #[DataProvider('structuralShapeProvider')]
    public function testReportedBasisMatchesTheOrderSubtotal(string $sku, int $qty): void
    {
        $order = $this->placeOrderFor($sku, $qty);

        $basis = 0.0;
        foreach ($this->productLines($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? []) as $line) {
            $basis += $line[1] * $line[2];
        }

        $this->assertEqualsWithDelta(
            (float) $order->getSubtotal(),
            $basis,
            0.01,
            "The goods reported to TaxCloud for $sku are worth more or less than the order is."
        );
    }

    /**
     * Changing the quantity has to change what is reported.
     *
     * The collector snapshots pre-tax values to stop incl-tax compounding across
     * repeated collections, and that snapshot is keyed on quantity. Key it on the
     * wrong quantity — a bundle child's per-parent one, say — and a cart edit
     * either fails to invalidate or invalidates constantly.
     */
    public function testChangingBundleQuantityRestatesTheReportedGoods(): void
    {
        $product = $this->seededProduct('test-bundle-dynamic');

        $quote = $this->newGuestQuote();
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, 1)));
        $this->collectAndSaveQuote($quote);

        $this->assertSame(
            [['test-product', 1.0, 10.0], ['test-virtual', 2.0, 10.0]],
            $this->productLines($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? []),
            'One bundle should report one and two units of its selections.'
        );

        // Same cart, quantity raised the way a shopper raises it. The collected
        // flag has to be cleared by hand: Quote::collectTotals() returns early
        // while it is set, so without this the "after" assertion would just be
        // re-reading the "before" state.
        foreach ($quote->getAllItems() as $item) {
            if (!$item->getParentItem()) {
                $item->setQty(3);
            }
        }
        $this->soapClient()->resetCalls();
        $quote->setTotalsCollectedFlag(false);
        $this->collectAndSaveQuote($quote);

        $this->assertSame(
            [['test-product', 3.0, 10.0], ['test-virtual', 6.0, 10.0]],
            $this->productLines($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? []),
            'Raising a bundle to qty 3 should triple the units reported, not leave them stale.'
        );
    }

    /**
     * Place an order holding one seeded product.
     */
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
     * Order-independent comparison: the two payloads are built by different code
     * walking different tables, so line order is not part of the contract.
     */
    private function sortLines(array $lines): array
    {
        usort($lines, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $lines;
    }
}
