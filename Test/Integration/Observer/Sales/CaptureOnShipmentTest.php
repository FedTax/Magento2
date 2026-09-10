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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Observer\Sales;

use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that with capture_trigger = shipment, capture happens when a shipment
 * is created — and NOT on order placement or invoice payment.
 *
 * Creating the shipment fires sales_order_shipment_save_after, which
 * Observer\Sales\Complete acts on only when the configured trigger is shipment.
 * Covers gap #4.4.
 */
class CaptureOnShipmentTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->setCaptureTrigger(CaptureTrigger::SHIPMENT);
    }

    public function testCompleteObserverFiresOnShipmentSaveAfterWhenTriggerIsShipment(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $this->payInvoice($order);

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'With capture_trigger=shipment, neither placing the order nor paying the '
            . 'invoice should capture.'
        );
        $this->assertOrderCapturedFlag(
            $order,
            false,
            'Nothing has been captured yet, so taxcloud_captured must still be unset.'
        );

        $this->createShipment($order);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Creating the shipment should trigger exactly one authorizedWithCapture SOAP call.'
        );

        $args = $soap->firstCallArgs('authorizedWithCapture');
        $this->assertSame(
            $order->getIncrementId(),
            $args['orderID'] ?? null,
            'The captured orderID should match the shipped order.'
        );
        $this->assertOrderCapturedFlag(
            $order,
            true,
            'Capturing on shipment must persist taxcloud_captured on the order row.'
        );
    }

    /**
     * Attaching a tracking number to the only shipment must not report the sale
     * to TaxCloud twice.
     *
     * The admin "Add Tracking Number" action saves the shipment a second time
     * (Magento\Shipping\Controller\Adminhtml\Order\Shipment\AddTrack). Two
     * independent things stop a second filing, and the test pins both:
     * upstream, that save does not re-dispatch sales_order_shipment_save_after
     * (verified by instrumenting the observer); and in the module, the
     * taxcloud_captured flag would suppress the capture even if it did.
     *
     * The module's own defence is the one that matters here. The old
     * count-of-shipments dedupe could not have caught this — the count is still
     * 1 — so the case rested entirely on Magento's dispatch behavior, and a
     * Magento upgrade that started dispatching on the track save would have
     * filed every tracked shipment as a second sale.
     */
    public function testAddingTrackingToTheOnlyShipmentDoesNotCaptureTwice(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $shipment = $this->createShipment($order);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Sanity: creating the shipment should have captured once.'
        );

        // Same shipment, saved again with a tracking number attached.
        $this->addTrackingToShipment($shipment);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Saving the same shipment a second time must not capture again — the sale '
            . 'would be reported to TaxCloud twice.'
        );
    }

    /**
     * A capture that FAILS at the first shipment is retried at the second, and
     * the order ends up filed exactly once.
     *
     * This is the regression the change exists for, and the one unit tests
     * cannot reach: it needs real Magento events, dispatched by real shipment
     * saves, against the real observer wiring. Before the fix, a count of the
     * order's shipments suppressed every event after the first — so a transient
     * failure on shipment #1 (a transport error, an expired credential) meant
     * the sale was NEVER filed, with no cron or CLI that would have picked it
     * up. Tax collected from the customer, never reported.
     *
     * The order is deliberately shipped in two parts, which is the shape that
     * makes the retry reachable at all.
     */
    public function testAFailedCaptureIsRetriedOnTheNextShipment(): void
    {
        $soap = $this->soapClient();

        // Fail the first authorizedWithCapture, succeed on every later one.
        $attempts = 0;
        $soap->setResponse('authorizedWithCapture', static function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                return [
                    'AuthorizedWithCaptureResult' => [
                        'ResponseType' => 'Error',
                        'Messages' => [
                            'ResponseMessage' => ['Message' => 'Simulated transport failure'],
                        ],
                    ],
                ];
            }

            return [
                'AuthorizedWithCaptureResult' => ['ResponseType' => 'OK', 'Messages' => ''],
            ];
        });

        $order = $this->placeOrder('default', 2);
        $itemIds = [];
        foreach ($order->getAllItems() as $item) {
            $itemIds[(int) $item->getId()] = 1.0;
        }

        // Shipment #1 — capture is attempted and fails.
        $this->createPartialShipment($order, $itemIds);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'The first shipment must attempt the capture.'
        );
        $this->assertOrderCapturedFlag(
            $this->reloadOrder($order),
            false,
            'A failed capture must not flag the order as captured — the flag is what '
            . 'suppresses the retry, and the cancel flow reads it to decide whether to reverse.'
        );

        // Shipment #2 — the remainder. The order is still unflagged, so capture retries.
        $this->createPartialShipment($this->reloadOrder($order), $itemIds);

        $this->assertSame(
            2,
            $soap->callCount('authorizedWithCapture'),
            'The second shipment must retry the capture that failed. Before this change '
            . 'the shipment count guard swallowed it and the order was never filed.'
        );
        $this->assertOrderCapturedFlag(
            $this->reloadOrder($order),
            true,
            'The retry succeeded, so the order is now recorded as captured.'
        );
    }

    /**
     * The retry is bounded by the flag, not by luck: once the sale is filed, a
     * third shipment must not file it again. Guards the obvious failure mode of
     * the test above — "retries forever" would be as wrong as "never retries".
     */
    public function testTheRetryStopsOnceTheOrderIsCaptured(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder('default', 3);
        $itemIds = [];
        foreach ($order->getAllItems() as $item) {
            $itemIds[(int) $item->getId()] = 1.0;
        }

        $this->createPartialShipment($order, $itemIds);
        $this->createPartialShipment($this->reloadOrder($order), $itemIds);
        $this->createPartialShipment($this->reloadOrder($order), $itemIds);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Capture succeeded on the first shipment, so the two later shipments must '
            . 'not file the sale again.'
        );
    }

}
