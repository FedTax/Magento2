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

namespace Taxcloud\Magento2\Model\Gateway\Soap;

/**
 * Narrow transport seam: provisions the configured SOAP client the gateway
 * talks to.
 *
 * Isolating client construction (WSDL, timeouts, connection options) behind a
 * one-method contract is what a non-SOAP transport would replace; the calling
 * code only needs "give me something to call, or null if unavailable".
 */
interface SoapClientProviderInterface
{
    /**
     * Return the shared SOAP client, or null when one cannot be constructed
     * (e.g. the WSDL fetch failed). Callers must treat null as "transport
     * unavailable" and fall back accordingly.
     *
     * @return \SoapClient|null
     */
    public function getClient();
}
