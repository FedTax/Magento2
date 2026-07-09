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
 * Gateway contract for address verification against the TaxCloud service.
 *
 * Kept transport-agnostic on purpose so the underlying transport can be swapped
 * without touching the address-verification observer.
 */
interface AddressGatewayInterface
{
    /**
     * Verify and normalize a destination address.
     *
     * @param array $address Address parts (Address1, Address2, City, State, Zip5, Zip4)
     * @return array|bool Normalized address on success, false on failure
     */
    public function verifyAddress($address);
}
