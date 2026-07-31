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
 * Aggregate gateway contract covering the full TaxCloud service surface.
 *
 * This is the seam the REST migration targets: a single implementation (SOAP
 * today) satisfies every operation, while individual call sites depend only on
 * the finer-grained interface they actually need
 * ({@see LookupGatewayInterface}, {@see OrderGatewayInterface},
 * {@see AddressGatewayInterface}, {@see ExemptionGatewayInterface}).
 */
interface GatewayInterface extends
    LookupGatewayInterface,
    OrderGatewayInterface,
    AddressGatewayInterface,
    ExemptionGatewayInterface
{
}
