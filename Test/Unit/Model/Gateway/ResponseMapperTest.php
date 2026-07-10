<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\CartItemResponseHandler;
use Taxcloud\Magento2\Model\Gateway\ResponseMapper;

/**
 * Covers the wire-normalization and exempt-state extraction pulled out of
 * Model\Api: array conversion of nested SOAP objects, the single-vs-array
 * collapse SOAP performs, and the StateAbbr/StateAbbreviation fallback.
 */
#[AllowMockObjectsWithoutExpectations]
class ResponseMapperTest extends TestCase
{
    private ResponseMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ResponseMapper(new CartItemResponseHandler(), new NullLogger());
    }

    public function testToArrayNormalizesNestedObjectGraph()
    {
        $response = new \stdClass();
        $response->LookupResult = new \stdClass();
        $response->LookupResult->ResponseType = 'OK';
        $response->LookupResult->CartItemsResponse = new \stdClass();
        $response->LookupResult->CartItemsResponse->CartItemResponse = [
            (object) ['CartItemIndex' => 0, 'TaxAmount' => 1.23],
        ];

        $arr = $this->mapper->toArray($response);

        $this->assertIsArray($arr);
        $this->assertSame('OK', $arr['LookupResult']['ResponseType']);
        $this->assertSame(
            ['CartItemIndex' => 0, 'TaxAmount' => 1.23],
            $arr['LookupResult']['CartItemsResponse']['CartItemResponse'][0]
        );
    }

    /**
     * Build a GetExemptCertificates-style response for one certificate.
     */
    private function certResponse(string $certId, array $stateAbbrs, bool $ok = true): \stdClass
    {
        $exemptStates = [];
        foreach ($stateAbbrs as $abbr) {
            $es = new \stdClass();
            $es->StateAbbr = $abbr;
            $exemptStates[] = $es;
        }
        $detail = new \stdClass();
        $detail->ExemptStates = new \stdClass();
        $detail->ExemptStates->ExemptState = $exemptStates;

        $cert = new \stdClass();
        $cert->CertificateID = $certId;
        $cert->Detail = $detail;

        $result = new \stdClass();
        $result->ResponseType = $ok ? 'OK' : 'Error';
        $result->ExemptCertificates = new \stdClass();
        $result->ExemptCertificates->ExemptionCertificate = [$cert];

        $response = new \stdClass();
        $response->GetExemptCertificatesResult = $result;
        return $response;
    }

    public function testExtractExemptStatesReturnsStatesForMatchingCert()
    {
        $response = $this->certResponse('cert-1', ['NY', 'NJ']);

        $this->assertSame(['NY', 'NJ'], $this->mapper->extractExemptStates($response, 'cert-1'));
    }

    public function testExtractExemptStatesReturnsEmptyOnNonOkResponse()
    {
        $response = $this->certResponse('cert-1', ['NY'], false);

        $this->assertSame([], $this->mapper->extractExemptStates($response, 'cert-1'));
    }

    public function testExtractExemptStatesReturnsEmptyWhenCertNotFound()
    {
        $response = $this->certResponse('cert-1', ['NY']);

        $this->assertSame([], $this->mapper->extractExemptStates($response, 'other-cert'));
    }

    public function testExtractExemptStatesHandlesSingleCertNotWrappedInArray()
    {
        $response = $this->certResponse('cert-1', ['CA']);
        // SOAP collapses a single cert to a bare object, not a one-element array.
        $response->GetExemptCertificatesResult->ExemptCertificates->ExemptionCertificate =
            $response->GetExemptCertificatesResult->ExemptCertificates->ExemptionCertificate[0];

        $this->assertSame(['CA'], $this->mapper->extractExemptStates($response, 'cert-1'));
    }

    public function testExtractExemptStatesHandlesSingleStateNotWrappedInArray()
    {
        $response = $this->certResponse('cert-1', ['TX']);
        $states = $response->GetExemptCertificatesResult->ExemptCertificates->ExemptionCertificate[0]
            ->Detail->ExemptStates;
        // Single exempt state collapses to a bare object too.
        $states->ExemptState = $states->ExemptState[0];

        $this->assertSame(['TX'], $this->mapper->extractExemptStates($response, 'cert-1'));
    }

    public function testApplyCartItemResponsesMapsProductAndShippingTax()
    {
        $cartItems = [
            0 => ['ItemID' => 'SKU-1'],
            1 => ['ItemID' => 'shipping'],
        ];
        $indexedItems = [0 => 'prod-code'];
        $result = ['product' => [], 'shipping' => 0];

        $cartItemResponse = [
            ['CartItemIndex' => 0, 'TaxAmount' => 0.83],
            ['CartItemIndex' => 1, 'TaxAmount' => 0.41],
        ];

        $this->mapper->applyCartItemResponses($cartItemResponse, $cartItems, $indexedItems, $result);

        $this->assertSame(0.83, $result['product']['prod-code']);
        $this->assertSame(0.41, $result['shipping']);
    }

    public function testExtractExemptStatesFallsBackToStateAbbreviation()
    {
        $es = new \stdClass();
        $es->StateAbbreviation = 'FL'; // no StateAbbr

        $detail = new \stdClass();
        $detail->ExemptStates = new \stdClass();
        $detail->ExemptStates->ExemptState = [$es];

        $cert = new \stdClass();
        $cert->CertificateID = 'cert-1';
        $cert->Detail = $detail;

        $result = new \stdClass();
        $result->ResponseType = 'OK';
        $result->ExemptCertificates = new \stdClass();
        $result->ExemptCertificates->ExemptionCertificate = [$cert];

        $response = new \stdClass();
        $response->GetExemptCertificatesResult = $result;

        $this->assertSame(['FL'], $this->mapper->extractExemptStates($response, 'cert-1'));
    }
}
