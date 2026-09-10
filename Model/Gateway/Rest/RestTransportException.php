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

namespace Taxcloud\Magento2\Model\Gateway\Rest;

/**
 * A v3 REST call failed before an HTTP response was received (DNS, connect,
 * TLS, timeout). The message is already scrubbed of credential values, so it
 * is safe for logs. Distinct from HTTP-level failures, which are returned as
 * a {@see RestResponse} — this distinction is what the retry policy's
 * non-idempotent rule keys on: only a request that provably never reached
 * TaxCloud may be retried for orders and refunds.
 */
class RestTransportException extends \RuntimeException
{
}
