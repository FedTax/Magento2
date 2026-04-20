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

use Taxcloud\Magento2\Logger\Logger;

/**
 * Distributes adjustment-only refund amounts proportionally across the
 * remaining (unrefunded) items and shipping of an order, so TaxCloud's
 * Returned API receives meaningful cart items instead of an empty array
 * (which TaxCloud interprets as a full-order return).
 *
 * Approach mirrors the Shopify ETL refund pipeline: distribute the
 * adjustment as fractional quantities of remaining items, scaled by a
 * tax ratio so we don't over-refund tax.
 */
class RefundDistributor
{
    /** Caller should skip the TaxCloud Returned call entirely. */
    const ACTION_SKIP = 'skip';

    /** Caller should send empty cartItems so TaxCloud returns the remainder. */
    const ACTION_FULL_RETURN = 'full_return';

    /** Caller should send the returned cartItems to TaxCloud. */
    const ACTION_DISTRIBUTE = 'distribute';

    /** Below this threshold the adjustment is treated as floating-point noise. */
    const MIN_ADJUSTMENT = 0.01;

    /**
     * Penny tolerance for the full-return fallback. If the adjustment is within
     * this many dollars of the remaining order total, treat it as a full return.
     */
    const FULL_RETURN_TOLERANCE = 0.01;

    /** Decimal precision for fractional quantities sent to TaxCloud. */
    const QTY_PRECISION = 4;

    /**
     * @var ProductTicService
     */
    private $productTicService;

    /**
     * @var Logger
     */
    private $tclogger;

    /**
     * @param ProductTicService $productTicService
     * @param Logger $tclogger
     */
    public function __construct(
        ProductTicService $productTicService,
        Logger $tclogger
    ) {
        $this->productTicService = $productTicService;
        $this->tclogger = $tclogger;
    }

    /**
     * Decide what to send to TaxCloud's Returned API for an adjustment-only
     * credit memo (one with no line items and no shipping refunded).
     *
     * Returns an associative array:
     *   [
     *     'action'    => one of ACTION_SKIP | ACTION_FULL_RETURN | ACTION_DISTRIBUTE,
     *     'cartItems' => array of cart items (empty unless action is DISTRIBUTE),
     *     'reason'    => human-readable explanation (for logging),
     *   ]
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return array
     */
    public function distribute($creditmemo)
    {
        $order = $creditmemo->getOrder();
        $adjustment = (float) $creditmemo->getAdjustmentPositive()
            - (float) $creditmemo->getAdjustmentNegative();

        if ($adjustment < self::MIN_ADJUSTMENT) {
            return $this->result(
                self::ACTION_SKIP,
                [],
                'adjustment ' . $adjustment . ' is below minimum ' . self::MIN_ADJUSTMENT
            );
        }

        $remaining = $this->buildRemainingItems($order);
        $remainingTotal = $this->sumRemaining($remaining);

        if ($remainingTotal <= 0) {
            return $this->result(
                self::ACTION_SKIP,
                [],
                'no remaining items or shipping available to refund'
            );
        }

        // Full-return fallback: adjustment effectively covers the whole remainder.
        if ($adjustment >= ($remainingTotal - self::FULL_RETURN_TOLERANCE)) {
            return $this->result(
                self::ACTION_FULL_RETURN,
                [],
                'adjustment ' . $adjustment . ' covers remaining total ' . $remainingTotal
                . ' (within $' . self::FULL_RETURN_TOLERANCE . ')'
            );
        }

        $taxRatio = $this->computeTaxRatio($order);
        $adjustmentPercent = ($taxRatio * $adjustment) / $remainingTotal;

        $cartItems = [];
        $index = 0;
        foreach ($remaining as $entry) {
            $distributedQty = round($entry['qty'] * $adjustmentPercent, self::QTY_PRECISION);
            if ($distributedQty <= 0) {
                continue;
            }
            $cartItems[] = [
                'ItemID' => $entry['ItemID'],
                'Index'  => $index++,
                'TIC'    => $entry['TIC'],
                'Price'  => $entry['price'],
                'Qty'    => $distributedQty,
            ];
        }

        if (empty($cartItems)) {
            return $this->result(
                self::ACTION_SKIP,
                [],
                'distribution rounded all quantities to zero (adjustment too small relative to remaining total)'
            );
        }

        return $this->result(
            self::ACTION_DISTRIBUTE,
            $cartItems,
            'distributed ' . $adjustment . ' across ' . count($cartItems) . ' remaining item(s)'
            . ' using taxRatio=' . $taxRatio . ' and percent=' . $adjustmentPercent
        );
    }

