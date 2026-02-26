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
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;

class Complete implements ObserverInterface
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

        if ($scopeConfig->getValue('tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->tclogger = $tclogger;
        } else {
            $this->tclogger = new class {
                public function info()
                {
                }
            };
        }
    }

    /**
     * Event names that correspond to each capture trigger option.
     */
    private static $triggerToEvent = [
        CaptureTrigger::ORDER_CREATION => 'sales_order_place_after',
        CaptureTrigger::PAYMENT => 'sales_order_invoice_pay',
        CaptureTrigger::SHIPMENT => 'sales_order_shipment_save_after',
    ];

    /**
     * Run only when the current event matches the configured "Capture in TaxCloud" setting.
     *
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
        $configuredTrigger = $this->scopeConfig->getValue(
            'tax/taxcloud_settings/capture_trigger',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if ($configuredTrigger === null || $configuredTrigger === '') {
            $configuredTrigger = CaptureTrigger::ORDER_CREATION;
        }

        $expectedEvent = isset(self::$triggerToEvent[$configuredTrigger])
            ? self::$triggerToEvent[$configuredTrigger]
            : self::$triggerToEvent[CaptureTrigger::ORDER_CREATION];

        if ($eventName !== $expectedEvent) {
            return;
        }

        $this->tclogger->info('Running Observer ' . $eventName . ' (capture trigger: ' . $configuredTrigger . ')');

        $order = $this->getOrderFromObserver($observer, $eventName);
        if (!$order) {
            return;
        }

        $this->tcapi->authorizeCapture($order);
    }

    /**
     * Get order from observer based on event. Returns null if we should skip (e.g. not first invoice/shipment).
     *
     * @param Observer $observer
     * @param string $eventName
     * @return \Magento\Sales\Model\Order|null
     */
    private function getOrderFromObserver(Observer $observer, $eventName)
    {
        $event = $observer->getEvent();

        if ($eventName === 'sales_order_place_after') {
            return $event->getOrder();
        }

        if ($eventName === 'sales_order_invoice_pay') {
            $order = $event->getInvoice()->getOrder();
            if ($order->getInvoiceCollection()->getSize() > 1) {
                return null;
            }
            return $order;
        }

        if ($eventName === 'sales_order_shipment_save_after') {
            $order = $event->getShipment()->getOrder();
            if ($order->getShipmentCollection()->getSize() > 1) {
                return null;
            }
            return $order;
        }

        return null;
    }
}
