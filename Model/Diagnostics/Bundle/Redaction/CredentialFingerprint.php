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
 * Describes a credential without revealing it.
 *
 * A diagnostics bundle leaves the merchant's control the moment it is
 * generated, so no credential value is ever written to one. What a support
 * engineer actually needs from a credential is almost never the value itself:
 * it is "is one set", "is it the one TaxCloud issued" (length, last four, a
 * hash prefix comparable across stores and against a value the merchant reads
 * out from their dashboard), and — the most common real credential bug — "was
 * it pasted with invisible whitespace or a line break".
 */
class CredentialFingerprint
{
    /**
     * Hex characters of the SHA-256 digest recorded. Enough to tell two values
     * apart with certainty in practice, far too few to brute-force a 32+
     * character key from.
     */
    public const HASH_PREFIX_LENGTH = 12;

    /**
     * Shortest value whose last four characters are recorded.
     */
    public const MIN_LENGTH_FOR_LAST4 = 8;

    /**
     * Fingerprint one credential value.
     *
     * @param string|null $value Plaintext credential (decrypted where stored encrypted)
     * @return array{set: bool, length: int, last4: string|null, sha256_prefix: string|null,
     *               leading_whitespace: bool, trailing_whitespace: bool, embedded_newline: bool,
     *               non_ascii: bool}
     */
    public function fingerprint(?string $value): array
    {
        if ($value === null || $value === '') {
            return [
                'set' => false,
                'length' => 0,
                'last4' => null,
                'sha256_prefix' => null,
                'leading_whitespace' => false,
                'trailing_whitespace' => false,
                'embedded_newline' => false,
                'non_ascii' => false,
            ];
        }

        $length = strlen($value);

        return [
            'set' => true,
            'length' => $length,
            // Withheld for values under eight characters, where four would be
            // half the secret or more. TaxCloud-issued values are all longer.
            'last4' => $length >= self::MIN_LENGTH_FOR_LAST4 ? substr($value, -4) : null,
            'sha256_prefix' => substr(hash('sha256', $value), 0, self::HASH_PREFIX_LENGTH),
            'leading_whitespace' => (bool) preg_match('/^\s/u', $value),
            'trailing_whitespace' => (bool) preg_match('/\s$/u', $value),
            'embedded_newline' => strpbrk(trim($value), "\r\n") !== false,
            'non_ascii' => (bool) preg_match('/[^\x20-\x7E]/', trim($value)),
        ];
    }

    /**
     * Human-readable anomalies in a fingerprint, for summary.md.
     *
     * @param array $fingerprint As returned by fingerprint()
     * @return string[]
     */
    public function anomalies(array $fingerprint): array
    {
        $anomalies = [];
        if (!empty($fingerprint['leading_whitespace'])) {
            $anomalies[] = 'leading whitespace';
        }
        if (!empty($fingerprint['trailing_whitespace'])) {
            $anomalies[] = 'trailing whitespace';
        }
        if (!empty($fingerprint['embedded_newline'])) {
            $anomalies[] = 'embedded line break';
        }
        if (!empty($fingerprint['non_ascii'])) {
            $anomalies[] = 'non-printable or non-ASCII characters';
        }

        return $anomalies;
    }
}
