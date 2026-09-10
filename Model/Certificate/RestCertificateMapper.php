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

namespace Taxcloud\Magento2\Model\Certificate;

/**
 * Turns a v3 exemption-certificate payload into a {@see Certificate}.
 *
 * Covered states are objects here — `states[].abbreviation`, per the published
 * `ExemptionCertificateExemptStatesResponse` schema — not the bare strings the
 * SOAP mapper produces from `StateAbbr`. Reading them as strings is precisely
 * the defect that made every v3 exemption silently fail before it was fixed.
 *
 * ABSENT, NOT INVENTED. v3 describes a certificate with less than v1 does, and
 * occasionally describes it wrongly:
 *
 *   - it carries no tax id at all, though the portal collects one and v1
 *     returns it;
 *   - it has been observed returning `"reason": "Unknown"` for a certificate
 *     v1 reports correctly as `Resale` — a value outside v3's own documented
 *     `reason` enum.
 *
 * Both are mapped to nothing rather than to a substitute. The temptation is to
 * default the reason to "Unknown" or "Other" and move on; that would render a
 * fabricated claim on a legal attestation. Showing less is correct. Showing
 * something the purchaser never asserted is not.
 */
class RestCertificateMapper
{
    /**
     * `reason` values that carry no information. v3 emits this for reasons it
     * cannot map, and it is not one of the values its own schema documents.
     */
    private const UNMAPPED_REASON = 'Unknown';

    /**
     * @param array<string, mixed> $body A v3 listing envelope
     * @return Certificate[]
     */
    public function fromListResponse(array $body): array
    {
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $certificates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $certificate = $this->fromCertificate($item);
            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        return $certificates;
    }

    /**
     * @param array<string, mixed> $raw One v3 certificate
     * @return Certificate|null Null when the payload carries no usable identifier
     */
    public function fromCertificate(array $raw): ?Certificate
    {
        $certificateId = $raw['certificateId'] ?? null;
        if (!is_string($certificateId) || $certificateId === '') {
            return null;
        }

        return new Certificate(
            $certificateId,
            (string) ($raw['customerId'] ?? ''),
            $this->states($raw['states'] ?? []),
            !empty($raw['disabledAt']),
            !empty($raw['singlePurchase']),
            $this->detail($raw)
        );
    }

    /**
     * @param mixed $states
     * @return string[]
     */
    private function states($states): array
    {
        if (!is_array($states)) {
            return [];
        }

        $abbreviations = [];
        foreach ($states as $state) {
            if (!is_array($state)) {
                continue;
            }
            $abbreviation = $state['abbreviation'] ?? null;
            if (is_string($abbreviation) && strlen($abbreviation) === 2) {
                $abbreviations[] = $abbreviation;
            }
        }

        return $abbreviations;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private function detail(array $raw): array
    {
        $reason = $raw['reason'] ?? '';
        if (!is_string($reason) || $reason === self::UNMAPPED_REASON) {
            // v3 could not map the stored reason. It did not become "Unknown";
            // v3 simply cannot tell us what it is.
            $reason = '';
        }

        $address = [];
        if (is_array($raw['address'] ?? null)) {
            $address = array_filter([
                (string) ($raw['address']['line1'] ?? ''),
                (string) ($raw['address']['city'] ?? ''),
                (string) ($raw['address']['state'] ?? ''),
                (string) ($raw['address']['zip'] ?? ''),
            ], static function ($part) {
                return $part !== '';
            });
        }

        // No taxId key: v3 has no field for it. Absent, not empty — the
        // certificate may well carry one that this transport cannot show.
        return [
            'reason' => $reason,
            'reasonDescription' => (string) ($raw['reasonDescription'] ?? ''),
            'businessType' => (string) ($raw['customerBusinessType'] ?? ''),
            'purchaserName' => (string) ($raw['customerName'] ?? ''),
            'purchaserAddress' => implode(', ', $address),
            'createdDate' => (string) ($raw['createdDate'] ?? ''),
        ];
    }
}
