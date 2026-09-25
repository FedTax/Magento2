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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Address;

/**
 * Estimate addresses: a quote destination with a region and postal code but
 * no street and/or city — what Magento's cart-page shipping estimator saves.
 *
 * TaxCloud refuses such an address as sent (v3 requires non-empty line1 and
 * city, SOAP requires a city), but prices by state and ZIP (refined by ZIP+4)
 * and ignores the street and city text. Filling the missing fields with a
 * fixed placeholder therefore returns the ZIP-level rate.
 *
 * Used only by quote lookups. Order-side destination builders never call
 * fill(): an order is always filed against its real address.
 */
class EstimateAddress
{
    /**
     * Stands in for a missing street line or city in an estimate lookup.
     */
    public const PLACEHOLDER = 'ESTIMATE';

    /**
     * Logged by a lookup that is sent as an estimate.
     */
    public const LOG_MESSAGE = 'Estimate address (no street and/or city): sending a ZIP-level lookup';

    /**
     * Whether a quote address lacks a city or a first street line.
     *
     * @param \Magento\Framework\DataObject $address Quote address
     * @return bool
     */
    public static function isPartial($address)
    {
        return self::firstStreetLine($address) === '' || trim((string) $address->getCity()) === '';
    }

    /**
     * Fill a v1-shaped destination's missing Address1 and/or City with the
     * placeholder. Every other key is returned unchanged.
     *
     * @param array $destination v1 keys: Address1/Address2/City/State/Zip5/Zip4 (optionally Country/PostalCode)
     * @return array
     */
    public static function fill(array $destination)
    {
        foreach (['Address1', 'City'] as $key) {
            if (trim((string) ($destination[$key] ?? '')) === '') {
                $destination[$key] = self::PLACEHOLDER;
            }
        }

        return $destination;
    }

    /**
     * Whether a destination carries the placeholder, in either the v1
     * (Address1/City) or the v3 (line1/city) shape.
     *
     * @param array $destination
     * @return bool
     */
    public static function isEstimate(array $destination)
    {
        foreach (['Address1', 'City', 'line1', 'city'] as $key) {
            if (($destination[$key] ?? null) === self::PLACEHOLDER) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Magento\Framework\DataObject $address
     * @return string
     */
    private static function firstStreetLine($address)
    {
        $street = $address->getStreet();
        $line = is_array($street) ? ($street[0] ?? '') : $street;

        return trim((string) $line);
    }
}
