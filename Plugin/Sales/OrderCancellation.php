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

namespace Taxcloud\Magento2\Plugin\Sales;

use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Model\Order\CancellationProcessor;

/**
 * Reverses the TaxCloud sale when an order is cancelled.
 *
 * registerCancellation() is the single point every cancellation passes through:
 * Order::cancel() calls it before dispatching order_cancel_after, and the
 * payment paths that cancel without going through cancel() — PayPal IPN,
 * Payflow Link, Express Checkout, and a denied or voided payment — call it
 * directly. Intercepting here therefore covers admin, API, and programmatic
 * cancellation with one plugin on one method.
 *
 * It is an "after" plugin because the work is a reaction, not a decision: the
 * sale is reversed once Magento has settled the order into the canceled state.
 * The processor re-checks that state, since registerCancellation() is a no-op
 * for an order that cannot be cancelled.
 */
class OrderCancellation
{
    /**
     * @var CancellationProcessor
     */
    private $processor;

    /**
     * @param CancellationProcessor $processor
     */
    public function __construct(CancellationProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * @param Order $subject
     * @param Order $result
     * @return Order
     */
    public function afterRegisterCancellation(Order $subject, $result)
    {
        $this->processor->process($subject);

        return $result;
    }
}
