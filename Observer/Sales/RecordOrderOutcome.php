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

namespace Taxcloud\Magento2\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\OrderRule\OutcomeRecorder;

/**
 * Records the order processing outcome as the quote becomes an order.
 *
 * On sales_model_service_quote_submit_before: the quote (with the request's
 * skip decision) and the numbered, not-yet-saved order are both in hand, so
 * the outcome and its explanatory comment are saved with the order itself.
 */
class RecordOrderOutcome implements ObserverInterface
{
    /**
     * @var OutcomeRecorder
     */
    private $recorder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OutcomeRecorder $recorder
     * @param LoggerInterface $logger
     */
    public function __construct(OutcomeRecorder $recorder, LoggerInterface $logger)
    {
        $this->recorder = $recorder;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $quote = $observer->getEvent()->getData('quote');
        if (!$order instanceof Order) {
            return;
        }

        try {
            $decision = $this->recorder->record($order, $quote instanceof Quote ? $quote : null);
        } catch (\Throwable $e) {
            // Never block order placement. The order is left without an
            // outcome; the placement-time fallback in Complete tries again.
            $this->logger->error('Could not record the TaxCloud order outcome: ' . $e->getMessage());
            return;
        }

        if ($decision !== null) {
            $this->logger->info(
                'Order ' . $order->getIncrementId() . ' TaxCloud outcome: ' . $decision->getAction()
                . ($decision->isStoreDefault() ? ' (store default)' : ' (rule "' . $decision->getRuleName() . '")')
            );
        }
    }
}
