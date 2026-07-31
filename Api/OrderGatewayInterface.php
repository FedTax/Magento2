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
     * @param \Magento\Sales\Model\Order $order
     * @return bool True on success (or a benign duplicate), false otherwise
     */
    public function authorizeCapture($order);

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
