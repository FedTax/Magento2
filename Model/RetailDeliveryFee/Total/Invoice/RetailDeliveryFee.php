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

namespace Taxcloud\Magento2\Model\RetailDeliveryFee\Total\Invoice;

use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

/**
 * Settles the Colorado Retail Delivery Fee on the first invoice.
 *
 * The fee is per delivery, not per item, so it cannot be prorated across
 * partial invoices: the first invoice carries it whole (matching the module's
 * whole-order capture rule), and any later invoice contributes nothing.
 */
class RetailDeliveryFee extends AbstractTotal
{
    /**
     * @param Invoice $invoice
     * @return $this
     */
    public function collect(Invoice $invoice)
    {
        $order = $invoice->getOrder();
        $amount = (float) $order->getTaxcloudRdfAmount();
        $baseAmount = (float) $order->getBaseTaxcloudRdfAmount();

        if ($amount <= 0) {
            return $this;
        }

        // Already settled by an earlier invoice.
        foreach ($order->getInvoiceCollection() as $previous) {
            if ($previous->getId()
                && $previous->getId() !== $invoice->getId()
                && (float) $previous->getTaxcloudRdfAmount() > 0
            ) {
                return $this;
            }
        }

        $invoice->setTaxcloudRdfAmount($amount);
        $invoice->setBaseTaxcloudRdfAmount($baseAmount);
        $invoice->setGrandTotal($invoice->getGrandTotal() + $amount);
        $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $baseAmount);

        return $this;
    }
}
