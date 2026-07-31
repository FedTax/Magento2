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
 * Admin-side lifecycle for a store view with TaxCloud ENABLED while the
 * default scope is DISABLED (TC-4, TC-5) — the dangerous inverse.
 *
 * Pre-fix, this configuration collected tax from the shopper on the
 * storefront (store scope resolves correctly there) and then silently skipped
 * every admin-side capture/reversal because the observers read the default
 * scope's enabled=0 — tax collected but never reported, cancellations never
 * reversed. These tests pin the fix: all lifecycle calls fire, and they carry
 * the STORE VIEW's credentials.
 */
class EnabledStoreViewLifecycleTest extends IntegrationTestCase
{
    private const SECOND_STORE_API_ID = 'second-store-api-id';
    private const SECOND_STORE_API_KEY = 'second-store-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();

        // Inverse of the seeded baseline: default scope OFF, second store ON,
        // with its own TaxCloud account credentials. setScopedConfig snapshots
        // and restores everything in tearDown.
        $this->setScopedConfig('tax/taxcloud_settings/enabled', '0');
        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/api_id', self::SECOND_STORE_API_ID);
        $this->setSecondStoreConfig('tax/taxcloud_settings/api_key', self::SECOND_STORE_API_KEY);
    }

    /**
     * TC-4 — Admin capture uses the store view's account.
     *
     * capture_trigger = On payment moves capture out of the storefront and
     * into the admin invoice flow — exactly where the pre-fix code read the
     * default scope's enabled=0 and returned silently. The capture must fire
     * on invoice pay and carry the second store's apiLoginID.
     */
    public function testInvoiceCaptureFiresUnderStoreViewAccountWhenDefaultDisabled(): void
    {
        $soap = $this->soapClient();
        $this->setScopedConfig('tax/taxcloud_settings/capture_trigger', CaptureTrigger::PAYMENT);

        $order = $this->placeOrder(self::SECOND_STORE_CODE);

        // The storefront leg already runs under the store view's account.
        $lookup = $soap->firstCallArgs('lookup');
        $this->assertNotNull($lookup, 'Second-store checkout should perform a Lookup (enabled at store scope).');
        $this->assertSame(
            self::SECOND_STORE_API_ID,
            $lookup['apiLoginID'] ?? null,
            'The storefront Lookup must use the second store\'s api_id.'
        );

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'With capture_trigger=payment, placing the order must not capture yet.'
        );

        // Ambient store = default (TaxCloud DISABLED there). The capture below
        // may only fire because the observer reads the ORDER's store.
        $this->pinAmbientStoreToDefault();

        $this->payInvoice($order);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Invoicing a second-store order must capture in TaxCloud even though the '
            . 'default scope is disabled — pre-fix this returned silently and the tax '
            . 'was collected but never reported.'
        );

        $capture = $soap->firstCallArgs('authorizedWithCapture');
        $this->assertSame(
            self::SECOND_STORE_API_ID,
            $capture['apiLoginID'] ?? null,
            'The admin-side capture must carry the second store\'s apiLoginID, not the default account\'s.'
        );
        $this->assertSame(
            self::SECOND_STORE_API_KEY,
            $capture['apiKey'] ?? null,
            'The admin-side capture must carry the second store\'s apiKey.'
        );
        $this->assertSame(
            $order->getIncrementId(),
            $capture['orderID'] ?? null,
            'The capture must reference the second-store order.'
        );
    }

    /**
     * TC-5 — Cancel reversal fires for an enabled store view.
     *
     * A second-store order captured at placement and then cancelled (uninvoiced)
     * must be reversed via Returned — under the store view's credentials.
     */
    public function testCancelReversalFiresUnderStoreViewAccount(): void
    {
        $soap = $this->soapClient();
        $this->setScopedConfig('tax/taxcloud_settings/capture_trigger', CaptureTrigger::ORDER_CREATION);

        $order = $this->placeOrder(self::SECOND_STORE_CODE);

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'With capture_trigger=order_creation, placing the second-store order must capture.'
        );

        // Ambient store = default (TaxCloud DISABLED there). The reversal below
        // may only fire because the processor reads the ORDER's store.
        $this->pinAmbientStoreToDefault();

        $this->cancelOrder($order);

        $this->assertSame(
            1,
            $soap->callCount('Returned'),
            'Cancelling a captured, uninvoiced second-store order must reverse the sale in TaxCloud.'
        );

        $returned = $soap->firstCallArgs('Returned');
        $this->assertSame(
            self::SECOND_STORE_API_ID,
            $returned['apiLoginID'] ?? null,
            'The cancel reversal must carry the second store\'s apiLoginID.'
        );
        $this->assertSame(
            $order->getIncrementId(),
            $returned['orderID'] ?? null,
            'The reversal must reference the cancelled second-store order.'
        );
    }
}
