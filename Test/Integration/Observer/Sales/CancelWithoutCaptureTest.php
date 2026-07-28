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
 * The payoff of moving the capture off order creation: a canceled order that was
 * never captured must leave no trace in TaxCloud.
 *
 * The setting's tooltip sells "On payment" as the way to keep canceled orders
 * out of TaxCloud, and CaptureOnInvoicePayTest already proves the capture is
 * deferred. What was untested is the other half — that cancelling such an order
 * is a clean no-op rather than a Returned call reversing a sale TaxCloud never
 * received (which is itself an error on the merchant's account).
 *
 * The OrderDetails fallback is stubbed to the uncaptured shape, matching what
 * the real API reports for an order that only ever had a Lookup.
 */
class CancelWithoutCaptureTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->installSoapMock($this->soapResponsesWith([
            'OrderDetails' => $this->soapResponseFixture('order_details_not_captured'),
        ]));
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);
    }

    public function testCancellingAnUncapturedOrderDoesNotReverseAnythingInTaxcloud(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'With capture_trigger=payment, placing the order must not capture.'
        );

        $soap->resetCalls();

        $this->cancelOrder($order);

        $this->assertSame(
            0,
            $soap->callCount('Returned'),
            'Cancelling an order that was never captured must not call Returned — there is '
            . 'no sale in TaxCloud to reverse.'
        );
        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Cancelling must not capture either.'
        );
        $this->assertOrderCapturedFlag(
            $order,
            false,
            'A canceled, never-captured order must not carry taxcloud_captured.'
        );
    }

    /**
     * The same journey with the shipment trigger: an order canceled before it
     * ships was never captured, so cancelling stays silent.
     */
    public function testCancellingBeforeShipmentDoesNotReverseAnythingInTaxcloud(): void
    {
        $this->setCaptureTrigger(CaptureTrigger::SHIPMENT);
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $soap->resetCalls();

        $this->cancelOrder($order);

        $this->assertSame(
            0,
            $soap->callCount('Returned'),
            'Cancelling an unshipped order under capture_trigger=shipment must not call Returned.'
        );
    }
}
