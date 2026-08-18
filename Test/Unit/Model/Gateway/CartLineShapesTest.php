<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * One table, one row per catalog type: given the quote-item tree Magento builds
 * for that type, these are the cart lines TaxCloud must be told about.
 *
 * The shapes are not invented. Each row mirrors what scripts/verify-test-
 * products.php printed when it added the seeded product to a real quote, so a
 * row failing here means either the module changed or Magento did — and the
 * verify script is the tie-breaker.
 *
 * What varies between types is only ever two things: which line carries the
 * price, and what quantity that line stores. Everything else about tax is the
 * same, which is why this file is a table and not eight test classes.
 */
#[AllowMockObjectsWithoutExpectations]
class CartLineShapesTest extends TestCase
{
    private $productTicService;
    private RequestBuilder $builder;

    protected function setUp(): void
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $this->productTicService = $this->createMock(ProductTicService::class);
        $this->productTicService->method('getProductTic')->willReturn('20000');
        $this->productTicService->method('getShippingTic')->willReturn('11010');

        $this->builder = new RequestBuilder(
            $config,
            $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createMock(\Magento\Directory\Model\RegionFactory::class),
            $this->productTicService,
            $this->createMock(RefundDistributor::class),
            new NullLogger()
        );
    }

    /**
     * Quote-item trees, exactly as Magento stores them.
     *
     * 'items'    keyed by tax-calculation id; 'parent' refers to another key.
     * 'mapped'   the codes that reach the tax details. A configurable's child is
     *            absent on purpose: Magento never maps it, which is why it has
     *            no tax-calculation id to be keyed by.
     * 'expected' [sku, qty, price] per emitted cart line, in order.
     */
    public static function quoteShapeProvider(): array
    {
        return [
            'simple' => [
                'items' => [
                    'p' => ['sku' => 'test-product', 'qty' => 1, 'price' => 10.0],
                ],
                'mapped' => ['p'],
                'expected' => [['test-product', 1.0, 10.0]],
            ],
            'virtual' => [
                'items' => [
                    'p' => ['sku' => 'test-virtual', 'qty' => 1, 'price' => 10.0],
                ],
                'mapped' => ['p'],
                'expected' => [['test-virtual', 1.0, 10.0]],
            ],
            'downloadable' => [
                'items' => [
                    'p' => ['sku' => 'test-downloadable', 'qty' => 1, 'price' => 10.0],
                ],
                'mapped' => ['p'],
                'expected' => [['test-downloadable', 1.0, 10.0]],
            ],
            // A grouped product never reaches the quote: each association is its
            // own top-level line carrying its own absolute qty.
            'grouped' => [
                'items' => [
                    'a' => ['sku' => 'test-product', 'qty' => 1, 'price' => 10.0],
                    'b' => ['sku' => 'test-virtual', 'qty' => 1, 'price' => 10.0],
                ],
                'mapped' => ['a', 'b'],
                'expected' => [['test-product', 1.0, 10.0], ['test-virtual', 1.0, 10.0]],
            ],
            // Parent priced, child at 0 and never mapped. The parent's sku is the
            // chosen variant's, which is what TaxCloud should hear about.
            'configurable' => [
                'items' => [
                    'p' => ['sku' => 'test-variant-red', 'qty' => 1, 'price' => 10.0, 'children' => ['c']],
                    'c' => ['sku' => 'test-variant-red', 'qty' => 1, 'price' => 0.0, 'parent' => 'p'],
                ],
                'mapped' => ['p'],
                'expected' => [['test-variant-red', 1.0, 10.0]],
            ],
            // The regression. Selections carry the basis and store qty PER
            // bundle, so a qty-3 cart is 3 and 6 units, never 1 and 2.
            'bundle dynamic' => [
                'items' => [
                    'p' => [
                        'sku' => 'test-bundle-dynamic',
                        'qty' => 3,
                        'price' => 30.0,
                        'children' => ['c1', 'c2'],
                        'childrenCalculated' => true,
                    ],
                    'c1' => ['sku' => 'test-product', 'qty' => 1, 'price' => 10.0, 'parent' => 'p'],
                    'c2' => ['sku' => 'test-virtual', 'qty' => 2, 'price' => 10.0, 'parent' => 'p'],
                ],
                'mapped' => ['p', 'c1', 'c2'],
                'expected' => [['test-product', 3.0, 10.0], ['test-virtual', 6.0, 10.0]],
            ],
            // The inverse: the parent holds the whole price and the selection is
            // priced at 0, so only the parent is a cart line.
            'bundle fixed' => [
                'items' => [
                    'p' => ['sku' => 'test-bundle-fixed', 'qty' => 1, 'price' => 50.0, 'children' => ['c']],
                    'c' => ['sku' => 'test-product', 'qty' => 1, 'price' => 0.0, 'parent' => 'p'],
                ],
                'mapped' => ['p'],
                'expected' => [['test-bundle-fixed', 1.0, 50.0]],
            ],
            'giftcard' => [
                'items' => [
                    'p' => ['sku' => 'test-giftcard', 'qty' => 1, 'price' => 25.0],
                ],
                'mapped' => ['p'],
                'expected' => [['test-giftcard', 1.0, 25.0]],
            ],
        ];
    }

    /**
     * @dataProvider quoteShapeProvider
     */
    #[DataProvider('quoteShapeProvider')]
    public function testLookupCartLinesForEachCatalogType(array $items, array $mapped, array $expected): void
    {
        $quoteItems = $this->buildQuoteTree($items);

        $keyed = [];
        $itemsByType = ['product' => []];
        foreach ($mapped as $code) {
            $keyed[$code] = $quoteItems[$code];
            $itemsByType['product'][$code] = ['item' => 'x'];
        }

        $address = $this->getMockBuilder(Dbl\QuoteAddressDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getShippingAmount'])->getMock();
        $address->method('getShippingAmount')->willReturn(0.0);

        $built = $this->builder->buildLookupCartItems($itemsByType, $keyed, $address);

        $this->assertSame($expected, $this->linesOf($built['cartItems']));
    }

    /**
     * Order-side trees. The same carts, after conversion — where a composite
     * child's quantity is stored absolutely rather than per parent.
     *
     * 'visible'  what getAllVisibleItems() returns; children nest under it.
     * 'expected' [sku, qty, price] per emitted cart line.
     */
    public static function orderShapeProvider(): array
    {
        return [
            'simple' => [
                'visible' => [['sku' => 'test-product', 'qty' => 1.0, 'price' => 10.0]],
                'expected' => [['test-product', 1.0, 10.0]],
            ],
            'virtual' => [
                'visible' => [['sku' => 'test-virtual', 'qty' => 1.0, 'price' => 10.0]],
                'expected' => [['test-virtual', 1.0, 10.0]],
            ],
            'downloadable' => [
                'visible' => [['sku' => 'test-downloadable', 'qty' => 1.0, 'price' => 10.0]],
                'expected' => [['test-downloadable', 1.0, 10.0]],
            ],
            'grouped' => [
                'visible' => [
                    ['sku' => 'test-product', 'qty' => 1.0, 'price' => 10.0],
                    ['sku' => 'test-virtual', 'qty' => 1.0, 'price' => 10.0],
                ],
                'expected' => [['test-product', 1.0, 10.0], ['test-virtual', 1.0, 10.0]],
            ],
            'configurable' => [
                'visible' => [['sku' => 'test-variant-red', 'qty' => 1.0, 'price' => 10.0]],
                'expected' => [['test-variant-red', 1.0, 10.0]],
            ],
            // qty_ordered on the children is already 3 and 6 — the quote side's
            // multiplication has happened at conversion, and must not happen twice.
            'bundle dynamic' => [
                'visible' => [[
                    'sku' => 'test-bundle-dynamic',
                    'qty' => 3.0,
                    'price' => 30.0,
                    'childrenCalculated' => true,
                    'children' => [
                        ['sku' => 'test-product', 'qty' => 3.0, 'price' => 10.0],
                        ['sku' => 'test-virtual', 'qty' => 6.0, 'price' => 10.0],
                    ],
                ]],
                'expected' => [['test-product', 3.0, 10.0], ['test-virtual', 6.0, 10.0]],
            ],
            'bundle fixed' => [
                'visible' => [[
                    'sku' => 'test-bundle-fixed',
                    'qty' => 1.0,
                    'price' => 50.0,
                    'children' => [['sku' => 'test-product', 'qty' => 1.0, 'price' => 0.0]],
                ]],
                'expected' => [['test-bundle-fixed', 1.0, 50.0]],
            ],
            'giftcard' => [
                'visible' => [['sku' => 'test-giftcard', 'qty' => 1.0, 'price' => 25.0]],
                'expected' => [['test-giftcard', 1.0, 25.0]],
            ],
        ];
    }

    /**
     * @dataProvider orderShapeProvider
     */
    #[DataProvider('orderShapeProvider')]
    public function testOrderCartLinesForEachCatalogType(array $visible, array $expected): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getAllVisibleItems')->willReturn($this->buildOrderTree($visible));
        $order->method('getBaseShippingAmount')->willReturn(0.0);

        $cartItems = $this->builder->buildCartItemsFromOrder($order);

        $this->assertSame($expected, $this->linesOf($cartItems));
    }

    /**
     * A full refund must return exactly what the order authorized. The credit
     * memo lists parents and children alike, so this is where a composite gets
     * counted twice if the wrapper is not skipped.
     *
     * @dataProvider orderShapeProvider
     */
    #[DataProvider('orderShapeProvider')]
    public function testReturnCartLinesMatchTheOrderForEachCatalogType(array $visible, array $expected): void
    {
        $orderItems = $this->buildOrderTree($visible);

        // A credit memo item per order item, parents and children both.
        $creditItems = [];
        foreach ($orderItems as $orderItem) {
            foreach (array_merge([$orderItem], $orderItem->getChildrenItems()) as $item) {
                $credit = $this->createMock(CreditmemoItem::class);
                $credit->method('getOrderItem')->willReturn($item);
                $credit->method('getQty')->willReturn((float) $item->getQtyOrdered());
                $credit->method('getPrice')->willReturn((float) $item->getPrice());
                $credit->method('getDiscountAmount')->willReturn(0.0);
                $creditItems[] = $credit;
            }
        }

        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($this->createMock(Order::class));
        $creditmemo->method('getAllItems')->willReturn($creditItems);
        $creditmemo->method('getShippingAmount')->willReturn(0.0);

        $return = $this->builder->buildReturnCartItems($creditmemo);

        // No filtering here on purpose. A credit memo lists the $0 children of a
        // parent-priced composite too, and the builder is expected to drop them:
        // the Lookup never sent them, so returning them would name item IDs the
        // order never reported. An integration invariant caught that divergence
        // after this table was first written passing a filtered comparison.
        $this->assertSame($expected, $this->linesOf($return['cartItems']));
    }

    /**
     * The asymmetry, stated as an assertion: the same cart described from the
     * quote and from the order must name the same SKUs at the same quantities.
     * A quote child stores qty per parent and an order child stores it
     * absolutely, so exactly one of the two sides has to multiply — and this is
     * what fails if that ever gets applied to both or neither.
     */
    public function testQuoteAndOrderDescribeTheSameCart(): void
    {
        $quoteShapes = self::quoteShapeProvider();
        $orderShapes = self::orderShapeProvider();

        foreach ($quoteShapes as $label => $quoteShape) {
            $this->assertSame(
                $quoteShape['expected'],
                $orderShapes[$label]['expected'],
                "quote and order disagree about the lines for: $label"
            );
        }
    }

    /**
     * Reduce cart items to [sku, qty, price] for comparison.
     */
    private function linesOf(array $cartItems): array
    {
        return array_map(
            fn ($item) => [$item['ItemID'], (float) $item['Qty'], round((float) $item['Price'], 4)],
            array_values(array_filter($cartItems, fn ($item) => $item['ItemID'] !== 'shipping'))
        );
    }

    /**
     * Turn a declarative quote tree into wired mocks. Parents and children point
     * at each other, so both are resolved lazily against the finished map.
     */
    private function buildQuoteTree(array $declarations): array
    {
        $items = [];
        $childrenOf = [];
        foreach ($declarations as $code => $declaration) {
            if (!empty($declaration['parent'])) {
                $childrenOf[$declaration['parent']][] = $code;
            }
        }

        foreach ($declarations as $code => $declaration) {
            $product = $this->getMockBuilder(Dbl\ProductDouble::class)
                ->disableOriginalConstructor()->onlyMethods(['getTaxClassId'])->getMock();
            $product->method('getTaxClassId')->willReturn('2');

            $item = $this->getMockBuilder(Dbl\QuoteItemDouble::class)
                ->disableOriginalConstructor()
                ->onlyMethods([
                    'getProduct', 'getSku', 'getPrice', 'getDiscountAmount', 'getQty',
                    'getParentItem', 'getChildren', 'isChildrenCalculated',
                ])
                ->getMock();
            $item->method('getProduct')->willReturn($product);
            $item->method('getSku')->willReturn($declaration['sku']);
            $item->method('getPrice')->willReturn($declaration['price']);
            $item->method('getDiscountAmount')->willReturn($declaration['discount'] ?? 0.0);
            $item->method('getQty')->willReturn($declaration['qty']);
            $item->method('isChildrenCalculated')->willReturn($declaration['childrenCalculated'] ?? false);
            $item->method('getParentItem')->willReturnCallback(
                function () use (&$items, $declaration) {
                    return empty($declaration['parent']) ? null : $items[$declaration['parent']];
                }
            );
            $item->method('getChildren')->willReturnCallback(
                function () use (&$items, $childrenOf, $code) {
                    return array_map(fn ($child) => $items[$child], $childrenOf[$code] ?? []);
                }
            );

            $items[$code] = $item;
        }

        return $items;
    }

    /**
     * Turn a declarative order tree into mocks, children first so a parent can
     * hand them back.
     */
    private function buildOrderTree(array $declarations): array
    {
        $visible = [];
        foreach ($declarations as $declaration) {
            $children = [];
            foreach ($declaration['children'] ?? [] as $childDeclaration) {
                $children[] = $this->orderItem($childDeclaration, 1, $declaration['childrenCalculated'] ?? false);
            }
            $visible[] = $this->orderItem($declaration, null, $declaration['childrenCalculated'] ?? false, $children);
        }

        return $visible;
    }

    private function orderItem(array $declaration, $parentItemId, bool $childrenCalculated, array $children = [])
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getSku')->willReturn($declaration['sku']);
        $item->method('getQtyOrdered')->willReturn($declaration['qty']);
        $item->method('getPrice')->willReturn($declaration['price']);
        $item->method('getDiscountAmount')->willReturn($declaration['discount'] ?? 0.0);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->method('getChildrenItems')->willReturn($children);

        return $item;
    }
}
