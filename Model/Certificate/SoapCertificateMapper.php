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
 * Turns a v1 `GetExemptCertificates` response into {@see Certificate} objects.
 *
 * Two shapes of the SOAP response have to be absorbed here, both long-standing:
 * PHP's SoapClient collapses a single-element list into a bare object rather
 * than a one-element array, and TaxCloud spells the state abbreviation
 * `StateAbbr` in some responses and `StateAbbreviation` in others.
 *
 * v1 carries MORE about a certificate than v3 does — a tax id, a fully
 * populated exemption reason — so this mapper is the richer of the two. It
 * still only reports what the response actually contained.
 */
class SoapCertificateMapper
{
    /**
     * Map a raw `GetExemptCertificates` response.
     *
     * A non-OK response yields no certificates; distinguishing "none" from
     * "could not ask" is the caller's job, since only the caller knows whether
     * it is safe to treat an empty set as an answer.
     *
     * @param object|null $response Raw SOAP response
     * @return Certificate[]
     */
    public function fromListResponse($response): array
    {
        $result = $response->GetExemptCertificatesResult ?? null;
        if (!$result || ($result->ResponseType ?? '') !== 'OK') {
            return [];
        }

        $certificates = [];
        foreach ($this->asList($result->ExemptCertificates->ExemptionCertificate ?? []) as $raw) {
            $certificate = $this->fromCertificate($raw);
            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        return $certificates;
    }

    /**
     * @param object $raw One `ExemptionCertificate` node
     * @param string $customerId Identity the listing was requested for; v1 does
     *                           not echo it back on each certificate
     * @return Certificate|null Null when the node carries no usable identifier
     */
    public function fromCertificate($raw, string $customerId = ''): ?Certificate
    {
        $certificateId = (string) ($raw->CertificateID ?? '');
        if ($certificateId === '') {
            return null;
        }

        $detail = $raw->Detail ?? null;

        return new Certificate(
            $certificateId,
            $customerId,
            $this->states($detail),
            // v1 has no notion of a disabled certificate: a withdrawn one is
            // deleted outright, so anything the listing returns is live.
            false,
            (bool) ($detail->SinglePurchase ?? false),
            $this->detail($detail)
        );
    }

    /**
     * @param object|null $detail
     * @return string[]
     */
    private function states($detail): array
    {
        $states = [];
        foreach ($this->asList($detail->ExemptStates->ExemptState ?? []) as $exemptState) {
            // Both spellings occur; neither is more canonical than the other.
            $abbreviation = $exemptState->StateAbbr ?? $exemptState->StateAbbreviation ?? null;
            if (is_string($abbreviation) && strlen($abbreviation) === 2) {
                $states[] = $abbreviation;
            }
        }

        return $states;
    }

    /**
     * Descriptive detail, omitting anything the response did not carry.
     *
     * @param object|null $detail
     * @return array<string, string>
     */
    private function detail($detail): array
    {
        if (!$detail) {
            return [];
        }

        $purchaserName = trim(
            (string) ($detail->PurchaserFirstName ?? '') . ' ' . (string) ($detail->PurchaserLastName ?? '')
        );

        $address = array_filter([
            (string) ($detail->PurchaserAddress1 ?? ''),
            (string) ($detail->PurchaserCity ?? ''),
            (string) ($detail->PurchaserState ?? ''),
            (string) ($detail->PurchaserZip ?? ''),
        ], static function ($part) {
            return $part !== '';
        });

        // array_filter in the Certificate constructor drops empty values, so
        // absent detail stays absent rather than becoming an empty string.
        return [
            'reason' => (string) ($detail->PurchaserExemptionReason ?? ''),
            'reasonDescription' => (string) ($detail->PurchaserExemptionReasonValue ?? ''),
            'businessType' => (string) ($detail->PurchaserBusinessType ?? ''),
            'purchaserName' => $purchaserName,
            'purchaserAddress' => implode(', ', $address),
            'createdDate' => (string) ($detail->CreatedDate ?? ''),
            'taxId' => (string) ($detail->PurchaserTaxID->IDNumber ?? ''),
        ];
    }

    /**
     * SoapClient hands back a bare object where a one-element list was meant.
     *
     * @param mixed $value
     * @return array<int, mixed>
     */
    private function asList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }
}
