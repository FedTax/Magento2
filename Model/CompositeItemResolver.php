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

namespace Taxcloud\Magento2\Model;

/**
 * Decides how a composite product (bundle, configurable, grouped) becomes
 * TaxCloud cart lines.
 *
 * Magento splits a composite line one of two ways, chosen by the product's
 * price type:
 *
 *  - CALCULATE_CHILD — a *dynamic-price* bundle. The children carry the price
 *    and the whole taxable basis; the parent is a wrapper that Magento
 *    deliberately leaves out of the address totals (see the "avoid double
 *    counting" branch in CommonTaxCollector::processProductItems). Sending
 *    both the parent and its children reports that basis to TaxCloud twice.
 *  - CALCULATE_PARENT — a configurable, or a fixed-price bundle. The parent
 *    carries the price and the children are priced at 0. Only the parent is
 *    mapped into the tax details, so nothing here applies.
 *
 * A dynamic-price bundle is the only thing that reaches the first case: the
 * price type behind it is a bundle-only attribute, and configurables are the
 * only other type that records a calculation mode, always as CALCULATE_PARENT.
 * Grouped products explode into one independent line per associated product,
 * and virtual, downloadable and gift card products are not composite at all —
 * all of them arrive as ordinary childless lines carrying their own price and
 * their own absolute quantity. Any of them can still be a *selection* inside a
 * dynamic bundle, which is why the rule keys on the bundle and not on the type
 * of what it holds.
 *
 * The trap this class exists to contain is that the two halves of the order
 * lifecycle store a child's quantity on *different scales*:
 *
 *  - a QUOTE child stores qty per parent — one unit of a selection inside a
 *    qty-2 bundle is stored as 1 — while its row total is already
 *    parent-multiplied ($20 for a $10 selection). Magento reconciles the two
 *    in TaxCalculation::getTotalQuantity(); anything sending a quote child to
 *    a tax service has to do the same, or it prices one unit and bills for two.
 *  - an ORDER or credit-memo child stores the absolute qty — that same line is
 *    stored as qty_ordered 2.
 *
 * So: multiply on the quote side, never on the order side.
 */
class CompositeItemResolver
{
    /**
     * True when this quote item's children carry the taxable basis, which makes
     * the item itself a wrapper that must not become a cart line of its own.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @return bool
     */
    public static function isQuoteParentPricedByChildren($item)
    {
        if (!$item || $item->getParentItem()) {
            return false;
        }

        $children = $item->getChildren();

        // isChildrenCalculated() is the same test Magento's own mapItems() uses
        // to decide whether to map the children as separate tax-detail rows, so
        // a parent that passes it always has its children in the details too.
        return !empty($children) && $item->isChildrenCalculated();
    }

    /**
     * The quantity a quote item actually represents: for a child of a
     * dynamic-price bundle that is its per-parent qty times the parent's.
     *
     * Mirrors \Magento\Tax\Model\TaxCalculation::getTotalQuantity(), which is
     * what produced the row total this quantity has to agree with.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @return float
     */
    public static function quoteQty($item)
    {
        $qty = (float) $item->getQty();
        $parent = $item->getParentItem();

        return $parent ? $qty * (float) $parent->getQty() : $qty;
    }

    /**
     * Order-side counterpart of isQuoteParentPricedByChildren(): true when this
     * order item is a dynamic-price bundle whose children carry the basis.
     *
     * Reads product_options['product_calculations'], persisted on the parent at
     * order placement, so it stays correct for an order loaded years later —
     * long after the product's price type may have been edited in the catalog.
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @return bool
     */
    public static function isOrderParentPricedByChildren($item)
    {
        if (!$item || $item->getParentItemId()) {
            return false;
        }

        return (bool) $item->isChildrenCalculated();
    }

    /**
     * True when this order item is a child whose parent carries the price — a
     * configurable's simple, or a fixed-price bundle's selection.
     *
     * Such a child is priced at 0 and never becomes a cart line: the Lookup
     * derives its lines from the visible items, so sending the child on a refund
     * would return an item ID the order never reported selling. It costs nothing
     * in tax, being worth nothing, but it makes the three payloads describe the
     * same order differently — and that divergence is what hid the bundle defect.
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @return bool
     */
    public static function isOrderChildWithoutBasis($item)
    {
        return $item && $item->getParentItemId() && !$item->isChildrenCalculated();
    }

    /**
     * The order items that carry the taxable basis for a top-level order item:
     * the item itself, or the children of a dynamic-price bundle.
     *
     * Falls back to the item itself when such a bundle has no children linked —
     * an over-report is recoverable, a silently untaxed line is not.
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @return \Magento\Sales\Model\Order\Item[]
     */
    public static function orderBasisItems($item)
    {
        if (!self::isOrderParentPricedByChildren($item)) {
            return [$item];
        }

        $children = $item->getChildrenItems();

        return empty($children) ? [$item] : array_values($children);
    }
}
