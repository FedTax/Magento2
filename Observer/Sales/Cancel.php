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

namespace Taxcloud\Magento2\Observer\Sales;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use \Magento\Sales\Model\Order;

class Cancel implements ObserverInterface
{

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig = null;

    /**
     * TaxCloud Api Object
     *
     * @var \Taxcloud\Magento2\Model\Api
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Taxcloud\Magento2\Logger\Logger
     */
    protected $tclogger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Taxcloud\Magento2\Model\Api $tcapi
     * @param \Taxcloud\Magento2\Logger\Logger $tclogger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Taxcloud\Magento2\Model\Api $tcapi,
        \Taxcloud\Magento2\Logger\Logger $tclogger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->tcapi = $tcapi;

        if ($scopeConfig->getValue(
            'tax/taxcloud_settings/logging',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) {
            $this->tclogger = $tclogger;
        } else {
            $this->tclogger = new \Psr\Log\NullLogger();
        }
    }

    /**
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    ) {
        if (!$this->scopeConfig->getValue(
            'tax/taxcloud_settings/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) {
            return;
        }

        $eventName = $observer->getEvent()->getName();

        if ($eventName === 'order_cancel_after') {
            $order = $observer->getEvent()->getOrder();
            if ($order && $order->getId()) {
                $this->processOrderCancel($order);
            }
            return;
        }

        if ($eventName === 'sales_order_save_after') {
            $order = $observer->getEvent()->getOrder();
            if (!$order || !$order->getId()) {
                return;
            }
            $origState = $order->getOrigData('state');
            if ($origState !== Order::STATE_CANCELED && $order->getState() === Order::STATE_CANCELED) {
                $this->tclogger->info(
                    'TaxCloud Cancel: detected state transition to canceled via sales_order_save_after, order '
                    . $order->getIncrementId()
                );
                $this->processOrderCancel($order);
            }
        }
    }

    /**
     * If the order was captured in TaxCloud and has no invoices, call Returned to reverse the sale.
     *
     * @param Order $order
     */
    protected function processOrderCancel(Order $order)
    {
        if ($order->getState() !== Order::STATE_CANCELED) {
            $this->tclogger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId() . ' (state is not canceled)'
            );
            return;
        }

        if ($order->getInvoiceCollection()->getSize() > 0) {
            $this->tclogger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId() . ' (order has invoices, use refund flow)'
            );
            return;
        }

        $details = $this->tcapi->getOrderDetails($order);
        if (!$details || empty($details['CapturedDate'])) {
            $this->tclogger->info(
                'TaxCloud Cancel: skipping order ' . $order->getIncrementId()
                . ' (order was not captured in TaxCloud or OrderDetails unavailable)'
            );
            return;
        }

        $this->tclogger->info(
            'TaxCloud Cancel: calling Returned for canceled unpaid order ' . $order->getIncrementId()
        );

        if ($this->tcapi->returnOrderCancellation($order)) {
            $this->tclogger->info(
                'TaxCloud Cancel: Returned completed for order ' . $order->getIncrementId()
            );
        }
    }
}
