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
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Test\Integration\CanadianQuoteTrait;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * A cart shipped to Canada, priced through the real collector pipeline.
 *
 * The unit tests pin the gateway's branches in isolation; what only a real
 * Magento can show is that a Canadian address survives the whole way — region
 * resolution from the directory, the collector, the lookup-before observer that
 * verifies addresses — and arrives at the transport as a Canadian destination
 * whose tax then lands on the quote's totals.
 *
 * Both halves of the opt-in are asserted, because either one failing open is a
 * compliance problem in opposite directions: with the setting off no Canadian
 * request may be made at all, and with it on the request must be Canadian.
 */
class CanadianTaxLookupTest extends IntegrationTestCase
{
    use SeededCatalogTrait;
    use CanadianQuoteTrait;

    /** Flat rate the cart responder applies, so the arithmetic is ours. */
    private const RATE = 0.13;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRestMock($this->restRespondersWith([
            'POST /carts' => $this->flatRateCartResponder(self::RATE),
        ]));
        $this->useRestTransport();
        $this->allowCanadaAsAShippingCountry();
    }

    /**
     * The headline: with Canadian tax on for the quote's store, the cart is
     * priced and the tax reaches the quote.
     */
    public function testACanadianCartIsPricedOverV3(): void
    {
        $this->setCanadaTax(true);

        $quote = $this->collectCanadianQuote();

        $cart = $this->restMock()->firstLookupCart();
        $this->assertNotNull($cart, 'A Canadian cart must reach the v3 carts endpoint.');

        $this->assertSame(
            ['city' => 'Toronto', 'state' => 'ON', 'zip' => 'M5H 2N2', 'countryCode' => 'CA'],
            [
                'city' => $cart['destination']['city'],
                'state' => $cart['destination']['state'],
                'zip' => $cart['destination']['zip'],
                'countryCode' => $cart['destination']['countryCode'],
            ],
            'The destination must name the province, the normalized postal code and the country — '
            . 'without countryCode the v3 API reads the address as US and rejects the postal code.'
        );
        $this->assertSame(
            'US',
            $cart['origin']['countryCode'],
            'The origin is still the US shipping origin.'
        );
        $this->assertArrayNotHasKey(
            'exemption',
            $cart,
            'Certificates cover US states only, so a Canadian cart carries none.'
        );

        $taxed = self::PRODUCT_PRICE + self::SHIPPING_PRICE;
        $this->assertEqualsWithDelta(
            round($taxed * self::RATE, 2),
            (float) $quote->getShippingAddress()->getTaxAmount(),
            0.01,
            'The tax TaxCloud returned must land on the quote, product and shipping alike.'
        );
    }

    /**
     * The postal code the customer typed is normalized before it is sent: the
     * province drives the rate, but a code TaxCloud cannot read is still a
     * Canadian address arriving malformed.
     *
     * @dataProvider postalCodeProvider
     */
    #[DataProvider('postalCodeProvider')]
    public function testThePostalCodeIsNormalizedOnTheWayOut(string $entered): void
    {
        $this->setCanadaTax(true);

        $this->collectCanadianQuote(['postcode' => $entered]);

        $cart = $this->restMock()->firstLookupCart();
        $this->assertNotNull($cart, 'The cart should have been priced.');
        $this->assertSame('M5H 2N2', $cart['destination']['zip']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function postalCodeProvider(): array
    {
        return [
            'canonical' => ['M5H 2N2'],
            'lower case, no space' => ['m5h2n2'],
        ];
    }

    /**
     * With the setting off — the default every install upgrades into — a
     * Canadian cart must not reach TaxCloud at all. Not merely "no tax
     * applied": a request would file a cart for a sale this store has not
     * opted into pricing.
     */
    public function testACanadianCartIsNotSentWhileTheSettingIsOff(): void
    {
        $this->setCanadaTax(false);

        $quote = $this->collectCanadianQuote();

        $this->assertSame(
            0,
            $this->restMock()->callCount('POST', '/carts'),
            'No Canadian lookup may happen while Canadian tax is off.'
        );
        $this->assertSame(0.0, (float) $quote->getShippingAddress()->getTaxAmount());
    }

    /**
     * An address that cannot produce a Canadian postal code is not sent either:
     * the province would still price it, and the customer would be charged tax
     * against an address TaxCloud was never given.
     */
    public function testAnInvalidCanadianPostalCodeIsNotSent(): void
    {
        $this->setCanadaTax(true);

        $quote = $this->collectCanadianQuote(['postcode' => '12345']);

        $this->assertSame(0, $this->restMock()->callCount('POST', '/carts'));
        $this->assertSame(0.0, (float) $quote->getShippingAddress()->getTaxAmount());
    }

    /**
     * TaxCloud verifies US addresses only ("unsupported country code"), so the
     * lookup-before observer must leave a Canadian destination alone — while
     * still verifying a US one, which is what makes this a skip rather than a
     * feature that stopped working.
     */
    public function testCanadianDestinationsSkipAddressVerification(): void
    {
        $this->setCanadaTax(true);
        $this->assertTrue(
            $this->get(TaxcloudConfig::class)->isVerifyAddressEnabled(),
            'The seeded store verifies addresses — otherwise this test would pass vacuously.'
        );

        $this->collectCanadianQuote();

        $this->assertSame(
            0,
            $this->restMock()->callCount('POST', '/tax/verify-address'),
            'A Canadian address must not be sent to verify-address.'
        );

        $this->restMock()->resetCalls();
        $this->collectUsQuote();

        $this->assertSame(
            1,
            $this->restMock()->callCount('POST', '/tax/verify-address'),
            'A US address is still verified.'
        );
    }

    /**
     * The setting is resolved against the store of the quote being priced, not
     * the ambient store: a store view can price Canada while the default scope
     * does not.
     */
    public function testTheSettingIsResolvedAgainstTheQuotesStore(): void
    {
        $this->setCanadaTax(false);
        $this->setSecondStoreConfig('tax/taxcloud_settings/canada_tax_enabled', '1');
        $this->setSecondStoreConfig('tax/taxcloud_settings/api_type', 'rest');
        $this->setSecondStoreConfig('tax/taxcloud_settings/enabled', '1');
        $this->pinAmbientStoreToDefault();

        $this->collectCanadianQuote([], self::SECOND_STORE_CODE);

        $this->assertSame(
            1,
            $this->restMock()->callCount('POST', '/carts'),
            'The store view that enables Canadian tax must price its own cart, whatever the '
            . 'ambient store says.'
        );
        $this->assertSame(
            $this->secondStoreId(),
            (int) $this->restMock()->callsTo('POST', '/carts')[0]['store'],
            'And the call must be made with that store.'
        );

        $this->restMock()->resetCalls();
        $this->collectCanadianQuote();

        $this->assertSame(
            0,
            $this->restMock()->callCount('POST', '/carts'),
            'While the default store view, with the setting off, still sends nothing.'
        );
    }

    /**
     * Build, price and return a Canadian quote.
     *
     * @param array<string, mixed> $addressOverride
     */
    private function collectCanadianQuote(array $addressOverride = [], string $storeCode = 'default'): Quote
    {
        return $this->collectQuoteFor($this->canadianAddress($addressOverride), $storeCode);
    }

    private function collectUsQuote(): Quote
    {
        return $this->collectQuoteFor([]);
    }

    /**
     * @param array<string, mixed> $addressOverride
     */
    private function collectQuoteFor(array $addressOverride, string $storeCode = 'default'): Quote
    {
        $quote = $this->newGuestQuote($addressOverride, $storeCode);
        $product = $this->seededProduct(self::TEST_PRODUCT_SKU);
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, 1)));

        return $this->collectAndSaveQuote($quote);
    }
}
