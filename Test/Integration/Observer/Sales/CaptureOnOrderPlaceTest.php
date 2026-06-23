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
 * Proves that with capture_trigger = order_creation, placing a real order
 * fires sales_order_place_after, which Magento routes to
 * Observer\Sales\Complete, which calls Api::authorizeCapture() -> the
 * authorizedWithCapture SOAP operation.
 *
 * This is the wiring only an integration test can prove: not "the observer does
 * the right thing given an event" (a unit test covers that) but "Magento
 * actually fires the event to our observer at the right time". Covers gap #4.1.
 */
class CaptureOnOrderPlaceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
    }

    public function testCompleteObserverFiresOnOrderPlaceWhenTriggerIsOrderCreation(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Placing an order with capture_trigger=order_creation should trigger exactly one '
            . 'authorizedWithCapture SOAP call via Observer\\Sales\\Complete.'
        );

        $args = $soap->firstCallArgs('authorizedWithCapture');
        $this->assertNotNull($args, 'authorizedWithCapture was never called.');
        $this->assertSame(
            $order->getIncrementId(),
            $args['orderID'] ?? null,
            'The captured orderID should match the placed order increment ID.'
        );
    }
}
