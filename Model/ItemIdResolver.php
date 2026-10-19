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
 * Turns a line's SKU into the item ID TaxCloud is told about.
 *
 * Both APIs cap the item ID at 50 characters and reject the WHOLE request when
 * one line exceeds it — v1 with "ItemID execeeds 50 characters", v3 with a 422
 * on `itemId: expected length <= 50`. Magento builds SKUs longer than that on
 * its own: a Dynamic SKU bundle appends every selection's SKU to its own, and
 * custom options append theirs.
 *
 * A SKU that fits is sent unchanged, so every existing order keeps matching
 * what TaxCloud recorded. A longer one is sent as a readable prefix, `~`, and a
 * fingerprint of the whole SKU — exactly 50 characters. Configurations that
 * share a prefix still differ in the fingerprint, which plain truncation would
 * lose.
 *
 * Nothing is stored. Magento copies the SKU verbatim from quote item to order
 * item, and credit-memo items read it from the order item, so every payload
 * for one order — lookup, capture, return, distributed refund, exempt
 * re-create — derives the same ID as long as each one asks this class.
 */
class ItemIdResolver
{
    /** TaxCloud's limit, in UTF-16 code units. */
    public const MAX_LENGTH = 50;

    /** Marks a shortened ID; rare in SKUs, accepted by both APIs. */
    public const SEPARATOR = '~';

    /** Hex characters of the fingerprint: 40 bits. */
    private const HASH_LENGTH = 10;

    /** What is left of MAX_LENGTH for the readable part. */
    private const PREFIX_LENGTH = self::MAX_LENGTH - self::HASH_LENGTH - 1;

    /**
     * Magento stores the SKU in a varchar(255) and runs MySQL without strict
     * mode, so a longer SKU is silently truncated on save. Fingerprinting only
     * what survives the save lets an in-memory quote item and the stored order
     * item agree.
     */
    private const SKU_COLUMN_LENGTH = 255;

    /**
     * The TaxCloud item ID for a line with this SKU.
     *
     * @param string|null $sku
     * @return string
     */
    public static function fromSku($sku)
    {
        $sku = (string) $sku;
        if (self::utf16Length($sku) <= self::MAX_LENGTH) {
            return $sku;
        }

        $prefix = '';
        $prefixLength = 0;
        foreach (mb_str_split($sku, 1, 'UTF-8') as $char) {
            $charLength = self::utf16Length($char);
            if ($prefixLength + $charLength > self::PREFIX_LENGTH) {
                break;
            }
            $prefix .= $char;
            $prefixLength += $charLength;
        }

        $fingerprint = substr(
            hash('sha1', mb_substr($sku, 0, self::SKU_COLUMN_LENGTH, 'UTF-8')),
            0,
            self::HASH_LENGTH
        );

        return $prefix . self::SEPARATOR . $fingerprint;
    }

    /**
     * Length as TaxCloud's v1 (.NET) service counts it. A character outside the
     * Basic Multilingual Plane is two units there and one character to v3, so
     * an ID that fits this measure fits both APIs.
     *
     * @param string $value
     * @return int
     */
    private static function utf16Length($value)
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