    /**
     * Build the list of remaining (unrefunded) order items and shipping.
     *
     * Each entry: ['ItemID' => string, 'TIC' => string, 'price' => float, 'qty' => float]
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    private function buildRemainingItems($order)
    {
        $remaining = [];

        foreach ($order->getAllItems() as $item) {
            // Skip child rows of configurable/bundle items — qty is on the parent.
            if (method_exists($item, 'getParentItem') && $item->getParentItem()) {
                continue;
            }

            $qtyOrdered = (float) $item->getQtyOrdered();
            $qtyRefunded = (float) $item->getQtyRefunded();
            $remainingQty = $qtyOrdered - $qtyRefunded;
            if ($remainingQty <= 0) {
                continue;
            }

            $unitPrice = (float) $item->getPrice();
            $discountPerUnit = $qtyOrdered > 0
                ? ((float) $item->getDiscountAmount() / $qtyOrdered)
                : 0.0;
            $effectivePrice = $unitPrice - $discountPerUnit;

            if ($effectivePrice <= 0) {
                continue;
            }

            $remaining[] = [
                'ItemID' => $item->getSku(),
                'TIC'    => $this->productTicService->getProductTic($item, 'returnOrderDistribute'),
                'price'  => $effectivePrice,
                'qty'    => $remainingQty,
            ];
        }

        $shippingAmount = (float) $order->getShippingAmount();
        $shippingRefunded = (float) $order->getShippingRefunded();
        $remainingShipping = $shippingAmount - $shippingRefunded;
        if ($remainingShipping > 0) {
            $remaining[] = [
                'ItemID' => 'shipping',
                'TIC'    => $this->productTicService->getShippingTic(),
                'price'  => $remainingShipping,
                'qty'    => 1.0,
            ];
        }

        return $remaining;
    }

    /**
     * @param array $remaining
     * @return float
     */
    private function sumRemaining(array $remaining)
    {
        $total = 0.0;
        foreach ($remaining as $entry) {
            $total += $entry['price'] * $entry['qty'];
        }
        return $total;
    }

    /**
     * taxRatio = 1 - (tax / (tax + subtotalAfterDiscount))
     *
     * Mirrors the Shopify ETL formula. Without it, distributing a pre-tax
     * adjustment across pre-tax item prices would implicitly refund some tax.
     *
     * Uses the post-discount subtotal because tax is calculated on the
     * discounted amount; using the pre-discount subtotal would inflate the
     * ratio and over-distribute.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return float
     */
    private function computeTaxRatio($order)
    {
        $tax = (float) $order->getTaxAmount();
        // getDiscountAmount() is negative in Magento (e.g., -20.00 for a $20 coupon)
        $subtotal = (float) $order->getSubtotal() + (float) $order->getDiscountAmount();
        $denom = $tax + $subtotal;
        if ($denom <= 0) {
            return 1.0;
        }
        return 1.0 - ($tax / $denom);
    }

    /**
     * @param string $action
     * @param array  $cartItems
     * @param string $reason
     * @return array
     */
    private function result($action, array $cartItems, $reason)
    {
        return [
            'action'    => $action,
            'cartItems' => $cartItems,
            'reason'    => $reason,
        ];
    }
}
