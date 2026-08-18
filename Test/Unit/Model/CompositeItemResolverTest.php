<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\CompositeItemResolver;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * Covers the composite-product rule shared by the Lookup, return and cancel
 * payloads: which line carries the taxable basis, and on which scale its
 * quantity is stored.
 *
 * The scenario throughout is the one that surfaced the bug — a qty-2
 * dynamic-price bundle holding one $10 selection, where the quote stores the
 * child at qty 1 with a row total of $20.
 */
#[AllowMockObjectsWithoutExpectations]
class CompositeItemResolverTest extends TestCase
{
    /**
     * A quote item stubbed with only the hierarchy accessors the resolver reads.
     */
    private function quoteItem($qty, $parent = null, array $children = [], $childrenCalculated = false)
    {
        $item = $this->getMockBuilder(Dbl\QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQty', 'getParentItem', 'getChildren', 'isChildrenCalculated'])
            ->getMock();
        $item->method('getQty')->willReturn($qty);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getChildren')->willReturn($children);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);

        return $item;
    }

    private function orderItem($parentItemId, $childrenCalculated, array $children = [])
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->method('getChildrenItems')->willReturn($children);

        return $item;
    }

    public function testDynamicBundleParentIsPricedByChildren()
    {
        $parent = $this->quoteItem(2.0, null, [$this->quoteItem(1.0)], true);

        $this->assertTrue(CompositeItemResolver::isQuoteParentPricedByChildren($parent));
    }

    public function testConfigurableParentIsNotPricedByChildren()
    {
        // A configurable's children exist but are priced at 0 — the parent line
        // carries the basis, and Magento never maps the children into the tax
        // details at all.
        $parent = $this->quoteItem(2.0, null, [$this->quoteItem(1.0)], false);

        $this->assertFalse(CompositeItemResolver::isQuoteParentPricedByChildren($parent));
    }

    /**
     * Grouped, virtual, downloadable and gift card lines all reach the cart as
     * childless top-level items, so this is the shape that has to stay a cart
     * line of its own.
     */
    public function testChildlessItemIsNotPricedByChildren()
    {
        $this->assertFalse(CompositeItemResolver::isQuoteParentPricedByChildren($this->quoteItem(1.0)));
    }

    /**
     * The children test comes first for a reason: a childless line is never
     * suppressed, whatever it claims about child calculation. Only a bundle
     * carries the price type behind that claim today, but a bundle whose
     * children failed to load would otherwise be dropped from the request and
     * silently untaxed — its own price is the sum of those children, so sending
     * it reports the same basis.
     */
    public function testChildlessItemSurvivesEvenWhenItClaimsChildCalculation()
    {
        $item = $this->quoteItem(2.0, null, [], true);

        $this->assertFalse(CompositeItemResolver::isQuoteParentPricedByChildren($item));
    }

    public function testChildIsNeverItsOwnPricedByChildrenParent()
    {
        $parent = $this->quoteItem(2.0, null, [], true);
        $child = $this->quoteItem(1.0, $parent, [], true);

        $this->assertFalse(CompositeItemResolver::isQuoteParentPricedByChildren($child));
    }

    public function testQuoteQtyMultipliesChildByParentQty()
    {
        $parent = $this->quoteItem(2.0);
        $child = $this->quoteItem(1.0, $parent);

        // The regression itself: 1 stored on the child, 2 units actually sold.
        $this->assertSame(2.0, CompositeItemResolver::quoteQty($child));
    }

    public function testQuoteQtyMultipliesMultiUnitSelections()
    {
        $parent = $this->quoteItem(3.0);
        $child = $this->quoteItem(2.0, $parent);

        $this->assertSame(6.0, CompositeItemResolver::quoteQty($child));
    }

    /**
     * A grouped product explodes into one independent line per associated
     * product, each with its own absolute qty — nothing to multiply.
     */
    public function testQuoteQtyLeavesTopLevelItemAlone()
    {
        $this->assertSame(2.0, CompositeItemResolver::quoteQty($this->quoteItem(2.0)));
    }

    public function testQuoteQtyCoercesStringQuantities()
    {
        // Quote item qty arrives as a decimal string off the DB row.
        $parent = $this->quoteItem('2.0000');
        $child = $this->quoteItem('1.0000', $parent);

        $this->assertSame(2.0, CompositeItemResolver::quoteQty($child));
    }

    public function testOrderDynamicBundleParentIsPricedByChildren()
    {
        $this->assertTrue(CompositeItemResolver::isOrderParentPricedByChildren($this->orderItem(null, true)));
    }

    public function testOrderChildIsNotAPricedByChildrenParent()
    {
        // A bundle child answers isChildrenCalculated() from its parent's
        // options, so the parent-id check is what keeps it out.
        $this->assertFalse(CompositeItemResolver::isOrderParentPricedByChildren($this->orderItem(54, true)));
    }

    public function testOrderConfigurableParentIsNotPricedByChildren()
    {
        $this->assertFalse(CompositeItemResolver::isOrderParentPricedByChildren($this->orderItem(null, false)));
    }

    public function testOrderBasisItemsExpandsDynamicBundleToChildren()
    {
        $childA = $this->orderItem(54, true);
        $childB = $this->orderItem(54, true);
        $parent = $this->orderItem(null, true, [7 => $childA, 8 => $childB]);

        // Keyed by item id in Magento; the caller indexes positionally.
        $this->assertSame([$childA, $childB], CompositeItemResolver::orderBasisItems($parent));
    }

    public function testOrderBasisItemsLeavesOrdinaryItemAlone()
    {
        $item = $this->orderItem(null, false);

        $this->assertSame([$item], CompositeItemResolver::orderBasisItems($item));
    }

    public function testOrderBasisItemsFallsBackToParentWhenChildrenAreNotLinked()
    {
        // Over-reporting a line is recoverable; dropping it silently is not.
        $parent = $this->orderItem(null, true, []);

        $this->assertSame([$parent], CompositeItemResolver::orderBasisItems($parent));
    }
}
