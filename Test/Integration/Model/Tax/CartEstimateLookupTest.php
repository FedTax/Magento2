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

namespace Taxcloud\Magento2\Test\Integration\Model\Tax;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Taxcloud\Magento2\Model\Address\EstimateAddress;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Test\Integration\CanadianQuoteTrait;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * The cart-page estimator's address — country, region and ZIP, no street or
 * city — priced through the real collector pipeline on both transports.
 *
 * The unit tests pin the gate and the placeholder in isolation. What only a
 * real Magento shows is that a quote address as Magento stores it (null street,
 * null city) gets past the collector, that the lookup-before observer really
 * does leave the placeholder alone with Verify Address on, and that the tax
 * returned lands on the quote — and that entering the full address afterwards
 * replaces the estimate with a normal, verified lookup.
 */
class CartEstimateLookupTest extends IntegrationTestCase
{
    use SeededCatalogTrait;
    use CanadianQuoteTrait;

    /** Flat rate both doubles apply, so the tax assertion is arithmetic. */
    private const RATE = 0.10;

    /** What the estimator saves: region and ZIP, nothing else. */
    private const ESTIMATOR_ADDRESS = ['street' => null, 'city' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => $this->flatRateLookupResponder(self::RATE),
            'verifyAddress' => $this->echoingVerifyAddressResponder(),
        ]));
        $this->assertTrue(
            $this->get(TaxcloudConfig::class)->isVerifyAddressEnabled(),
            'The seeded store verifies addresses — otherwise the skip assertions would pass vacuously.'
        );
    }

    public function testSoapEstimateIsPricedWithThePlaceholderAndNotVerified(): void
    {
        $quote = $this->collectQuoteFor(self::ESTIMATOR_ADDRESS);
        $soap = $this->soapClient();

        $lookup = $soap->firstCallArgs('lookup');
        $this->assertNotNull($lookup, 'An estimator address must reach TaxCloud.');
        $this->assertSame(EstimateAddress::PLACEHOLDER, $lookup['destination']['Address1']);
        $this->assertSame(EstimateAddress::PLACEHOLDER, $lookup['destination']['City']);
        $this->assertSame('TX', $lookup['destination']['State']);
        $this->assertSame('78701', $lookup['destination']['Zip5']);
        $this->assertSame(0, $soap->callCount('verifyAddress'), 'A placeholder can never verify.');

        $this->assertTaxOnQuote($quote);
    }

    public function testRestEstimateIsPricedWithThePlaceholderAndNotVerified(): void
    {
        $this->installRestMock($this->restRespondersWith([
            'POST /carts' => $this->flatRateCartResponder(self::RATE),
        ]));
        $this->useRestTransport();

        $quote = $this->collectQuoteFor(self::ESTIMATOR_ADDRESS);

        $cart = $this->restMock()->firstLookupCart();
        $this->assertNotNull($cart, 'An estimator address must reach the v3 carts endpoint.');
        $this->assertSame(
            [
                'line1' => EstimateAddress::PLACEHOLDER,
                'city' => EstimateAddress::PLACEHOLDER,
                'state' => 'TX',
                'zip' => '78701',
                'countryCode' => 'US',
            ],
            $cart['destination']
        );
        $this->assertSame(0, $this->restMock()->callCount('POST', '/tax/verify-address'));

        $this->assertTaxOnQuote($quote);
    }

    /**
     * Checkout supersedes the estimate: the same quote, re-totalled with the
     * full address, is looked up with the real street and city and verified.
     */
    public function testTheFullAddressReplacesTheEstimate(): void
    {
        $quote = $this->collectQuoteFor(self::ESTIMATOR_ADDRESS);
        $soap = $this->soapClient();
        $soap->resetCalls();

        $quote->getShippingAddress()->addData(['street' => '1401 Lavaca St', 'city' => 'Austin']);
        // collectTotals() is a no-op once a quote is flagged collected; the
        // checkout address save clears the flag the same way.
        $quote->setTotalsCollectedFlag(false);
        $this->collectAndSaveQuote($quote);

        $lookup = $soap->firstCallArgs('lookup');
        $this->assertNotNull($lookup, 'The full address must be looked up afresh.');
        $this->assertSame('1401 LAVACA ST', $lookup['destination']['Address1'], 'verified (the double upper-cases)');
        $this->assertSame('AUSTIN', $lookup['destination']['City']);
        $this->assertSame(1, $soap->callCount('verifyAddress'), 'A real address is verified again.');
    }

    public function testCanadianEstimateIsPricedOnACanadaEnabledStore(): void
    {
        $this->installRestMock($this->restRespondersWith([
            'POST /carts' => $this->flatRateCartResponder(self::RATE),
        ]));
        $this->useRestTransport();
        $this->allowCanadaAsAShippingCountry();
        $this->setCanadaTax(true);

        $this->collectQuoteFor($this->canadianAddress(self::ESTIMATOR_ADDRESS));

        $cart = $this->restMock()->firstLookupCart();
        $this->assertNotNull($cart, 'A Canadian estimator address must be priced.');
        $this->assertSame(EstimateAddress::PLACEHOLDER, $cart['destination']['line1']);
        $this->assertSame(EstimateAddress::PLACEHOLDER, $cart['destination']['city']);
        $this->assertSame('ON', $cart['destination']['state']);
        $this->assertSame('M5H 2N2', $cart['destination']['zip']);
        $this->assertSame('CA', $cart['destination']['countryCode']);
    }

    /**
     * An estimator address with no region is still not priced: the placeholder
     * only stands in for street and city, never for the state the rate needs.
     */
    public function testAnEstimateWithoutARegionIsNotSent(): void
    {
        $quote = $this->collectQuoteFor(self::ESTIMATOR_ADDRESS + ['region_id' => null, 'region' => null]);

        $this->assertSame(0, $this->soapClient()->callCount('lookup'));
        $this->assertSame(0.0, (float) $quote->getShippingAddress()->getTaxAmount());
    }

    private function assertTaxOnQuote(Quote $quote): void
    {
        $this->assertEqualsWithDelta(
            round((self::PRODUCT_PRICE + self::SHIPPING_PRICE) * self::RATE, 2),
            (float) $quote->getShippingAddress()->getTaxAmount(),
            0.01,
            'The estimated tax must land on the quote, product and shipping alike.'
        );
    }

    /**
     * @param array<string, mixed> $addressOverride
     */
    private function collectQuoteFor(array $addressOverride): Quote
    {
        $quote = $this->newGuestQuote($addressOverride);
        $product = $this->seededProduct(self::TEST_PRODUCT_SKU);
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, 1)));

        return $this->collectAndSaveQuote($quote);
    }
}
