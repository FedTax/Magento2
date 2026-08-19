<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestExemptionValidator;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;

/**
 * v3 exemption validation: state coverage from the certificate listing,
 * disabled certificates, cursor pagination, fail-closed fetches, and the
 * per-(customer, certificate, account) cache key discipline.
 */
#[AllowMockObjectsWithoutExpectations]
class RestExemptionValidatorTest extends TestCase
{
    private const STORE_ID = 3;
    private const CONN = 'conn-uuid-1';

    /**
     * @var RestClient&\PHPUnit\Framework\MockObject\MockObject
     */
    private $restClient;

    /**
     * @var FrontendInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheType;

    /**
     * @var array<string, string> Cache backing store: key => serialized data
     */
    private $cacheStore = [];

    private function validator(): RestExemptionValidator
    {
        $this->restClient = $this->createMock(RestClient::class);

        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('getRestConnectionId')->with(self::STORE_ID)->willReturn(self::CONN);

        $this->cacheStore = [];
        $this->cacheType = $this->createMock(FrontendInterface::class);
        $this->cacheType->method('load')->willReturnCallback(function ($key) {
            return $this->cacheStore[$key] ?? false;
        });
        $this->cacheType->method('save')->willReturnCallback(function ($data, $key) {
            $this->cacheStore[$key] = $data;
            return true;
        });

        return new RestExemptionValidator(
            $this->restClient,
            $config,
            new CacheKeyBuilder(),
            $this->cacheType
        );
    }

    private function certificatesResponse(array $items, ?string $nextCursor = null): RestResponse
    {
        $body = ['items' => $items];
        if ($nextCursor !== null) {
            $body['nextCursor'] = $nextCursor;
        }
        return new RestResponse(200, (string) json_encode($body));
    }

    /**
     * @param string[] $states Covered state abbreviations, shaped here into the
     *                         v3 state entries the API actually returns
     */
    private function certificate(string $id, array $states, ?string $disabledAt = null): array
    {
        $cert = [
            'certificateId' => $id,
            'customerId' => '42',
            'states' => array_map(static function ($abbr) {
                return ['abbreviation' => $abbr];
            }, $states),
        ];
        if ($disabledAt !== null) {
            $cert['disabledAt'] = $disabledAt;
        }
        return $cert;
    }

    public function testCertificateCoveringDestinationStateValidates()
    {
        $validator = $this->validator();

        $requestArgs = null;
        $this->restClient->method('request')->willReturnCallback(function (...$args) use (&$requestArgs) {
            $requestArgs = $args;
            return $this->certificatesResponse([$this->certificate('cert-9', ['NY', 'NJ'])]);
        });

        $this->assertSame('cert-9', $validator->validate('cert-9', '42', 'NY', self::STORE_ID));

        // Account-level listing, filtered by customer, not connection-scoped.
        $this->assertSame('GET', $requestArgs[0]);
        $this->assertStringContainsString('/tax/exemption-certificates?customerId=42', $requestArgs[1]);
        $this->assertSame(self::STORE_ID, $requestArgs[3]);
        $this->assertFalse($requestArgs[4]);
    }

    public function testCertificateNotCoveringDestinationStateIsRejected()
    {
        $validator = $this->validator();
        $this->restClient->method('request')->willReturn(
            $this->certificatesResponse([$this->certificate('cert-9', ['CT'])])
        );

        $this->assertNull($validator->validate('cert-9', '42', 'NY', self::STORE_ID));
    }

    public function testDisabledCertificateIsRejected()
    {
        $validator = $this->validator();
        $this->restClient->method('request')->willReturn(
            $this->certificatesResponse([$this->certificate('cert-9', ['NY'], '2026-01-01T00:00:00Z')])
        );

        $this->assertNull($validator->validate('cert-9', '42', 'NY', self::STORE_ID));
    }

