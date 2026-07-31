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
 * Proves that with capture_trigger = payment, capture happens when the invoice
 * is paid — and NOT when the order is merely placed.
 *
 * Order placement fires sales_order_place_after; paying the invoice fires
 * sales_order_invoice_pay. Observer\Sales\Complete listens to both but acts
 * only on the one matching the configured trigger. Covers gaps #4.2 and #4.3.
 */
class CaptureOnInvoicePayTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);
    }

    public function testCompleteObserverFiresOnInvoicePayWhenTriggerIsPayment(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'With capture_trigger=payment, simply placing the order must NOT capture.'
        );
        $this->assertOrderCapturedFlag(
            $order,
            false,
            'An order that has not been captured yet must not carry taxcloud_captured — '
            . 'the cancel flow would otherwise reverse a sale TaxCloud never received.'
        );

        $this->payInvoice($order);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Paying the invoice should trigger exactly one authorizedWithCapture SOAP call.'
        );

        $args = $soap->firstCallArgs('authorizedWithCapture');
        $this->assertSame(
            $order->getIncrementId(),
            $args['orderID'] ?? null,
            'The captured orderID should match the order whose invoice was paid.'
        );
        $this->assertOrderCapturedFlag(
            $order,
            true,
            'Capturing on invoice payment must persist taxcloud_captured on the order row.'
        );
    }
}
