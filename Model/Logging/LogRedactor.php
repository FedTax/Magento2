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

namespace Taxcloud\Magento2\Model\Logging;

/**
 * Masks TaxCloud credentials in log payloads.
 *
 * Two shapes reach the log: the PHP params arrays built for the SoapClient,
 * and (in Advanced mode) the raw SOAP XML captured via the client's trace
 * buffers. Both carry apiLoginID/apiKey; keys and element names are preserved
 * so operators can confirm the fields were sent, values are replaced.
 */
class LogRedactor
{
    /**
     * Placeholder substituted for credential values in log output.
     */
    public const PLACEHOLDER = '***REDACTED***';

    /**
     * Params-array keys whose values are masked.
     */
    private const SENSITIVE_KEYS = ['apiLoginID', 'apiKey'];

    /**
     * Return a copy of a SOAP params array with credential values masked.
     *
     * @param array $params
     * @return array
     */
    public static function redactArray(array $params)
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (array_key_exists($key, $params)) {
                $params[$key] = self::PLACEHOLDER;
            }
        }
        return $params;
    }

    /**
     * Mask credential element contents in a SOAP XML (or other text) payload.
     *
     * Matches <apiLoginID>…</apiLoginID> and <apiKey>…</apiKey> including
     * namespace prefixes and attributes, case-insensitively.
     *
     * @param string $payload
     * @return string
     */
    public static function redactXml($payload)
    {
        $names = implode('|', self::SENSITIVE_KEYS);
        $redacted = preg_replace(
            '~(<(?:[\w.-]+:)?(' . $names . ')(?:\s[^>]*)?>).*?(</(?:[\w.-]+:)?\2\s*>)~is',
            '$1' . self::PLACEHOLDER . '$3',
            $payload
        );

        // preg_replace returns null on engine failure (e.g. backtrack limit on a
        // pathological payload); never let that leak the unredacted original.
        return $redacted ?? '[unloggable payload: redaction failed]';
    }
}
