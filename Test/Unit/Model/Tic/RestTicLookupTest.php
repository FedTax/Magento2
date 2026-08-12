<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Tic;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;
use Taxcloud\Magento2\Model\Tic\RestTicLookup;
use Taxcloud\Magento2\Model\Tic\TicCache;
use Taxcloud\Magento2\Model\Tic\TicSearchResult;
use Taxcloud\Magento2\Model\Tic\TicSuggestionSorter;

/**
 * The v3 backend: a real search endpoint, so the work is in sending the right
 * request, mapping a richer response, and turning every failure — including
 * the documented rate limit — into something an admin form can survive.
 */
#[AllowMockObjectsWithoutExpectations]
class RestTicLookupTest extends TestCase
{
    /**
     * @var RestClient&\PHPUnit\Framework\MockObject\MockObject
     */
    private $client;

    /**
     * @return RestTicLookup
     */
    private function lookup(): RestTicLookup
    {
        $this->client = $this->createMock(RestClient::class);

        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('load')->willReturn(false);

        return new RestTicLookup(
            $this->client,
            new TicCache($frontend, new Json()),
            new TicSuggestionSorter()
        );
    }

    /**
     * One row as the documented TicSearchResult shape.
     *
     * @param array $overrides
     * @return array
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'ticId' => 40010,
            'label' => 'Candy',
            'naturalLabel' => 'Sweets and confectionery',
            'description' => 'Confection with sugar, honey or other sweetener',
            'documentation' => 'Long-form guidance that would swamp a dropdown row.',
            'rank' => 1,
            'score' => 0.94,
        ];
    }

    public function testPostsAnAccountLevelSearchRequest()
    {
        $lookup = $this->lookup();

        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                '/tax/tic/search',
                $this->callback(static function ($body) {
                    return $body['query'] === 'candy' && $body['limit'] >= 1 && $body['limit'] <= 100;
                }),
                null,
                // The endpoint is account-level: prefixing it with a connection
                // id would 404 for a store that has credentials but no
                // connection configured.
                false
            )
            ->willReturn(new RestResponse(200, json_encode(['query' => 'candy', 'results' => [$this->row()]])));

        $lookup->search('candy');
    }

    public function testMapsTheRicherResponseShape()
    {
        $lookup = $this->lookup();
        $this->client->method('request')
            ->willReturn(new RestResponse(200, json_encode(['results' => [$this->row()]])));

        $suggestion = $lookup->search('candy')->getSuggestions()[0];

        $this->assertSame('40010', $suggestion->getCode());
        $this->assertSame('Candy', $suggestion->getLabel());
        $this->assertSame('Confection with sugar, honey or other sweetener', $suggestion->getDetail());
        $this->assertSame(0.94, $suggestion->getScore());
    }

    /**
     * TaxCloud already ranks; re-sorting would discard the relevance the
     * endpoint exists to provide.
     */
    public function testPreservesTaxcloudRankingForTextQueries()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(200, json_encode(['results' => [
            $this->row(['ticId' => 40010, 'label' => 'Candy', 'score' => 0.94]),
            $this->row(['ticId' => 40080, 'label' => 'Gift basket', 'score' => 0.71]),
        ]])));

        $codes = array_map(
            static function ($s) {
                return $s->getCode();
            },
            $lookup->search('candy')->getSuggestions()
        );

        $this->assertSame(['40010', '40080'], $codes);
    }

    public function testExactCodeIsPromotedAboveHigherScoredResults()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(200, json_encode(['results' => [
            $this->row(['ticId' => 40080, 'label' => 'Gift basket', 'score' => 0.99]),
            $this->row(['ticId' => 40010, 'label' => 'Candy', 'score' => 0.10]),
        ]])));

        $this->assertSame('40010', $lookup->search('40010')->getSuggestions()[0]->getCode());
    }

    public function testMalformedRowIsSkippedRatherThanFailingTheSearch()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(200, json_encode(['results' => [
            ['label' => 'no id here'],
            $this->row(),
        ]])));

        $suggestions = $lookup->search('candy')->getSuggestions();

        $this->assertCount(1, $suggestions);
        $this->assertSame('40010', $suggestions[0]->getCode());
    }

    public function testNoMatchesIsAvailableWithNoSuggestions()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(200, json_encode(['results' => []])));

        $result = $lookup->search('zzzz');

        $this->assertTrue($result->isAvailable(), 'searching successfully and matching nothing is not a failure');
        $this->assertSame([], $result->getSuggestions());
    }

    /**
     * The rate limit is documented, and a throttled search says nothing about
     * the TIC the admin typed — so it must not read as "not found".
     */
    public function testRateLimitIsUnavailableNotNotFound()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(429, '{"title":"Too Many Requests"}'));

        $result = $lookup->search('candy');

        $this->assertFalse($result->isAvailable());
        $this->assertSame(TicSearchResult::REASON_TRANSPORT, $result->getReason());
    }

    public function testUnauthorizedReportsAuthFailure()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(401, '{}'));

        $this->assertSame(TicSearchResult::REASON_AUTH_FAILED, $lookup->search('candy')->getReason());
    }

    public function testMissingConfigurationReportsNotConfigured()
    {
        $lookup = $this->lookup();
        $this->client->method('request')
            ->willThrowException(new RestConfigurationException('no connection id'));

        $this->assertSame(TicSearchResult::REASON_NOT_CONFIGURED, $lookup->search('candy')->getReason());
    }

    public function testRejectedExchangeReportsAuthFailure()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willThrowException(
            new TokenExchangeException(TokenExchangeException::REJECTED, 'rejected')
        );

        $this->assertSame(TicSearchResult::REASON_AUTH_FAILED, $lookup->search('candy')->getReason());
    }

    public function testTransportFailureIsUnavailable()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willThrowException(new RestTransportException('timeout'));

        $this->assertSame(TicSearchResult::REASON_TRANSPORT, $lookup->search('candy')->getReason());
    }

    /**
     * Nothing about finding a TIC may escape into the admin form.
     */
    public function testUnexpectedErrorIsContained()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willThrowException(new \RuntimeException('boom'));

        $result = $lookup->search('candy');

        $this->assertFalse($result->isAvailable());
        $this->assertSame(TicSearchResult::REASON_TRANSPORT, $result->getReason());
    }

    public function testResolveReturnsOnlyTheExactCode()
    {
        $lookup = $this->lookup();
        $this->client->method('request')->willReturn(new RestResponse(200, json_encode(['results' => [
            $this->row(['ticId' => 40010, 'label' => 'Candy']),
            $this->row(['ticId' => 40080, 'label' => 'Gift basket']),
        ]])));

        $suggestions = $lookup->resolve('40010')->getSuggestions();

        $this->assertCount(1, $suggestions);
        $this->assertSame('Candy', $suggestions[0]->getLabel());
    }

    /**
     * Regression: ranking used to be applied only on the cold path, so as soon
     * as a query was cached the exact code stopped being promoted. It reached a
     * real admin — searching "20000" listed 10000 first — because every test
     * here used a cache that always missed.
     */
    public function testExactCodeIsStillPromotedOnACacheHit()
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $stored = null;
        $frontend->method('save')->willReturnCallback(static function ($data) use (&$stored) {
            $stored = $data;

            return true;
        });
        $frontend->method('load')->willReturnCallback(static function () use (&$stored) {
            return $stored ?? false;
        });

        $client = $this->createMock(RestClient::class);
        // Once only: the second search must be served from cache, which is the
        // path under test.
        $client->expects($this->once())->method('request')->willReturn(
            new RestResponse(200, json_encode(['results' => [
                $this->row(['ticId' => 10000, 'label' => 'Administrative', 'score' => 0.50]),
                $this->row(['ticId' => 20000, 'label' => 'Clothing', 'score' => 0.50]),
            ]]))
        );

        $lookup = new RestTicLookup($client, new TicCache($frontend, new Json()), new TicSuggestionSorter());

        $cold = $lookup->search('20000');
        $warm = $lookup->search('20000');

        $this->assertSame('20000', $cold->getSuggestions()[0]->getCode(), 'cold path');
        $this->assertSame('20000', $warm->getSuggestions()[0]->getCode(), 'cached path');
    }

    public function testEmptyQueryDoesNotCallTheApi()
    {
        $lookup = $this->lookup();
        $this->client->expects($this->never())->method('request');

        $this->assertSame([], $lookup->search('  ')->getSuggestions());
    }
}
