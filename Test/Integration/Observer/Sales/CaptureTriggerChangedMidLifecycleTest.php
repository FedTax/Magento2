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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Observer\Sales;

use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The setting is read fresh on every lifecycle event, so an order can outlive
 * the configuration it was placed under. Changing it is a one-click admin
 * action, and orders in flight at that moment are the ones at risk.
 *
 * Two directions, both of which have to be safe:
 *
 *  - loosening (order_creation -> shipment): the order was already captured at
 *    placement, so shipping it must not capture a second time;
 *  - tightening (shipment -> order_creation): the order was placed before the
 *    change and never captured, so it must not be left unreported when the
 *    event it was waiting for finally arrives.
 */
class CaptureTriggerChangedMidLifecycleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
    }

    public function testSwitchingToShipmentAfterAnOrderWasCapturedAtPlacementDoesNotCaptureAgain(): void
    {
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Sanity: the order captures at placement under capture_trigger=order_creation.'
        );

        // The merchant follows the tooltip's advice and moves capture later.
        $this->setCaptureTrigger(CaptureTrigger::SHIPMENT);

        $this->payInvoice($order);
        $this->createShipment($order);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'An order already captured at placement must not be captured again when the '
            . 'trigger moves to shipment — that reports the same sale to TaxCloud twice.'
        );
    }

    /**
     * The reverse switch must not strand the order: it was placed while the
     * store captured on shipment, so nothing captured at placement, and moving
     * the setting back to order_creation removes the event it was waiting for.
     * Shipping it is now the merchant's last touchpoint.
     */
    public function testSwitchingToOrderCreationAfterPlacementLeavesTheOrderUncaptured(): void
    {
        $this->setCaptureTrigger(CaptureTrigger::SHIPMENT);
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Sanity: nothing captures at placement under capture_trigger=shipment.'
        );

        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);

        $this->payInvoice($order);
        $this->createShipment($order);

        // Characterization, not an endorsement: the sale silently never reaches
        // TaxCloud. If this ever starts capturing, the change was deliberate and
        // this test should be updated to assert 1 — but it must not change by
        // accident, because the failure mode is unreported tax.
        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Known gap: an order placed under capture_trigger=shipment is never captured '
            . 'once the setting moves to order_creation, because the shipment event no '
            . 'longer matches the configured trigger.'
        );
        $this->assertOrderCapturedFlag($order, false);
    }
}
