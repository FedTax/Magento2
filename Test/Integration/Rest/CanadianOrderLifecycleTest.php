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

namespace Taxcloud\Magento2\Test\Integration\Rest;

use Magento\Framework\DataObject;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\CanadianQuoteTrait;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * What happens to a Canadian order after it is placed.
 *
 * Pricing a Canadian cart is only half of the obligation: the sale has to be
 * filed, and a later refund or cancellation has to reverse it. Each step runs
 * here through the real Magento lifecycle (place, credit memo, cancel) with the
 * v3 transport recorded, because each is driven by a different observer or
 * plugin and any one of them could stop at the country gate on its own.
 *
 * The failure this guards against is silent and expensive: tax collected from a
 * Canadian customer and no transaction in TaxCloud to file it under.
 */
class CanadianOrderLifecycleTest extends IntegrationTestCase
{
    use SeededCatalogTrait;
    use CanadianQuoteTrait;

    private const RATE = 0.13;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRestMock($this->restRespondersWith([
            'POST /carts' => $this->flatRateCartResponder(self::RATE),
        ]));
        $this->useRestTransport();
        $this->allowCanadaAsAShippingCountry();
        $this->setCanadaTax(true);
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
    }

    /**
     * The order is filed with the Canadian destination it was quoted against,
     * carrying the tax the customer actually paid.
     */
    public function testACanadianOrderIsFiledWithItsCanadianDestination(): void
    {
        $order = $this->placeCanadianOrder();

        $body = $this->restMock()->firstBody('POST', '/orders');
        $this->assertNotNull($body, 'A Canadian order must be filed with TaxCloud.');

        $this->assertSame($order->getIncrementId(), $body['orderId']);
        $this->assertSame('CA', $body['destination']['countryCode']);
        $this->assertSame('ON', $body['destination']['state']);
        $this->assertSame('M5H 2N2', $body['destination']['zip']);
        $this->assertSame('US', $body['origin']['countryCode'], 'The origin is still the US origin.');

        $filedTax = 0.0;
        foreach ($body['lineItems'] as $line) {
            $filedTax += (float) $line['tax']['amount'];
        }
        $this->assertEqualsWithDelta(
            (float) $order->getBaseTaxAmount(),
            $filedTax,
            0.01,
            'The tax filed must equal the tax charged, or the return will not match the sale.'
        );

        $this->assertOrderCapturedFlag($order, true, 'A filed order must be flagged captured.');
    }

    /**
     * A credit memo reverses it. The refund is addressed by order id, so what
     * this really proves is that the refund path is reached at all for a
     * Canadian order.
     */
    public function testACanadianOrderCanBeRefunded(): void
    {
        $order = $this->placeCanadianOrder();
        $this->payInvoice($order);
        $this->restMock()->resetCalls();

        $this->refundOrder($order);

        $refunds = $this->restMock()->callsTo('POST', '/orders/refunds');
        $this->assertCount(1, $refunds, 'A Canadian credit memo must reach the v3 refunds endpoint.');
        $this->assertStringContainsString(
            rawurlencode((string) $order->getIncrementId()),
            $refunds[0]['path'],
            'The refund must name the order it reverses.'
        );
    }

    /**
     * Cancelling an uninvoiced order reverses the capture the same way — the
     * path that runs through the cancellation plugin rather than an observer.
     */
    public function testCancellingACanadianOrderReversesItsCapture(): void
    {
        $order = $this->placeCanadianOrder();
        $this->restMock()->resetCalls();

        $this->cancelOrder($order);

        $this->assertCount(
            1,
            $this->restMock()->callsTo('POST', '/orders/refunds'),
            'A cancelled Canadian order must be reversed in TaxCloud.'
        );
    }

    /**
     * And with the setting off at capture time, the order is not filed — the
     * documented consequence of switching Canadian tax off while orders are
     * still waiting for their capture trigger.
     */
    public function testAnOrderIsNotFiledOnceCanadianTaxIsSwitchedOff(): void
    {
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);
        $order = $this->placeCanadianOrder();

        $this->setCanadaTax(false);
        $this->restMock()->resetCalls();

        $this->payInvoice($order);

        $this->assertSame(
            0,
            $this->restMock()->callCount('POST', '/orders'),
            'With Canadian tax off, the order has no usable destination and is not filed.'
        );
        $this->assertOrderCapturedFlag($order, false);
    }

    private function placeCanadianOrder(): Order
    {
        $quote = $this->newGuestQuote($this->canadianAddress());
        $product = $this->seededProduct(self::TEST_PRODUCT_SKU);
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, 1)));
        $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }
}
