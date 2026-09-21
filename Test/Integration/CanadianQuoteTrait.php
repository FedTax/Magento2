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

namespace Taxcloud\Magento2\Test\Integration;

use Magento\Directory\Model\RegionFactory;

/**
 * Shared setup for the Canadian-tax integration tests: a Canadian ship-to
 * address, the two settings Canadian pricing needs (V3 REST and the opt-in),
 * and the Magento-side permission to ship there at all.
 *
 * The seeded store mirrors production — SOAP, Canadian tax off, US-only
 * shipping — so each of these is switched on per test and restored by
 * {@see IntegrationTestCase::tearDown()}.
 *
 * @method void setScopedConfig(string $path, ?string $value, string $scopeType = 'default', int $scopeId = 0)
 */
trait CanadianQuoteTrait
{
    /** Seeded price of test-product, so a tax assertion can be arithmetic. */
    private const PRODUCT_PRICE = 10.0;

    /** Seeded flat-rate shipping price. */
    private const SHIPPING_PRICE = 5.0;

    /**
     * Toronto City Hall — Ontario, so the rate is province-driven and the
     * postal code is a real one.
     *
     * The postal code is deliberately given as the customer would type it,
     * uppercase with a space; tests that care about normalization override it.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function canadianAddress(array $override = []): array
    {
        return array_merge([
            'street' => '100 Queen St W',
            'city' => 'Toronto',
            'country_id' => 'CA',
            'region_id' => $this->regionId('ON'),
            'region' => 'Ontario',
            'postcode' => 'M5H 2N2',
        ], $override);
    }

    /**
     * Put the default scope on the v3 transport, which Canadian tax requires.
     */
    protected function useRestTransport(): void
    {
        $this->setScopedConfig('tax/taxcloud_settings/api_type', 'rest');
    }

    /**
     * Turn Canadian tax on or off at the default scope.
     */
    protected function setCanadaTax(bool $enabled): void
    {
        $this->setScopedConfig('tax/taxcloud_settings/canada_tax_enabled', $enabled ? '1' : '0');
    }

    /**
     * Magento refuses a shipping country it is not configured to allow, which
     * would fail a Canadian checkout before TaxCloud is ever consulted.
     */
    protected function allowCanadaAsAShippingCountry(): void
    {
        $this->setScopedConfig('general/country/allow', 'US,CA');
    }

    /**
     * Directory id of a province, looked up rather than hard-coded: region ids
     * are install data, and a fixed number silently means a different region on
     * another install.
     */
    protected function regionId(string $code, string $countryId = 'CA'): int
    {
        $region = $this->get(RegionFactory::class)->create()->loadByCode($code, $countryId);
        $id = (int) $region->getId();
        if ($id === 0) {
            throw new \RuntimeException(sprintf('No directory region %s/%s in this install.', $countryId, $code));
        }

        return $id;
    }
}
