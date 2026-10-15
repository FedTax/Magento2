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

namespace Taxcloud\Magento2\Model\OrderRule;

use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Reads the properties rules match on from a quote or a placed order.
 */
class SubjectReader
{
    /**
     * A quote as it stands now. Payment and shipping method are null until the
     * customer has chosen them; the order number is always null.
     *
     * @param Quote $quote
     * @return MatchSubject
     */
    public function fromQuote(Quote $quote): MatchSubject
    {
        $payment = $quote->getPayment();
        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();

        return new MatchSubject(
            (int) $quote->getStoreId(),
            $this->groupId($quote->getCustomerGroupId()),
            $payment ? (string) $payment->getMethod() : null,
            $shipping ? (string) $shipping->getShippingMethod() : null,
            null
        );
    }

    /**
     * @param Order $order
     * @return MatchSubject
     */
    public function fromOrder(Order $order): MatchSubject
    {
        $payment = $order->getPayment();

        return new MatchSubject(
            (int) $order->getStoreId(),
            $this->groupId($order->getCustomerGroupId()),
            $payment ? (string) $payment->getMethod() : null,
            $order->getIsVirtual() ? null : (string) $order->getShippingMethod(),
            (string) $order->getIncrementId()
        );
    }

    /**
     * Guests are group 0, which must stay distinguishable from "unknown".
     *
     * @param mixed $groupId
     * @return int|null
     */
    private function groupId($groupId): ?int
    {
        return $groupId === null || $groupId === '' ? null : (int) $groupId;
    }
}
