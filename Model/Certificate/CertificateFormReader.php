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
 * Reads a submitted certificate form: normalizes it, reports what is wrong with
 * it, and turns it into gateway data.
 *
 * Shared by the admin and storefront creation endpoints so a certificate means
 * the same thing whoever filled the form in, and so the enum values — which
 * both APIs reject outright when wrong — are stated once.
 *
 * This validates SHAPE, never truth. Whether the purchaser genuinely qualifies
 * for the exemption they are claiming is a legal question no software here can
 * answer: TaxCloud accepts a certificate carrying an invented tax id without
 * complaint, verified against the live API. Rejecting a malformed state
 * abbreviation is useful; implying the accepted ones have been checked would
 * not be.
 *
 * Nothing here carries the customer identity. That is resolved server-side from
 * the customer the request concerns, because identity is what ownership is
 * decided by — a submitted one would let a form choose whose exemptions it
 * creates.
 */
class CertificateFormReader
{
    /**
     * Exemption reasons both APIs accept. v1 and v3 agree on these spellings;
     * the pairs they disagree about (v1's combined religious/educational value)
     * are deliberately excluded rather than mapped, so a certificate never
     * records a reason the purchaser did not pick.
     */
    public const REASONS = [
        'FederalGovernment' => 'Federal Government',
        'StateOrLocalGovernment' => 'State or Local Government',
        'TribalGovernment' => 'Tribal Government',
        'ForeignDiplomat' => 'Foreign Diplomat',
        'CharitableOrganization' => 'Charitable Organization',
        'ReligiousOrganization' => 'Religious Organization',
        'EducationalOrganization' => 'Educational Organization',
        'Resale' => 'Resale',
        'AgriculturalProduction' => 'Agricultural Production',
        'IndustrialProductionOrManufacturing' => 'Industrial Production or Manufacturing',
        'DirectPayPermit' => 'Direct Pay Permit',
        'DirectMail' => 'Direct Mail',
        'Other' => 'Other',
    ];

    /**
     * TaxCloud's own guidance on filling a certificate in. Which reason or
     * business type applies is a tax question with a legal answer, and a
     * paraphrase here that was subtly wrong would be worse than a link.
     */
    public const GUIDANCE_URL = 'https://support.taxcloud.com/article/94-how-to-upload-an-exemption-certificate';

    /**
     * Business types both APIs accept.
     */
    public const BUSINESS_TYPES = [
        'AccommodationAndFoodServices' => 'Accommodation and Food Services',
        'AgriculturalForestryFishingHunting' => 'Agricultural, Forestry, Fishing, Hunting',
        'Construction' => 'Construction',
        'FinanceAndInsurance' => 'Finance and Insurance',
        'InformationPublishingAndCommunications' => 'Information, Publishing and Communications',
        'Manufacturing' => 'Manufacturing',
        'Mining' => 'Mining',
        'RealEstate' => 'Real Estate',
        'RentalAndLeasing' => 'Rental and Leasing',
        'RetailTrade' => 'Retail Trade',
        'TransportationAndWarehousing' => 'Transportation and Warehousing',
        'Utilities' => 'Utilities',
        'WholesaleTrade' => 'Wholesale Trade',
        'BusinessServices' => 'Business Services',
        'ProfessionalServices' => 'Professional Services',
        'EducationAndHealthCareServices' => 'Education and Health Care Services',
        'NonprofitOrganization' => 'Nonprofit Organization',
        'Government' => 'Government',
        'NotABusiness' => 'Not a Business',
        'Other' => 'Other',
    ];

    /**
     * Longer descriptions are rejected outright by v3 (verified live: 21
     * characters returns 422, 20 is accepted). The field itself is optional —
     * an empty value is accepted — but the key must always be sent.
     */
    public const REASON_DESCRIPTION_LIMIT = 20;

    /**
     * Normalize a submitted form into gateway data.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    public function read(array $submitted): array
    {
        $states = $submitted['states'] ?? [];
        if (is_string($states)) {
            $states = explode(',', $states);
        }

        $clean = [];
        foreach (is_array($states) ? $states : [] as $state) {
            $state = strtoupper(trim((string) $state));
            if (strlen($state) === 2) {
                $clean[] = $state;
            }
        }

        return [
            'states' => array_values(array_unique($clean)),
            'firstName' => $this->text($submitted, 'firstName'),
            'lastName' => $this->text($submitted, 'lastName'),
            'title' => $this->text($submitted, 'title'),
            'address1' => $this->text($submitted, 'address1'),
            'address2' => $this->text($submitted, 'address2'),
            'city' => $this->text($submitted, 'city'),
            'state' => strtoupper($this->text($submitted, 'state')),
            'zip' => $this->text($submitted, 'zip'),
            'businessType' => $this->text($submitted, 'businessType'),
            'businessTypeDescription' => $this->text($submitted, 'businessTypeDescription'),
            'reason' => $this->text($submitted, 'reason'),
            'reasonDescription' => $this->text($submitted, 'reasonDescription'),
        ];
    }

    /**
     * The first thing wrong with this form, or null when nothing is.
     *
     * One at a time on purpose: these are rejections the APIs would make
     * anyway, and reporting them individually keeps the message specific enough
     * to act on.
     *
     * @param array<string, mixed> $data Output of {@see read()}
     * @return string|null
     */
    public function firstProblem(array $data)
    {
        if ($data['states'] === []) {
            return (string) __('Choose at least one state this exemption applies in.');
        }

        foreach (['firstName', 'lastName', 'address1', 'city', 'state', 'zip'] as $required) {
            if ($data[$required] === '') {
                return (string) __('The purchaser\'s name and address are required.');
            }
        }

        if (!isset(self::REASONS[$data['reason']])) {
            return (string) __('Choose a reason for the exemption.');
        }

        if (!isset(self::BUSINESS_TYPES[$data['businessType']])) {
            return (string) __('Choose a business type.');
        }

        // Deliberately NOT required. Verified against the live v3 API: an empty
        // description is accepted (201), only the KEY must be present — omitting
        // it entirely is what returns 422. The cap below is real, though: 21
        // characters is rejected, 20 accepted.
        if (mb_strlen($data['reasonDescription']) > self::REASON_DESCRIPTION_LIMIT) {
            return (string) __(
                'Keep the exemption description to %1 characters or fewer.',
                self::REASON_DESCRIPTION_LIMIT
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $submitted
     * @param string $key
     * @return string
     */
    private function text(array $submitted, string $key): string
    {
        $value = $submitted[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
