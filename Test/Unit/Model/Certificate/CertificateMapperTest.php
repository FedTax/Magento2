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

namespace Taxcloud\Magento2\Test\Unit\Model\Certificate;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\RestCertificateMapper;
use Taxcloud\Magento2\Model\Certificate\SoapCertificateMapper;

/**
 * Both mappers must land on the same {@see Certificate} for the same
 * certificate, and neither may invent detail its transport did not supply.
 *
 * The fixtures here are not imagined. They are the shapes observed live
 * against the TaxCloud sandbox on 2026-08-19 — including v3's `states` as
 * objects (the defect that silently disabled every v3 exemption) and its
 * `reason: "Unknown"` for a certificate v1 reports as `Resale`.
 */
class CertificateMapperTest extends TestCase
{
    private const CERT_ID = '8e5cf42d-d89b-f111-93b3-ef10eae71d6e';

    // ─── v3 ──────────────────────────────────────────────────────────────

    /**
     * @param string[] $states
     * @return array<string, mixed>
     */
    private function v3Certificate(array $states = ['TX'], array $overrides = []): array
    {
        return array_merge([
            'certificateId' => self::CERT_ID,
            'customerId' => '2',
            'customerName' => 'Exempt Customer',
            'customerBusinessType' => 'WholesaleTrade',
            'reason' => 'Resale',
            'reasonDescription' => 'Resale',
            'address' => ['line1' => '1100 Congress Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
            'states' => array_map(static function ($s) {
                return ['abbreviation' => $s];
            }, $states),
            'disabledAt' => null,
            'singlePurchase' => false,
            'createdDate' => '2026-08-19T14:09:00Z',
        ], $overrides);
    }

    public function testRestMapperReadsStatesAsObjects()
    {
        $mapper = new RestCertificateMapper();
        $certificate = $mapper->fromCertificate($this->v3Certificate(['TX', 'NY']));

        $this->assertNotNull($certificate);
        $this->assertSame(['TX', 'NY'], $certificate->getStates());
        $this->assertTrue($certificate->covers('TX'));
        $this->assertFalse($certificate->covers('CA'));
    }

    public function testRestMapperDiscardsUnusableStateEntriesButKeepsTheRest()
    {
        $mapper = new RestCertificateMapper();
        $certificate = $mapper->fromCertificate($this->v3Certificate([], [
            'states' => [
                ['abbreviation' => 'TX'],
                ['abbreviation' => null],
                ['abbreviation' => 'TEXAS'],
                [],
                'NY',
                ['abbreviation' => 'CT'],
            ],
        ]));

        $this->assertSame(['TX', 'CT'], $certificate->getStates());
        // A bare string is the pre-fix shape; it is not a v3 state entry.
        $this->assertFalse($certificate->covers('NY'));
    }

    /**
     * The heart of "absent, not invented": v3 answers `Unknown` for a reason it
     * cannot map. Mapping that to a literal reason would render a claim the
     * purchaser never made.
     */
    public function testRestMapperTreatsUnknownReasonAsAbsent()
    {
        $mapper = new RestCertificateMapper();
        $certificate = $mapper->fromCertificate($this->v3Certificate(['TX'], ['reason' => 'Unknown']));

        $this->assertNull($certificate->getDetailValue('reason'));
        $this->assertArrayNotHasKey('reason', $certificate->getDetail());
    }

    public function testRestMapperReportsNoTaxIdBecauseV3HasNoField()
    {
        $mapper = new RestCertificateMapper();
        $certificate = $mapper->fromCertificate($this->v3Certificate());

        $this->assertNull(
            $certificate->getDetailValue('taxId'),
            'v3 carries no tax id; absent must not be reported as an empty value'
        );
    }

    public function testRestMapperMarksDisabledAndSinglePurchase()
    {
        $mapper = new RestCertificateMapper();

        $disabled = $mapper->fromCertificate(
            $this->v3Certificate(['TX'], ['disabledAt' => '2026-01-01T00:00:00Z'])
        );
        $this->assertTrue($disabled->isDisabled());
        $this->assertFalse($disabled->covers('TX'), 'a disabled certificate covers nothing');

        $single = $mapper->fromCertificate($this->v3Certificate(['TX'], ['singlePurchase' => true]));
        $this->assertTrue($single->isSinglePurchase());
        $this->assertFalse($single->covers('TX'), 'a single-purchase certificate is never eligible');
    }

    public function testRestMapperReadsListingEnvelopeAndSkipsJunk()
    {
        $mapper = new RestCertificateMapper();
        $certificates = $mapper->fromListResponse([
            'items' => [
                $this->v3Certificate(['TX']),
                ['customerId' => '2'],
                'not-an-object',
                $this->v3Certificate(['NY'], ['certificateId' => 'second-cert']),
            ],
        ]);

        $this->assertCount(2, $certificates);
        $this->assertSame(self::CERT_ID, $certificates[0]->getCertificateId());
        $this->assertSame('second-cert', $certificates[1]->getCertificateId());
    }

    // ─── v1 SOAP ─────────────────────────────────────────────────────────

    /**
     * @param string[] $states
     */
    private function soapResponse(array $states = ['TX'], array $overrides = []): \stdClass
    {
        $exemptStates = [];
        foreach ($states as $abbreviation) {
            $state = new \stdClass();
            $state->StateAbbr = $abbreviation;
            $state->ReasonForExemption = 'Resale';
            $exemptStates[] = $state;
        }

        $detail = new \stdClass();
        $detail->ExemptStates = new \stdClass();
        $detail->ExemptStates->ExemptState = $exemptStates;
        $detail->SinglePurchase = $overrides['SinglePurchase'] ?? false;
        $detail->PurchaserFirstName = 'Exempt';
        $detail->PurchaserLastName = 'Customer';
        $detail->PurchaserAddress1 = '1100 Congress Ave';
        $detail->PurchaserCity = 'Austin';
        $detail->PurchaserState = 'TX';
        $detail->PurchaserZip = '78701';
        $detail->PurchaserBusinessType = 'WholesaleTrade';
        $detail->PurchaserExemptionReason = 'Resale';
        $detail->PurchaserTaxID = new \stdClass();
        $detail->PurchaserTaxID->IDNumber = '**-***4567';

        $certificate = new \stdClass();
        $certificate->CertificateID = self::CERT_ID;
        $certificate->Detail = $detail;

        $result = new \stdClass();
        $result->ResponseType = $overrides['ResponseType'] ?? 'OK';
        $result->ExemptCertificates = new \stdClass();
        $result->ExemptCertificates->ExemptionCertificate =
            $overrides['single'] ?? false ? $certificate : [$certificate];

        $response = new \stdClass();
        $response->GetExemptCertificatesResult = $result;

        return $response;
    }

    public function testSoapMapperReadsStateAbbr()
    {
        $mapper = new SoapCertificateMapper();
        $certificates = $mapper->fromListResponse($this->soapResponse(['TX', 'NY']));

        $this->assertCount(1, $certificates);
        $this->assertSame(['TX', 'NY'], $certificates[0]->getStates());
        $this->assertTrue($certificates[0]->covers('TX'));
    }

    /**
     * SoapClient collapses a one-element list into a bare object.
     */
    public function testSoapMapperHandlesSingleCertificateObject()
    {
        $mapper = new SoapCertificateMapper();
        $certificates = $mapper->fromListResponse($this->soapResponse(['TX'], ['single' => true]));

        $this->assertCount(1, $certificates);
        $this->assertSame(self::CERT_ID, $certificates[0]->getCertificateId());
    }

    public function testSoapMapperAcceptsEitherStateSpelling()
    {
        $mapper = new SoapCertificateMapper();
        $response = $this->soapResponse(['TX']);
        $alternate = new \stdClass();
        $alternate->StateAbbreviation = 'NY';
        $response->GetExemptCertificatesResult
            ->ExemptCertificates->ExemptionCertificate[0]
            ->Detail->ExemptStates->ExemptState[] = $alternate;

        $certificates = $mapper->fromListResponse($response);

        $this->assertSame(['TX', 'NY'], $certificates[0]->getStates());
    }

    public function testSoapMapperCarriesTaxIdThatV3Cannot()
    {
        $mapper = new SoapCertificateMapper();
        $certificates = $mapper->fromListResponse($this->soapResponse());

        $this->assertSame('**-***4567', $certificates[0]->getDetailValue('taxId'));
        $this->assertSame('Resale', $certificates[0]->getDetailValue('reason'));
    }

    public function testSoapMapperReturnsNothingForNonOkResponse()
    {
        $mapper = new SoapCertificateMapper();

        $this->assertSame([], $mapper->fromListResponse($this->soapResponse(['TX'], ['ResponseType' => 'Error'])));
        $this->assertSame([], $mapper->fromListResponse(null));
    }

    // ─── the two agree ───────────────────────────────────────────────────

    /**
     * The same certificate through either transport must agree on everything
     * exemption decisions turn on. They are allowed to differ only in detail
     * v3 cannot carry.
     */
    public function testBothTransportsAgreeOnTheDecisionFields()
    {
        $viaRest = (new RestCertificateMapper())->fromCertificate($this->v3Certificate(['TX']));
        $viaSoap = (new SoapCertificateMapper())->fromListResponse($this->soapResponse(['TX']))[0];

        $this->assertSame($viaRest->getCertificateId(), $viaSoap->getCertificateId());
        $this->assertSame($viaRest->getStates(), $viaSoap->getStates());
        $this->assertSame($viaRest->isDisabled(), $viaSoap->isDisabled());
        $this->assertSame($viaRest->isSinglePurchase(), $viaSoap->isSinglePurchase());
        $this->assertSame($viaRest->covers('TX'), $viaSoap->covers('TX'));

        // Where they legitimately differ: v1 has the tax id, v3 has no field.
        $this->assertNotNull($viaSoap->getDetailValue('taxId'));
        $this->assertNull($viaRest->getDetailValue('taxId'));
    }
}
