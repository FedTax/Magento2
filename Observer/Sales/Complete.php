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
        // these orders. Gated after the trigger check, so it logs once per
        // matching lifecycle event: an order fulfilled in parts logs once per
        // document, which is the same shape as the retry path below.
        if ($this->config->isCalculationsOnly($storeId)) {
            $this->tclogger->info(
                'Skipping authorizeCapture for order ' . $order->getIncrementId() . ' (calculations-only mode)'
            );
            return;
        }

        // Already reported — don't send the sale again. This flag is the ONLY
        // capture dedupe.
        //
        // Counting the order's invoices or shipments used to guard this as
        // well, and could not: the two events are dispatched at different
        // points relative to their document being written —
        // sales_order_invoice_pay fires from Invoice::register() before the
        // invoice row exists, sales_order_shipment_save_after fires after it —
        // so the same "more than one document" test meant two different things
        // and neither answered the actual question, which is whether the order
        // has been filed. Worse, on the shipment path it suppressed the retry
        // of a capture that had FAILED, and an order whose first shipment
        // failed was then never filed at all.
        //
        // Capture is whole-order on both transports, so a repeat call could
        // never capture "the rest" of anything; TaxCloud answers one with an
        // already-exists response that both gateways map back to success. The
        // flag therefore only saves a wasted round-trip and a log warning that
        // reads like a fault — a lost flag write cannot double-file.
        if ($order->getData('taxcloud_captured')) {
            $this->tclogger->info(
                'Skipping authorizeCapture for order ' . $order->getIncrementId()
                . ' (already captured in TaxCloud)'
            );
            return;
        }

        $this->tclogger->info('Running Observer ' . $eventName . ' (capture trigger: ' . $configuredTrigger . ')');

        if ($this->tcapi->authorizeCapture($order, $this->getCompletedAtFromObserver($observer, $eventName))) {
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
     * Resolve the order the event concerns. Null only for an event this
     * observer does not handle — whether to capture is decided in execute(),
     * against the taxcloud_captured flag, not here.
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
            return $event->getInvoice()->getOrder();
        }

        if ($eventName === self::EVENT_SHIPMENT_SAVE_AFTER) {
            return $event->getShipment()->getOrder();
        }

        return null;
    }

    /**
     * The creation time of the document that triggered this capture — the
     * order, the invoice or the shipment — which is the date TaxCloud files
     * the sale under. Taking it from the document rather than from the clock
     * keeps a capture retried at a later fulfillment document in the period
     * that document belongs to.
     *
     * Null when the document carries no timestamp yet, which is the normal
     * case at sales_order_place_after: the order has not been saved, so it has
     * no created_at. The gateways fall back to now.
     *
     * @param Observer $observer
     * @param string $eventName
     * @return string|null Magento datetime string (UTC)
     */
    private function getCompletedAtFromObserver(Observer $observer, $eventName)
    {
        $event = $observer->getEvent();

        if ($eventName === self::EVENT_ORDER_PLACE_AFTER) {
            $document = $event->getOrder();
        } elseif ($eventName === self::EVENT_INVOICE_PAY) {
            $document = $event->getInvoice();
        } elseif ($eventName === self::EVENT_SHIPMENT_SAVE_AFTER) {
            $document = $event->getShipment();
        } else {
            return null;
        }

        $createdAt = $document ? $document->getCreatedAt() : null;

        return $createdAt !== null && $createdAt !== '' ? (string) $createdAt : null;
    }
}
