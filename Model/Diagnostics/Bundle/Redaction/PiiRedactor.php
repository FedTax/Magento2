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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction;

/**
 * Masks customer-identifying details when the merchant asks for it.
 *
 * Off by default: a bundle is most useful when the reported order can be
 * reproduced exactly, and the merchant is told plainly in the dialog that the
 * bundle carries customer order data. When it is on, the details that identify
 * a person — names, street lines, email addresses, phone numbers — are masked
 * everywhere, including inside log payloads. City, state, ZIP and TIC are
 * deliberately preserved: they drive the tax calculation, and a bundle without
 * them could no longer answer the question it exists for.
 *
 * Masked values are replaced with an explicit marker, never removed, so the
 * reader can tell "masked" from "not present".
 */
class PiiRedactor
{
    /**
     * Marker substituted for a masked value.
     */
    public const MARKER = '***MASKED***';

    /**
     * Key-name pattern (case-insensitive, whole key) of fields holding a
     * person's name, street, email or phone, across the shapes the extension
     * writes: Magento address/order fields, SOAP v1 params, v3 REST payloads.
     */
    private const KEY_PATTERN = '(?:[A-Za-z_]*first_?name|[A-Za-z_]*last_?name|[A-Za-z_]*middle_?name'
        . '|full_?name|customer_?name|purchaser_?name|prefix|suffix'
        . '|street(?:_?line)?_?\d*|line[12]|address_?[12]|address_?line_?[12]'
        . '|[A-Za-z_]*email(?:_?address)?|telephone|[A-Za-z_]*phone(?:_?number)?|fax)';

    /**
     * Email addresses, anywhere in text.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * Mask PII in free text: log lines, JSON documents, print_r dumps, XML.
     *
     * @param string $text
     * @return string
     */
    public function maskText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $key = self::KEY_PATTERN;
        $marker = self::MARKER;
        $patterns = [
            // JSON array member: "street": ["1 Main St", "Apt 2"]
            '/("' . $key . '"\s*:\s*)\[[^\]]*\]/i' => '$1["' . $marker . '"]',
            // JSON string member: "firstname": "Jane"
            '/("' . $key . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"' . $marker . '"',
            // print_r: [Address1] => 1 Main St
            '/(\[' . $key . '\]\s*=>\s*)(?!Array\b)[^\r\n]*/i' => '$1' . $marker,
            // XML element: <Address1>1 Main St</Address1>
            '/(<(?:[\w.\-]+:)?(' . $key . ')(?:\s[^>]*)?>)[^<]*(<\/(?:[\w.\-]+:)?\2\s*>)/i' => '$1' . $marker . '$3',
            // Magento log prose: "email=jane@example.com" is caught below; key=value pairs here
            '/(\b' . $key . '=)[^&\s,;"\']+/i' => '$1' . $marker,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $next = preg_replace($pattern, $replacement, $text);
            if ($next === null) {
                // Never fall back to the unmasked original.
                return '[unexportable content: masking failed]';
            }
            $text = $next;
        }

        return (string) preg_replace(self::EMAIL_PATTERN, $marker, $text);
    }

    /**
     * Mask PII in structured data, by key, recursively.
     *
     * @param array $data
     * @return array
     */
    public function maskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isPiiKey($key)) {
                $data[$key] = $value === null || $value === '' ? $value : self::MARKER;
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->maskArray($value);
            } elseif (is_string($value)) {
                $data[$key] = (string) preg_replace(self::EMAIL_PATTERN, self::MARKER, $value);
            }
        }

        return $data;
    }

    /**
     * Whether a field name holds customer PII.
     *
     * @param string $key
     * @return bool
     */
    public function isPiiKey(string $key): bool
    {
        return (bool) preg_match('/^' . self::KEY_PATTERN . '$/i', $key);
    }
}
