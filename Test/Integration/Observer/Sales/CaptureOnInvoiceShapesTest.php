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
 * capture_trigger = payment hangs the whole capture on one event,
 * sales_order_invoice_pay. Not every invoice fires it, and not every invoice
 * that does covers the whole order — CaptureOnInvoicePayTest only exercises the
 * one shape (a single full invoice, paid) where both assumptions hold.
 *
 * These pin what happens for the other two shapes an admin can produce. Both
 * are characterizations of current behavior; the assertion messages say which
 * direction a change would be a regression in.
 */
class CaptureOnInvoiceShapesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);
    }

    /**
     * The first partial invoice captures the whole order — and the invoice that
     * settles the remainder must not capture it again.
     *
     * Capturing on the first partial invoice is right: TaxCloud tracks orders,
     * not invoices, and waiting for a full invoice would leave partially
     * invoiced orders unreported. The second half is where the observer's
     * dedupe fails. It skips an event when the order already has more than one
     * invoice, but sales_order_invoice_pay is dispatched from
     * Invoice::register(), before the invoice is written — so at dispatch time
     * the collection holds only the EARLIER invoices. For invoice #2 that count
     * is 1, the guard does not trip, and the sale is filed with TaxCloud a
     * second time. (The guard only ever engages from invoice #3 on.)
     */
    public function testFirstPartialInvoiceCapturesTheWholeOrderOnce(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder('default', 2);
        $itemIds = [];
        foreach ($order->getAllItems() as $item) {
            $itemIds[(int) $item->getId()] = 1.0;
        }

        $this->payPartialInvoice($order, $itemIds);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'The first partial invoice must capture the order in TaxCloud — waiting for a '
            . 'full invoice would leave partially-shipped orders unreported.'
        );
        $this->assertOrderCapturedFlag($order, true);

        // Invoice the rest. Now the invoice collection is 2, which the observer
        // skips — the sale is already captured.
        $this->payInvoice($this->reloadOrder($order));

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Invoicing the remainder must not capture a second time.'
        );
    }

    /**
     * Requesting NOT_CAPTURE does not skip the capture for an offline payment
     * method, and it must not.
     *
     * Invoice::register() only honors the requested capture case when
     * canCapture() is true; for a non-gateway method (the seeded checkmo, and
     * every offline method) it falls into the `!isGateway()` branch and calls
     * pay() anyway. So sales_order_invoice_pay still fires and the sale is still
     * reported. Worth pinning because the natural reading of "no capture" is
     * that TaxCloud hears nothing — the opposite of what happens, and the
     * opposite would be the dangerous outcome (tax collected, never reported).
     */
    public function testNotCaptureInvoiceOnAnOfflineMethodStillReportsTheSale(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();

        $this->createUnpaidInvoice($order);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'An offline payment method pays the invoice during register() whatever capture '
            . 'case was requested, so capture_trigger=payment must still report the sale.'
        );
        $this->assertOrderCapturedFlag($order, true);
    }
}
