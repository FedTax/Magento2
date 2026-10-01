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
 *
 * redactText() is the defensive superset applied to log content leaving the
 * store in a diagnostics bundle. It cannot assume the log was written by the
 * current version — a line from before a redaction gap was closed is still in
 * the file — so it masks every credential shape any version could have
 * written: XML elements, print_r dumps, JSON members, HTTP auth headers and
 * bare Bearer tokens, plus any exact credential value the caller knows.
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
     * Key names whose values redactText() masks in JSON and print_r shapes.
     * Broader than SENSITIVE_KEYS: it also covers config-path names and token
     * fields, because text leaving the store is not only SOAP params.
     */
    private const TEXT_SENSITIVE_KEYS = [
        'apiLoginID', 'apiKey', 'api_id', 'api_key', 'rest_api_key', 'x-api-key',
        'token', 'access_token', 'accessToken', 'authToken', 'bearer', 'password',
    ];

    /**
     * Shortest known secret value substituted verbatim. Shorter values would
     * mask unrelated text (a one-character key would blank every such letter).
     */
    private const MIN_KNOWN_SECRET_LENGTH = 6;

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

    /**
     * Mask every credential shape in free text (a log line, a JSON document).
     *
     * @param string   $text
     * @param string[] $knownSecrets Exact credential values to mask wherever they appear
     * @return string
     */
    public static function redactText($text, array $knownSecrets = [])
    {
        $text = (string) $text;
        if ($text === '') {
            return $text;
        }

        // Exact values first, longest first, so a value that contains another
        // is masked whole rather than leaving a readable remainder.
        $secrets = [];
        foreach ($knownSecrets as $secret) {
            $secret = (string) $secret;
            foreach (array_unique([$secret, trim($secret)]) as $candidate) {
                if (strlen($candidate) >= self::MIN_KNOWN_SECRET_LENGTH) {
                    $secrets[$candidate] = strlen($candidate);
                }
            }
        }
        arsort($secrets);
        if ($secrets !== []) {
            $text = str_replace(array_keys($secrets), self::PLACEHOLDER, $text);
        }

        $redacted = self::redactXml($text);

        $keys = implode('|', array_map(static function ($key) {
            return preg_quote($key, '~');
        }, self::TEXT_SENSITIVE_KEYS));

        $patterns = [
            // JSON member: "apiKey": "value" (escaped quotes inside the value allowed)
            '~("(?:' . $keys . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"~i' => '$1"' . self::PLACEHOLDER . '"',
            // print_r / var_export: [apiKey] => value   or   'apiKey' => 'value'
            '~(\[(?:' . $keys . ')\]\s*=>\s*)[^\r\n]*~i' => '$1' . self::PLACEHOLDER,
            "~('(?:" . $keys . ")'\\s*=>\\s*)'(?:[^'\\\\]|\\\\.)*'~i" => "\$1'" . self::PLACEHOLDER . "'",
            // HTTP header: X-API-KEY: value
            '~(X-API-KEY\s*:\s*)[^\s,;"\']+~i' => '$1' . self::PLACEHOLDER,
            // Bearer tokens anywhere: Authorization headers, exception messages
            '#(Bearer\s+)[A-Za-z0-9\-._~+/]+=*#i' => '$1' . self::PLACEHOLDER,
            // Query-string style: apiKey=value
            '~((?:^|[?&\s])(?:' . $keys . ')=)[^&\s"\']+~i' => '$1' . self::PLACEHOLDER,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $next = preg_replace($pattern, $replacement, $redacted);
            if ($next === null) {
                return '[unloggable payload: redaction failed]';
            }
            $redacted = $next;
        }

        return $redacted;
    }
}
