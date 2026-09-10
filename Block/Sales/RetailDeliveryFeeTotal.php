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

namespace Taxcloud\Magento2\Block\Sales;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\AbstractBlock;

/**
 * Injects the Colorado Retail Delivery Fee row into sales totals blocks.
 *
 * One block for every totals surface — storefront and admin order view,
 * invoice and credit memo views, and the order/invoice/creditmemo emails —
 * because they all share Magento's totals-block contract: the parent calls
 * initTotals() and renders whatever was addTotal()'ed. The amount comes from
 * the document itself (getSource(): order, invoice, or creditmemo), so each
 * document shows what it actually settled or refunded.
 */
class RetailDeliveryFeeTotal extends AbstractBlock
{
    /**
     * Add the fee row to the parent totals block when the document carries it.
     *
     * @return $this
     */
    public function initTotals()
    {
        /** @var \Magento\Sales\Block\Order\Totals $parent */
        $parent = $this->getParentBlock();
        $source = $parent->getSource();

        if (!$source) {
            return $this;
        }

        $amount = (float) $source->getTaxcloudRdfAmount();
        if ($amount <= 0) {
            return $this;
        }

        $parent->addTotalBefore(
            new DataObject([
                'code' => 'taxcloud_rdf',
                'strong' => false,
                'label' => __('Colorado Retail Delivery Fee'),
                'value' => $amount,
                'base_value' => (float) $source->getBaseTaxcloudRdfAmount(),
            ]),
            'grand_total'
        );

        return $this;
    }
}
