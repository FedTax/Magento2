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
 * Gateway contract for tax-rate lookups against the TaxCloud service.
 *
 * Kept transport-agnostic on purpose: implementations may talk SOAP, REST, or
 * anything else. Consumers depend on this contract rather than the concrete
 * gateway so the transport can be swapped without touching call sites.
 */
interface LookupGatewayInterface
{
    /**
     * Look up tax for a quote's items and shipping.
     *
     * @param array $itemsByType Tax details grouped by item type (product/shipping)
     * @param \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment
     * @param \Magento\Quote\Model\Quote $quote
     * @return array Tax amounts keyed by item type
     */
    public function lookupTaxes($itemsByType, $shippingAssignment, $quote);
}
