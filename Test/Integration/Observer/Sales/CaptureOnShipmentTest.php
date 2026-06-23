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
    }
}
