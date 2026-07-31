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

use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Admin-side lifecycle for a store view with TaxCloud DISABLED (TC-2, TC-3).
 *
 * This is the core multi-store bug the store-scoping fix addressed: admin
 * cancel/refund observers run with the ambient (default) store — where
 * TaxCloud is enabled — and used to fire live API calls for orders belonging
 * to a store view where the merchant had switched TaxCloud off. Every config
 * gate must resolve against the ORDER's store.
 *
 * The seeded baseline is already this exact configuration: enabled=1 at
 * default scope, enabled=0 at stores/second. These tests also serve as the
 * TC-10 (CLI/cron context) proof: the ambient store is pinned to the default
 * store view before each admin-side action, yet the order's store wins.
 */
class DisabledStoreViewAdminLifecycleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
    }

    /**
     * TC-2 — Admin cancel respects a disabled store view.
     *
     * A second-store order cancelled from the admin/CLI context must produce
     * no TaxCloud traffic: no OrderDetails probe, no Returned reversal.
     * (Pre-fix, this logged two OrderDetails calls under the default
     * account.)
     */
    public function testCancelOfSecondStoreOrderMakesNoTaxcloudCalls(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder(self::SECOND_STORE_CODE);

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Placing a second-store order must not capture — TaxCloud is off for that store.'
        );

        // TC-10: pin the ambient store to the default store view (where
        // TaxCloud is ENABLED) — the observers can only skip correctly by
        // reading the ORDER's store.
        $this->pinAmbientStoreToDefault();

        $this->cancelOrder($order);

        $this->assertSame(
            0,
            $soap->callCount('OrderDetails'),
            'Cancelling a disabled-store order must not probe OrderDetails under the default account.'
        );
        $this->assertSame(
            0,
            $soap->callCount('Returned'),
            'Cancelling a disabled-store order must not send a Returned reversal.'
        );
    }

    /**
     * TC-3 — Admin refund respects a disabled store view.
     *
     * Invoicing and refunding a second-store order must not capture and must
     * not send a Returned call.
     */
    public function testRefundOfSecondStoreOrderMakesNoTaxcloudCalls(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder(self::SECOND_STORE_CODE);
        $this->payInvoice($order);

        $this->assertSame(
            0,
            $soap->callCount('authorizedWithCapture'),
            'Neither placing nor invoicing a disabled-store order may capture in TaxCloud.'
        );

        $this->pinAmbientStoreToDefault();

        $this->refundOrder($order);

        $this->assertSame(
            0,
            $soap->callCount('Returned'),
            'Refunding a disabled-store order must not send a Returned call.'
        );
    }

}
