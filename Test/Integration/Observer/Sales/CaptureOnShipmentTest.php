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
     * (Magento\Shipping\Controller\Adminhtml\Order\Shipment\AddTrack), and the
     * observer's dedupe would not catch it: it counts shipments on the order,
     * which is still 1. What saves us today is upstream — that second save does
     * not re-dispatch sales_order_shipment_save_after, verified by instrumenting
     * the observer. So this passes for a reason outside the module's control,
     * which is exactly why it is worth pinning: if a Magento upgrade starts
     * dispatching on the track save, the module would silently start filing
     * every tracked shipment as a second sale.
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
}
