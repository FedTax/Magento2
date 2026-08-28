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
 * One TaxCloud exemption certificate, as the module understands it — the same
 * shape whichever API supplied it.
 *
 * The two APIs do not describe a certificate identically, and v3 describes it
 * with less: it carries no tax id at all, and has been observed returning
 * `reason: "Unknown"` for a certificate v1 reports correctly as `Resale` —
 * a value outside v3's own documented enum.
 *
 * So detail here is deliberately OPTIONAL, and the mappers are required to
 * leave a field absent rather than substitute for it. A REST store shows less
 * about a certificate than a SOAP store; neither shows something untrue about
 * it. Rendering "Unknown" as though the purchaser had claimed it would put
 * words in the mouth of a legal attestation.
 *
 * The four fields that are NOT optional — identifier, customer identity,
 * covered states, disabled — are the ones exemption decisions turn on, and
 * both transports supply all four.
 */
class Certificate
{
    /**
     * @var string
     */
    private $certificateId;

    /**
     * @var string
     */
    private $customerId;

    /**
     * @var string[] Two-letter state abbreviations
     */
    private $states;

    /**
     * @var bool
     */
    private $disabled;

    /**
     * @var bool
     */
    private $singlePurchase;

    /**
     * Optional descriptive detail. An absent key means the supplying transport
     * did not carry a usable value.
     *
     * @var array<string, string>
     */
    private $detail;

    /**
     * @param string $certificateId
     * @param string $customerId TaxCloud customer identity this is filed under
     * @param string[] $states Two-letter covered state abbreviations
     * @param bool $disabled
     * @param bool $singlePurchase
     * @param array<string, string> $detail Optional: reason, purchaserName,
     *        purchaserAddress, createdDate, taxId. Omit what is not known —
     *        never pass a placeholder.
     */
    public function __construct(
        string $certificateId,
        string $customerId,
        array $states = [],
        bool $disabled = false,
        bool $singlePurchase = false,
        array $detail = []
    ) {
        $this->certificateId = $certificateId;
        $this->customerId = $customerId;
        $this->states = array_values(array_filter($states, static function ($state) {
            return is_string($state) && strlen($state) === 2;
        }));
        $this->disabled = $disabled;
        $this->singlePurchase = $singlePurchase;
        $this->detail = array_filter($detail, static function ($value) {
            return is_string($value) && $value !== '';
        });
    }

    /**
     * @return string
     */
    public function getCertificateId(): string
    {
        return $this->certificateId;
    }

    /**
     * The TaxCloud customer identity this certificate is filed under — not
     * necessarily a Magento customer id.
     *
     * @return string
     */
    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    /**
     * @return string[] Two-letter state abbreviations
     */
    public function getStates(): array
    {
        return $this->states;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * @return bool
     */
    public function isSinglePurchase(): bool
    {
        return $this->singlePurchase;
    }

    /**
     * Whether this certificate may exempt a delivery to the given state.
     *
     * Disabled and single-purchase certificates never cover anything: the
     * first has been withdrawn, and the second was issued for one order the
     * module has no way to identify.
     *
     * @param string $state Two-letter destination state abbreviation
     * @return bool
     */
    public function covers(string $state): bool
    {
        if ($this->disabled || $this->singlePurchase) {
            return false;
        }

        return in_array($state, $this->states, true);
    }

    /**
     * Optional descriptive detail. Absent keys mean the supplying transport
     * did not carry a usable value — never that the certificate says nothing.
     *
     * @return array<string, string>
     */
    public function getDetail(): array
    {
        return $this->detail;
    }

    /**
     * @param string $key
     * @return string|null Null when this transport did not supply the value
     */
    public function getDetailValue(string $key): ?string
    {
        return $this->detail[$key] ?? null;
    }

    /**
     * The record kept on an order that this certificate exempted: what the
     * certificate said at the time of the sale.
     *
     * Snapshotted rather than re-read because TaxCloud stores neither the
     * certificate document nor an expiry, and a certificate can be deleted
     * outright — so the live record may not answer, years later, why a sale
     * was untaxed.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return array_merge(
            [
                'certificateId' => $this->certificateId,
                'customerId' => $this->customerId,
                'states' => $this->states,
            ],
            $this->detail
        );
    }
}
