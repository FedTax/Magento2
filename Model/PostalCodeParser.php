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

namespace Taxcloud\Magento2\Model;

/**
 * Postal Code Parser Utility
 * 
 * Handles parsing of US ZIP codes in various formats:
 * - 55057 (5 digits only)
 * - 55057-1616 (ZIP+4 with hyphen)
 * - 55057+1616 (ZIP+4 with plus sign)
 * - 55057 1616 (ZIP+4 with space)
 */
class PostalCodeParser
{
    /**
     * Parse a postal code string into Zip5 and Zip4 components
     * 
     * @param string|null $postcode The postal code to parse
     * @return array Array with 'Zip5' and 'Zip4' keys
     */
    public static function parse($postcode)
    {
        if (empty($postcode)) {
            return [
                'Zip5' => null,
                'Zip4' => null
            ];
        }

        // Extract only digits from the postal code
        $digits = preg_replace('/[^0-9]/', '', $postcode);
        
        return [
            'Zip5' => substr($digits, 0, 5),
            'Zip4' => strlen($digits) >= 9 ? substr($digits, 5, 4) : null
        ];
    }

    /**
     * Validate if a parsed ZIP code is valid for TaxCloud API
     * 
     * @param array $parsedZip Array with 'Zip5' and 'Zip4' keys
     * @return bool True if valid, false otherwise
     */
    public static function isValid($parsedZip)
    {
        // Zip5 must be exactly 5 digits
        if (!isset($parsedZip['Zip5']) || strlen($parsedZip['Zip5']) !== 5 || !ctype_digit($parsedZip['Zip5'])) {
            return false;
        }

        // Zip4 must be either null or exactly 4 digits
        if (isset($parsedZip['Zip4']) && $parsedZip['Zip4'] !== null) {
            if (strlen($parsedZip['Zip4']) !== 4 || !ctype_digit($parsedZip['Zip4'])) {
                return false;
            }
        }

        return true;
    }
}
