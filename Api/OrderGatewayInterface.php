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

namespace Taxcloud\Magento2\Api;

/**
 * Gateway contract for order-lifecycle operations against the TaxCloud service
 * (authorize/capture, return, cancel-return and order-detail lookups).
 *
 * Kept transport-agnostic on purpose so the underlying transport (SOAP today,
 * REST tomorrow) can be swapped without touching the observers that drive the
 * order lifecycle.
 */
interface OrderGatewayInterface
{
    /**
     * Authorize and capture an order in TaxCloud in a single step.
     *
     * $completedAt is the creation time of the document that triggered the
     * capture — the order, the invoice or the shipment, per the store's capture
     * trigger — as a Magento datetime string in UTC. It is the date the sale is
     * filed under, so that a capture retried at a later fulfillment document
     * files under that document rather than under the retry's wall clock. Null
     * falls back to the current time; that is a real path on the order-creation
     * trigger, where the order is not yet persisted and carries no created_at.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string|null $completedAt Triggering document's created_at (UTC)
     * @return bool True on success (or a benign duplicate), false otherwise
     */
    public function authorizeCapture($order, $completedAt = null);

    /**
     * Return (refund) an order's items in TaxCloud from a credit memo.
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return bool True on success, false otherwise
     */
    public function returnOrder($creditmemo);

    /**
     * Fetch order details from TaxCloud (OrderDetails API).
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array|null OrderDetailsResult as an array, or null on failure / not found
     */
    public function getOrderDetails($order);

    /**
     * Return a canceled (uninvoiced) order in TaxCloud, reversing the capture.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool True on success, false otherwise
     */
    public function returnOrderCancellation($order);
}
