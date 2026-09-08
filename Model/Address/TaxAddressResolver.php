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

namespace Taxcloud\Magento2\Model\Address;

/**
 * Answers one question, in one place: which address is a sale sourced to.
 *
 * A sale is sourced to where it is delivered whenever that is known, and to
 * the buyer's billing address when nothing is delivered — the floor of the
 * sourcing hierarchy is the seller's own origin, never "no tax" and never "not
 * filed". A cart that ships nothing has no delivery address to know.
 *
 * The two methods look asymmetric, and are, because Magento is:
 *
 * - An ORDER may genuinely have no shipping address. Quote\Address\ToOrder
 *   converts one only for a non-virtual quote (QuoteManagement::submitQuote),
 *   so every order placed from a wholly virtual cart returns false from
 *   getShippingAddress(). Hence a fallback.
 * - A QUOTE always has both. Quote::_getAddressByType() lazily creates and
 *   attaches an empty address of whichever type is asked for, so a `?:`
 *   fallback on a quote address is dead code — the empty shipping address it
 *   would skip is truthy. The real question for a quote is which address
 *   Magento assigned the items to, and Quote\Address::getAllItems() answers it
 *   with exactly the isVirtual() test repeated below. Asking the same question
 *   the same way is what keeps the destination we report aligned with the
 *   address the totals were collected on.
 *
 * Deliberately dependency-free: no logger, no config. Callers that need to
 * report which address they got, or that no usable one existed, say so
 * themselves — the choice is cheap and made on hot paths, and the reporting
 * belongs with the validation that can reject it anyway.
 */
class TaxAddressResolver
{
    /**
     * The address an order's sale is sourced to.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|\Magento\Framework\DataObject $order
     * @return \Magento\Sales\Api\Data\OrderAddressInterface|\Magento\Framework\DataObject|null
     */
    public function forOrder($order)
    {
        if (!$order) {
            return null;
        }

        return $order->getShippingAddress() ?: ($order->getBillingAddress() ?: null);
    }

    /**
     * The address a quote's sale is sourced to — the one Magento put the items
     * on, which is the one its totals were collected against.
     *
     * @param \Magento\Quote\Model\Quote $quote Concrete quote: isVirtual() and
     *        the address accessors live there, not on CartInterface
     * @return \Magento\Quote\Model\Quote\Address|null
     */
    public function forQuote($quote)
    {
        if (!$quote) {
            return null;
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        return $address ?: null;
    }
}
