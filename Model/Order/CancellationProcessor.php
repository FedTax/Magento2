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

namespace Taxcloud\Magento2\Model\Order;

use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Api\OrderGatewayInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Reverses a cancelled order's sale in TaxCloud.
 *
 * A cancellation only reaches TaxCloud when the order is genuinely canceled,
 * has no invoices (an invoiced order reverses through the refund flow instead),
 * and TaxCloud reports it as captured. Anything else is a no-op.
 */
class CancellationProcessor
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var OrderGatewayInterface
     */
    private $gateway;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Orders already reversed by this instance.
     *
     * registerCancellation() can be reached more than once for the same order in
     * a single request; the sale must only be reversed once.
     *
     * @var int[]
     */
    private $processedOrderIds = [];

    /**
     * @param TaxcloudConfig        $config
     * @param OrderGatewayInterface $gateway
     * @param LoggerInterface|null  $logger
     */
    public function __construct(
        TaxcloudConfig $config,
        OrderGatewayInterface $gateway,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->gateway = $gateway;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Reverse the sale in TaxCloud when the order qualifies.
     *
     * @param Order $order
     * @return void
     */
    public function process(Order $order)
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$order->getId()) {
            return;
        }

        if (in_array($order->getId(), $this->processedOrderIds, true)) {
            $this->logger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId()
                . ' (already processed in this request)'
            );
            return;
        }

        if ($order->getState() !== Order::STATE_CANCELED) {
            $this->logger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId() . ' (state is not canceled)'
            );
            return;
        }

        if ($order->getInvoiceCollection()->getSize() > 0) {
            $this->logger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId() . ' (order has invoices, use refund flow)'
            );
            return;
        }

        $details = $this->gateway->getOrderDetails($order);
        if (!$details || empty($details['CapturedDate'])) {
            $this->logger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId()
                . ' (order was not captured in TaxCloud or OrderDetails unavailable)'
            );
            return;
        }

        $this->logger->info(
            'TaxCloud Cancel: calling Returned for canceled unpaid order ' . $order->getIncrementId()
        );

        $this->processedOrderIds[] = $order->getId();

        if ($this->gateway->returnOrderCancellation($order)) {
            $this->logger->info(
                'TaxCloud Cancel: Returned completed for order ' . $order->getIncrementId()
            );
        }
    }
}
