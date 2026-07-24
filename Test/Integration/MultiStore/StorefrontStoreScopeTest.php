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
 * Storefront-side store-view scoping (TC-1, TC-6, TC-7).
 *
 * The seeded baseline is a real multi-store setup: TaxCloud enabled at default
 * scope, DISABLED at the second store view (stores/second), same catalog on
 * both websites. These tests build carts on each store and assert that every
 * config decision — enabled, TICs, verify_address — resolves against the
 * QUOTE's store, not the ambient CLI/default store.
 */
class StorefrontStoreScopeTest extends IntegrationTestCase
{
    /**
     * TC-1 — Storefront honors the store-view override (regression guard).
     *
     * Same cart, two stores: the default store's totals collection performs a
     * TaxCloud Lookup; the second store's (enabled=0 at store scope) performs
     * NO TaxCloud traffic at all and computes zero tax.
     */
    public function testSecondStoreQuoteMakesNoTaxcloudCallsWhileDefaultStoreDoes(): void
    {
        $soap = $this->installSoapMock();

        // Contrast/harness sanity: the default store DOES look up.
        $this->buildQuoteWithTestProduct(1);
        $this->assertGreaterThan(
            0,
            $soap->callCount('lookup'),
            'Default-store totals collection should reach the (mock) TaxCloud Lookup — '
            . 'if this fails the harness is broken, not the store scoping.'
        );

        $soap->resetCalls();

        $quote = $this->buildQuoteWithTestProduct(1, [], self::SECOND_STORE_CODE);

        $this->assertSame(
            [],
            $soap->getCalls(),
            'With TaxCloud disabled at stores/second, a second-store cart must produce '
            . 'NO TaxCloud SOAP traffic of any kind.'
        );
        $this->assertSame(
            0.0,
            (float) $quote->getShippingAddress()->getTaxAmount(),
            'A second-store cart must carry no TaxCloud tax.'
        );
    }

    /**
     * TC-6 — Per-store TIC codes.
     *
     * default_tic / shipping_tic overridden at the second store view must reach
     * the Lookup payload for a second-store cart, while the default store keeps
     * its own values.
     */
    public function testSecondStoreLookupCarriesStoreScopedTics(): void
    {
        $soap = $this->installSoapMock();

        // Enable TaxCloud on the second store so its lookup actually runs, and
        // give it store-scoped TICs distinct from the default scope's
        // (default_tic=20000 seeded; shipping_tic unset -> 11010 fallback).
        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/default_tic', '20010');
        $this->setSecondStoreConfig('tax/taxcloud_settings/shipping_tic', '11000');

        $this->buildQuoteWithTestProduct(1, [], self::SECOND_STORE_CODE);

        $secondPayload = $soap->firstCallArgs('lookup');
        $this->assertNotNull($secondPayload, 'Second-store cart should perform a Lookup once enabled there.');
        $this->assertSame(
            '20010',
            $this->cartItemTic($secondPayload, self::TEST_PRODUCT_SKU),
            'The second-store Lookup must carry the STORE-scoped default_tic for untagged products.'
        );
        $this->assertSame(
            '11000',
            $this->cartItemTic($secondPayload, 'shipping'),
            'The second-store Lookup must carry the STORE-scoped shipping_tic.'
        );

        $soap->resetCalls();

        // Contrast: the default store still resolves its own values.
        $this->buildQuoteWithTestProduct(1);

        $defaultPayload = $soap->firstCallArgs('lookup');
        $this->assertNotNull($defaultPayload, 'Default-store cart should perform a Lookup.');
        $this->assertSame(
            '20000',
            $this->cartItemTic($defaultPayload, self::TEST_PRODUCT_SKU),
            'The default store must keep the default-scope default_tic.'
        );
        $this->assertSame(
            '11010',
            $this->cartItemTic($defaultPayload, 'shipping'),
            'The default store must keep the shipping_tic fallback.'
        );
    }

    /**
     * TC-7 — Per-store verify_address.
     *
     * verify_address=1 at default, 0 at the second store: a second-store lookup
     * must not trigger VerifyAddress, while a default-store lookup still does.
     */
    public function testSecondStoreLookupSkipsVerifyAddressWhenDisabledAtStoreScope(): void
    {
        $soap = $this->installSoapMock();

        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/verify_address', '0');

        // Second store first (fresh lookup cache): the lookup runs, address
        // verification does not.
        $this->buildQuoteWithTestProduct(1, [], self::SECOND_STORE_CODE);

        $this->assertGreaterThan(
            0,
            $soap->callCount('lookup'),
            'Second-store cart should perform a Lookup once enabled there.'
        );
        $this->assertSame(
            0,
            $soap->callCount('verifyAddress'),
            'verify_address=0 at stores/second must suppress VerifyAddress for second-store carts.'
        );

        $soap->resetCalls();

        // Contrast: the default store (verify_address=1 seeded) still verifies.
        // The Address observer runs before the lookup cache is consulted, so
        // this holds even if the lookup itself is served from cache.
        $this->buildQuoteWithTestProduct(1);

        $this->assertGreaterThan(
            0,
            $soap->callCount('verifyAddress'),
            'The default store must still verify addresses.'
        );
    }

    /**
     * The TIC sent for a given ItemID in a Lookup payload, or null.
     */
    private function cartItemTic(array $lookupArgs, string $itemId): ?string
    {
        foreach ($lookupArgs['cartItems'] ?? [] as $cartItem) {
            if (($cartItem['ItemID'] ?? null) === $itemId) {
                return isset($cartItem['TIC']) ? (string) $cartItem['TIC'] : null;
            }
        }
        return null;
    }
}
