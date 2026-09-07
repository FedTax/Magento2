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

namespace Taxcloud\Magento2\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Copies the charged Colorado Retail Delivery Fee from the quote onto the
 * order as it is placed.
 *
 * A fieldset (sales_convert_quote_address → to_order) cannot carry it:
 * core's Quote\Address\ToOrder::convert() funnels the fieldset through
 * populateWithArray($order, …, OrderInterface::class), which keeps only keys
 * the order INTERFACE declares and silently drops a plain custom column. This
 * event fires with both quote and order in hand, just before conversion, and
 * writing the column directly on the order survives to the database.
 *
 * The amount is read from the address that bears it — shipping for a physical
 * order (the only kind the fee applies to) — so the order stores exactly what
 * was charged, which capture and refunds then read instead of re-consulting
 * config (the rate moves every July 1).
 */
class PersistRetailDeliveryFee implements ObserverInterface
{
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $quote = $observer->getEvent()->getData('quote');
        if (!$order || !$quote) {
            return;
        }

        $address = $quote->getShippingAddress() ?: $quote->getBillingAddress();
        if (!$address) {
            return;
        }

        $amount = (float) $address->getTaxcloudRdfAmount();
        if ($amount <= 0) {
            return;
        }

        $order->setData('taxcloud_rdf_amount', $amount);
        $order->setData('base_taxcloud_rdf_amount', (float) $address->getBaseTaxcloudRdfAmount());
    }
}
