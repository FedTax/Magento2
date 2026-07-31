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
 * Gateway contract for exemption-certificate validation against the TaxCloud
 * service.
 *
 * Kept transport-agnostic on purpose so the underlying transport can be swapped
 * without changing how exemptions are resolved during a lookup.
 */
interface ExemptionGatewayInterface
{
    /**
     * Return the certificate ID only when the certificate covers the given
     * destination state, otherwise null.
     *
     * @param string $certificateID
     * @param string $customerID
     * @param string $destinationState Two-letter state abbreviation
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose TaxCloud account applies
     * @return string|null The certificate ID if it covers the state, null otherwise
     */
    public function getValidatedCertificateID($certificateID, $customerID, $destinationState, $store = null);
}
