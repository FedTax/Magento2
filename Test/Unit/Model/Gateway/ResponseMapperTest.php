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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * SOAP nests objects several levels deep and mixes arrays of objects in
     * between; every level must come back as a plain array.
     */
    public function testToArrayNormalizesDeeplyNestedAndMixedStructures()
    {
        $response = (object) [
            'Level1' => (object) [
                'Level2' => (object) [
                    'Level3' => (object) ['Leaf' => 'deep'],
                    'ListOfObjects' => [
                        (object) ['Id' => 1, 'Nested' => (object) ['Flag' => true]],
                        (object) ['Id' => 2, 'Nested' => (object) ['Flag' => false]],
                    ],
                ],
                'ArrayOfScalars' => ['a', 'b'],
            ],
        ];

        $arr = $this->mapper->toArray($response);

        $this->assertSame(
            [
                'Level1' => [
                    'Level2' => [
                        'Level3' => ['Leaf' => 'deep'],
                        'ListOfObjects' => [
                            ['Id' => 1, 'Nested' => ['Flag' => true]],
                            ['Id' => 2, 'Nested' => ['Flag' => false]],
                        ],
                    ],
                    'ArrayOfScalars' => ['a', 'b'],
                ],
            ],
            $arr,
            'every nested object level must normalize to a plain array'
        );
    }

    /**
     * An object nested inside a plain array (not just inside another object)
     * must still be converted — SOAP produces this for multi-item carts.
     */
    public function testToArrayNormalizesObjectsInsideArrays()
    {
        $arr = $this->mapper->toArray([
            'Items' => [(object) ['TaxAmount' => 1.23], (object) ['TaxAmount' => 4.56]],
        ]);

        $this->assertSame([
            'Items' => [['TaxAmount' => 1.23], ['TaxAmount' => 4.56]],
        ], $arr);
    }

    /**
     * @dataProvider scalarPassthroughProvider
     */
    #[DataProvider('scalarPassthroughProvider')]
    public function testToArrayPassesScalarsThroughUnchanged($value, string $message)
    {
        $this->assertSame($value, $this->mapper->toArray($value), $message);
    }

    public static function scalarPassthroughProvider(): array
    {
        return [
            'string' => ['OK', 'strings pass through'],
            'int' => [42, 'ints pass through'],
            'float' => [1.23, 'floats pass through'],
            'bool' => [true, 'bools pass through'],
            'null' => [null, 'null passes through'],
        ];
    }

    public function testToArrayNormalizesEmptyObjectToEmptyArray()
    {
        $this->assertSame(['Empty' => []], $this->mapper->toArray((object) ['Empty' => new \stdClass()]));
    }

    /**
     * Regression guard: the previous json_encode()/json_decode() round-trip
     * returned false for a payload containing invalid UTF-8, which json_decode()
     * then turned into null — silently discarding the whole response, which the
     * gateway read as a failed call. A latin-1 byte in a name or address is
     * enough to trigger it.
     */
    public function testToArraySurvivesInvalidUtf8InsteadOfDiscardingResponse()
    {
        $latin1Name = "caf\xE9";

        $arr = $this->mapper->toArray((object) [
            'VerifyAddressResult' => (object) ['City' => $latin1Name, 'Zip5' => '10001'],
        ]);

        $this->assertNull(
            json_decode(json_encode($arr), true),
            'precondition: this payload is exactly what the old round-trip collapsed to null'
        );
        $this->assertSame($latin1Name, $arr['VerifyAddressResult']['City']);
        $this->assertSame('10001', $arr['VerifyAddressResult']['Zip5']);
    }

    /**
     * The round-trip dropped zero fractions, so a whole-dollar or zero tax came
     * back as int. Amounts now keep the float the wire carried. Downstream does
     * arithmetic and (float) casts only, so totals are unaffected — but pin the
     * type so the change stays deliberate.
     */
    public function testToArrayPreservesWholeNumberFloatsAsFloats()
    {
        $arr = $this->mapper->toArray((object) [
            'Taxable' => (object) ['TaxAmount' => 2.0],
            'Exempt' => (object) ['TaxAmount' => 0.0],
        ]);

        $this->assertIsFloat($arr['Taxable']['TaxAmount'], 'whole-dollar tax stays float');
        $this->assertIsFloat($arr['Exempt']['TaxAmount'], 'zero tax stays float');
        $this->assertSame(2.0, $arr['Taxable']['TaxAmount']);
        $this->assertSame(0.0, $arr['Exempt']['TaxAmount']);
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
