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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Model\RetailDeliveryFee\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

/**
 * Refunds the Colorado Retail Delivery Fee on full returns only.
 *
 * Colorado's rule: the fee attaches to the delivery, not the items, so a
 * partial return (delivery happened, some goods kept) does not refund it.
 * The memo's persisted fee amount is the single decision the refund path
 * reads afterwards — a memo carrying the fee reverses it in TaxCloud, a memo
 * without it leaves the fee filed.
 *
 * "Full return" covers two shapes:
 *  - a memo whose items exhaust every remaining unrefunded unit, and
 *  - an item-less (adjustment-style) memo on an order whose items are
 *    already fully refunded — the trailing memo completing the return.
 */
class RetailDeliveryFee extends AbstractTotal
{
    /**
     * @param Creditmemo $creditmemo
     * @return $this
     */
    public function collect(Creditmemo $creditmemo)
    {
        $order = $creditmemo->getOrder();
        $amount = (float) $order->getTaxcloudRdfAmount();
        $baseAmount = (float) $order->getBaseTaxcloudRdfAmount();

        if ($amount <= 0 || $this->feeAlreadyRefunded($creditmemo)) {
            return $this;
        }

        if (!$this->isFullReturn($creditmemo)) {
            return $this;
        }

        $creditmemo->setTaxcloudRdfAmount($amount);
        $creditmemo->setBaseTaxcloudRdfAmount($baseAmount);
        $creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $amount);
        $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $baseAmount);

        return $this;
    }

    /**
     * Whether an earlier credit memo already carried the fee back.
     *
     * @param Creditmemo $creditmemo
     * @return bool
     */
    private function feeAlreadyRefunded(Creditmemo $creditmemo): bool
    {
        foreach ($creditmemo->getOrder()->getCreditmemosCollection() as $previous) {
            if ($previous->getId()
                && $previous->getId() !== $creditmemo->getId()
                && (float) $previous->getTaxcloudRdfAmount() > 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this memo completes the return of every ordered unit.
     *
     * Order items' qty_refunded is not yet updated for the memo being
     * collected, so the memo's own quantities count toward the remainder.
     * An item-less memo qualifies only when nothing remains unrefunded
     * before it.
     *
     * @param Creditmemo $creditmemo
     * @return bool
     */
    private function isFullReturn(Creditmemo $creditmemo): bool
    {
        $memoQtyByOrderItem = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItemId = $item->getOrderItemId();
            $memoQtyByOrderItem[$orderItemId] = ($memoQtyByOrderItem[$orderItemId] ?? 0) + (float) $item->getQty();
        }

        foreach ($creditmemo->getOrder()->getAllItems() as $orderItem) {
            $remaining = (float) $orderItem->getQtyOrdered()
                - (float) $orderItem->getQtyRefunded()
                - ($memoQtyByOrderItem[$orderItem->getId()] ?? 0);
            if ($remaining > 0.0001) {
                return false;
            }
        }

        return true;
    }
}