    public function testMissingCertificateIsRejectedAndCachedEmpty()
    {
        $validator = $this->validator();
        $this->restClient->method('request')->willReturn($this->certificatesResponse([]));

        $this->assertNull($validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        $this->assertContains('[]', $this->cacheStore, 'the miss is cached as an empty state list');
    }

    /**
     * A single malformed state entry must not cost the customer the states
     * that are perfectly well-formed alongside it — the failure mode that
     * turned the whole certificate into "covers nothing".
     */
    public function testUnusableStateEntriesDoNotDiscardTheRest()
    {
        $validator = $this->validator();
        $this->restClient->method('request')->willReturn(
            $this->certificatesResponse([
                [
                    'certificateId' => 'cert-9',
                    'customerId' => '42',
                    'states' => [
                        ['abbreviation' => 'NY'],
                        ['abbreviation' => null],
                        ['abbreviation' => 'NEW YORK'],
                        [],
                        'NJ',
                        ['abbreviation' => 'CT'],
                    ],
                ],
            ])
        );

        $this->assertSame('cert-9', $validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        $this->assertSame('cert-9', $validator->validate('cert-9', '42', 'CT', self::STORE_ID));
        // The bare string is the pre-fix shape; it is not a v3 state entry.
        $this->assertNull($validator->validate('cert-9', '42', 'NJ', self::STORE_ID));
    }

    public function testCertificateWithNoCoveredStatesIsRejected()
    {
        $validator = $this->validator();
        $this->restClient->method('request')->willReturn(
            $this->certificatesResponse([$this->certificate('cert-9', [])])
        );

        $this->assertNull($validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        $this->assertContains('[]', $this->cacheStore, 'an enabled cert covering nothing caches as empty');
    }

    public function testPaginationFollowsCursorUntilCertificateFound()
    {
        $validator = $this->validator();

        $queries = [];
        $this->restClient->method('request')->willReturnCallback(function ($method, $path) use (&$queries) {
            $queries[] = $path;
            if (count($queries) === 1) {
                return $this->certificatesResponse([$this->certificate('other-cert', ['CA'])], 'cursor-2');
            }
            return $this->certificatesResponse([$this->certificate('cert-9', ['NY'])]);
        });

        $this->assertSame('cert-9', $validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('cursor=cursor-2', $queries[1]);
    }

    public function testSecondValidationServedFromCache()
    {
        $validator = $this->validator();
        $this->restClient->expects($this->once())->method('request')->willReturn(
            $this->certificatesResponse([$this->certificate('cert-9', ['NY'])])
        );

        $this->assertSame('cert-9', $validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        // Second call: no further API request (the mock allows exactly one).
        $this->assertSame('cert-9', $validator->validate('cert-9', '42', 'NY', self::STORE_ID));
    }

    public function testFetchFailuresFailClosedWithoutCaching()
    {
        // HTTP failure.
        $validator = $this->validator();
        $this->restClient->method('request')->willReturn(new RestResponse(500, ''));
        $this->assertNull($validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        $this->assertSame([], $this->cacheStore, 'a transient failure must not pin an empty list');

        // Transport failure.
        $validator = $this->validator();
        $this->restClient->method('request')->willThrowException(new RestTransportException('boom'));
        $this->assertNull($validator->validate('cert-9', '42', 'NY', self::STORE_ID));
        $this->assertSame([], $this->cacheStore);
    }

    public function testEmptyInputsValidateToNullWithoutApiCall()
    {
        $validator = $this->validator();
        $this->restClient->expects($this->never())->method('request');

        $this->assertNull($validator->validate('', '42', 'NY', self::STORE_ID));
        $this->assertNull($validator->validate('cert-9', '', 'NY', self::STORE_ID));
        $this->assertNull($validator->validate('cert-9', '42', '', self::STORE_ID));
    }

    public function testCacheKeyIncludesTheAccountConnection()
    {
        $keyBuilder = new CacheKeyBuilder();
        $this->assertNotSame(
            $keyBuilder->forExemptCertStates('42', 'cert-9', 'conn-a'),
            $keyBuilder->forExemptCertStates('42', 'cert-9', 'conn-b'),
            'stores on different TaxCloud accounts must never share exemption entries'
        );
    }
}
