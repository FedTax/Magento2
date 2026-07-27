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

namespace Taxcloud\Magento2\Test\Integration\MultiStore;

use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * "Only do tax calculations without further Taxcloud integration"
 * (tax/taxcloud_settings/calculations_only) driven through real Magento.
 *
 * The setting splits the module in two: the calculation calls (Lookup,
 * VerifyAddress) keep running so the shopper is charged correctly, while every
 * call that records or reverses a sale in TaxCloud — AuthorizedWithCapture,
 * Returned, OrderDetails — is suppressed, because another system (QuickBooks
 * and the like) owns that side of the integration and a second push would
 * double-report the sale.
 *
 * These tests assert on the recorded SOAP operations rather than on mocks, so
 * they cover the wiring the unit tests cannot see: observer registration, the
 * cancellation plugin, and DI. The default scope is put into calculation-only
 * mode and the second store view left on the full integration, which makes the
 * last test a genuine store-awareness check — a gate that read the ambient
 * store would suppress the second store's capture too.
 */
class CalculationsOnlyStoreViewTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();

        // Default scope: enabled (seeded baseline) and calculation-only.
        $this->setScopedConfig('tax/taxcloud_settings/calculations_only', '1');
    }

    /**
     * Placing an order looks tax up but never captures it.
     *
     * This is the core of the mode — the storefront still charges TaxCloud tax,
     * and TaxCloud is never told a sale happened.
     */
    public function testOrderPlacementLooksUpTaxButDoesNotCapture(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);

        $this->placeOrder();

        $this->assertSame(
            1,
            $soap->callCount('lookup'),
            'Calculation-only mode must still perform the tax Lookup — that is the whole point of the mode.'
        );
        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Calculation-only mode must not report the sale to TaxCloud.'
        );
    }

    /**
     * Address verification is a calculation-side call and stays on.
     */
    public function testAddressVerificationStillRuns(): void
    {
        $soap = $this->soapClient();
        $this->setScopedConfig('tax/taxcloud_settings/verify_address', '1');

        $this->placeOrder();

        $this->assertGreaterThan(
            0,
            $soap->callCount('verifyAddress'),
            'Calculation-only mode must leave VerifyAddress running; it does not touch order state.'
        );
    }

    /**
     * No capture means no taxcloud_captured flag.
     *
     * The cancel flow reads this flag to decide whether to reverse. Writing it
     * for an uncaptured order would arm a Returned call for a sale TaxCloud has
     * no record of, so its absence is part of the contract, not an accident.
     */
    public function testCapturedFlagIsNotSetOnTheOrder(): void
    {
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);

        $order = $this->placeOrder();

        $this->assertEmpty(
            $order->getData('taxcloud_captured'),
            'An order that was never captured in TaxCloud must not carry the taxcloud_captured flag.'
        );
    }

    /**
     * Capture is suppressed on the invoice trigger too.
     *
     * The gate sits after the capture-trigger routing, so it has to hold for
     * whichever event the store captures on — not just order placement.
     */
    public function testInvoicePaymentDoesNotCapture(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);

        $order = $this->placeOrder();
        $this->payInvoice($order);

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Calculation-only mode must suppress the capture on the payment trigger as well.'
        );
    }

    /**
     * Refunding never sends Returned.
     */
    public function testCreditMemoDoesNotReverseInTaxcloud(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);

        $order = $this->placeOrder();
        $this->payInvoice($order);
        $this->refundOrder($order);

        $this->assertSame(
            0,
            $soap->callCount('Returned'),
            'There is no TaxCloud sale to reverse in calculation-only mode, so a credit memo must not call Returned.'
        );
    }

    /**
     * Cancelling an uninvoiced order never sends Returned, and never probes
     * OrderDetails to decide whether it should.
     */
    public function testCancellationDoesNotReverseInTaxcloud(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);

        $order = $this->placeOrder();
        $this->pinAmbientStoreToDefault();

        $this->cancelOrder($order);

        $this->assertSame(
            0,
            $soap->callCount('Returned'),
            'Cancelling an order that was never captured must not call Returned.'
        );
        $this->assertSame(
            0,
            $soap->callCount('OrderDetails'),
            'The cancellation gate must short-circuit before the license-gated OrderDetails probe.'
        );
    }

    /**
     * Store-awareness: calculation-only at default scope must not leak into a
     * store view that is left on the full integration.
     *
     * Both orders go through the same request-scoped observers. The second
     * store's capture may only fire because the gate resolves the ORDER's store
     * rather than the ambient one — the ambient store is pinned to the default
     * (calculation-only) view before the capture is triggered, so an ambient
     * read would suppress it.
     */
    public function testSecondStoreWithoutTheSettingStillCaptures(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);

        // The seeded baseline disables TaxCloud on the second store; turn it
        // back on and leave it on the full integration.
        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/calculations_only', '0');

        $defaultOrder = $this->placeOrder();
        $secondOrder = $this->placeOrder(self::SECOND_STORE_CODE);

        // Ambient store = default, where calculations_only=1. Both captures
        // below resolve their own order's store or neither fires.
        $this->pinAmbientStoreToDefault();

        $this->payInvoice($defaultOrder);
        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'The default-scope order is calculation-only and must not capture.'
        );

        $this->payInvoice($secondOrder);
        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'The second store does not set calculations_only, so its order must still capture — '
            . 'a gate reading the ambient store would wrongly suppress this.'
        );

        $capture = $soap->firstCallArgs('authorizedWithCapture');
        $this->assertSame(
            $secondOrder->getIncrementId(),
            $capture['orderID'] ?? null,
            'The one capture that fired must be the second store\'s order, not the calculation-only one.'
        );
    }

    /**
     * The inverse: calculation-only set only at store scope must not suppress
     * the default store's capture.
     */
    public function testSettingAtStoreScopeDoesNotAffectTheDefaultStore(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);

        // Undo the class-wide default-scope setting for this test: only the
        // second store view runs calculation-only here.
        $this->setScopedConfig('tax/taxcloud_settings/calculations_only', '0');
        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/calculations_only', '1');

        $this->placeOrder();

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'A store-scope calculations_only override must not suppress the default store\'s capture.'
        );
    }
}
