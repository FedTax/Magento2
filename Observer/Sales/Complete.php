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
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

class Complete implements ObserverInterface
{
    /** @var string Magento event: order placed */
    public const EVENT_ORDER_PLACE_AFTER = 'sales_order_place_after';

    /** @var string Magento event: invoice paid */
    public const EVENT_INVOICE_PAY = 'sales_order_invoice_pay';

    /** @var string Magento event: shipment saved */
    public const EVENT_SHIPMENT_SAVE_AFTER = 'sales_order_shipment_save_after';

    /**
     * TaxCloud store-scoped configuration reader
     *
     * @var TaxcloudConfig
     */
    protected $config;

    /**
     * TaxCloud order-lifecycle gateway
     *
     * @var \Taxcloud\Magento2\Api\OrderGatewayInterface
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $tclogger;

    /**
     * Sales order resource, used to persist the taxcloud_captured flag without
     * re-dispatching the full order save.
     *
     * @var \Magento\Sales\Model\ResourceModel\Order
     */
    protected $orderResource;

    /**
     * @param TaxcloudConfig $config
     * @param \Taxcloud\Magento2\Api\OrderGatewayInterface $tcapi
     * @param \Psr\Log\LoggerInterface $tclogger Config-gated proxy, bound in di.xml
     * @param \Magento\Sales\Model\ResourceModel\Order $orderResource
     */
    public function __construct(
        TaxcloudConfig $config,
        \Taxcloud\Magento2\Api\OrderGatewayInterface $tcapi,
        \Psr\Log\LoggerInterface $tclogger,
        \Magento\Sales\Model\ResourceModel\Order $orderResource
    ) {
        $this->config = $config;
        $this->tcapi = $tcapi;
        $this->orderResource = $orderResource;

        $this->tclogger = $tclogger;
    }

    /**
     * Event names that correspond to each capture trigger option.
     *
     * @var array
     */
    private static $triggerToEvent = [
        CaptureTrigger::ORDER_CREATION => self::EVENT_ORDER_PLACE_AFTER,
        CaptureTrigger::PAYMENT => self::EVENT_INVOICE_PAY,
        CaptureTrigger::SHIPMENT => self::EVENT_SHIPMENT_SAVE_AFTER,
    ];

    /**
     * Run only when the current event matches the configured "Capture in TaxCloud" setting.
     *
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    ) {
        // Resolve the order FIRST: enabled and capture_trigger must be read
        // against the ORDER's store, not the ambient request store. This
        // observer runs in admin/cron/webhook contexts where the ambient store
        // is the default store view — for a store that disables TaxCloud (or
        // configures a different trigger) at store scope, the default's values
        // would otherwise silently apply.
        $eventName = $observer->getEvent()->getName();
        $order = $this->getOrderFromObserver($observer, $eventName);
        if (!$order) {
            return;
        }

        $storeId = $order->getStoreId();
        if ($this->tclogger instanceof GatewayLogger) {
            $this->tclogger->setStore($storeId);
        }

        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $configuredTrigger = $this->config->getCaptureTrigger($storeId);

        $expectedEvent = isset(self::$triggerToEvent[$configuredTrigger])
            ? self::$triggerToEvent[$configuredTrigger]
            : self::$triggerToEvent[CaptureTrigger::ORDER_CREATION];

        if ($eventName !== $expectedEvent) {
            return;
        }

        // Calculation-only stores never push the sale to TaxCloud — another
        // system owns that side of the integration. Skipping here also leaves
        // taxcloud_captured unset, which keeps the cancel flow a no-op for
        // these orders. Gated after the trigger check so this logs once per
        // order rather than once per lifecycle event.
        if ($this->config->isCalculationsOnly($storeId)) {
            $this->tclogger->info(
                'Skipping authorizeCapture for order ' . $order->getIncrementId() . ' (calculations-only mode)'
            );
            return;
        }

        // Already reported — don't send the sale again.
        //
        // AuthorizedWithCapture carries no amounts or items: it just flips the
        // whole cart recorded by the checkout Lookup to captured. So a repeat
        // call cannot capture "the rest" of anything, and TaxCloud answers it
        // with "This transaction has already been marked as authorized" (which
        // Api::authorizeCapture maps back to success). Nothing is mis-filed, but
        // the round-trip is pure waste and the warning it leaves in the log
        // reads like a fault.
        //
        // The count-based dedupe above cannot cover this on its own:
        // sales_order_invoice_pay is dispatched from Invoice::register() before
        // the invoice is written, so the collection is always one short, and a
        // store that changes capture_trigger mid-lifecycle can make a later
        // event newly eligible for an order captured under the old setting.
        if ($order->getData('taxcloud_captured')) {
            $this->tclogger->info(
                'Skipping authorizeCapture for order ' . $order->getIncrementId()
                . ' (already captured in TaxCloud)'
            );
            return;
        }

        $this->tclogger->info('Running Observer ' . $eventName . ' (capture trigger: ' . $configuredTrigger . ')');

        if ($this->tcapi->authorizeCapture($order)) {
            $this->markCapturedInTaxcloud($order);
        }
    }

    /**
     * Record on the order that it was captured in TaxCloud. The cancel flow reads this
     * flag instead of the license-gated OrderDetails API. saveAttribute persists the
     * single column without re-dispatching the full order save.
     *
     * @param \Magento\Sales\Model\Order $order
     */
    private function markCapturedInTaxcloud($order)
    {
        try {
            $order->setData('taxcloud_captured', 1);
            $this->orderResource->saveAttribute($order, 'taxcloud_captured');
        } catch (\Throwable $e) {
            // Non-fatal: capture already succeeded in TaxCloud. Cancel can still fall
            // back to OrderDetails if the flag was not persisted.
            $this->tclogger->warning(
                'TaxCloud: could not persist taxcloud_captured for order '
                . $order->getIncrementId() . ': ' . $e->getMessage()
            );
        }
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

        if ($eventName === self::EVENT_ORDER_PLACE_AFTER) {
            return $event->getOrder();
        }

        if ($eventName === self::EVENT_INVOICE_PAY) {
            $order = $event->getInvoice()->getOrder();
            if ($order->getInvoiceCollection()->getSize() > 1) {
                return null;
            }
            return $order;
        }

        if ($eventName === self::EVENT_SHIPMENT_SAVE_AFTER) {
            $order = $event->getShipment()->getOrder();
            if ($order->getShipmentsCollection()->getSize() > 1) {
                return null;
            }
            return $order;
        }

        return null;
    }
}
