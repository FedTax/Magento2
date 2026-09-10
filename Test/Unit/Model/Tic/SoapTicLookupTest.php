<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Tic;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapClientProviderInterface;
use Taxcloud\Magento2\Model\Tic\SoapTicLookup;
use Taxcloud\Magento2\Model\Tic\TicCache;
use Taxcloud\Magento2\Model\Tic\TicSearchResult;
use Taxcloud\Magento2\Model\Tic\TicSuggestionSorter;
use Taxcloud\Magento2\Test\Unit\Double\SoapClientDouble;

/**
 * The v1 backend: it has no search, so it fetches the whole catalogue once and
 * ranks locally. What matters is that the ranking puts the answer the admin
 * meant at the top, that the catalogue is fetched once rather than per
 * keystroke, and that nothing here can throw into an admin form.
 */
#[AllowMockObjectsWithoutExpectations]
class SoapTicLookupTest extends TestCase
{
    private const CREDS = [
        [TaxcloudConfig::XML_PATH_API_ID, ScopeInterface::SCOPE_STORE, null, 'login'],
        [TaxcloudConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, null, 'key'],
    ];

    /**
     * @var SoapClientDouble&\PHPUnit\Framework\MockObject\MockObject
     */
    private $client;

    /**
     * A TIC catalogue in the shape GetTICs returns it.
     *
     * @param array<int, string> $tics
     * @return \stdClass
     */
    private function envelope(array $tics, string $responseType = 'OK'): \stdClass
    {
        $rows = [];
        foreach ($tics as $id => $description) {
            $tic = new \stdClass();
            $tic->TICID = $id;
            $tic->Description = $description;
            $rows[] = $tic;
        }

        $ticsNode = new \stdClass();
        $ticsNode->TIC = $rows;

        $result = new \stdClass();
        $result->ResponseType = $responseType;
        $result->TICs = $ticsNode;

        $envelope = new \stdClass();
        $envelope->GetTICsResult = $result;

        return $envelope;
    }

    /**
     * @param array $configMap
     * @return SoapTicLookup
     */
    private function lookup(array $configMap = self::CREDS): SoapTicLookup
    {
        $this->client = $this->getMockBuilder(SoapClientDouble::class)
            ->disableOriginalConstructor()
            ->getMock();

        $provider = $this->createMock(SoapClientProviderInterface::class);
        $provider->method('getClient')->willReturn($this->client);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        // A real cache over a null-ish frontend: load() returns false, so every
        // test starts cold unless it says otherwise.
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('load')->willReturn(false);

        return new SoapTicLookup(
            $provider,
            new TaxcloudConfig($scopeConfig),
            new TicCache($frontend, new Json()),
            new TicSuggestionSorter()
        );
    }

    public function testExactCodeRanksFirst()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([
            40080 => 'Gift basket with only food and candy',
            40010 => 'Candy',
        ]));

        $result = $lookup->search('40010');

        $this->assertTrue($result->isAvailable());
        $this->assertSame('40010', $result->getSuggestions()[0]->getCode());
    }

    /**
     * "Candy" must beat "Gift basket … candy …" — an exact description match
     * outranks a mere substring hit.
     */
    public function testExactDescriptionOutranksSubstring()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([
            40080 => 'Gift basket with only food and candy',
            40010 => 'Candy',
        ]));

        $suggestions = $lookup->search('candy')->getSuggestions();

        $this->assertSame('40010', $suggestions[0]->getCode());
        $this->assertSame('40080', $suggestions[1]->getCode());
    }

    public function testPrefixOutranksMidStringMatch()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([
            11001 => 'Combined Shipping and Handling Charge',
            10001 => 'Shipping insurance',
        ]));

        $suggestions = $lookup->search('shipping')->getSuggestions();

        $this->assertSame('10001', $suggestions[0]->getCode(), 'starts-with beats contains');
    }

    /**
     * v1 supplies no documentation and no relevance, and pretending otherwise
     * would show empty affordances in the picker.
     */
    public function testSuggestionsCarryNoDetailOrScore()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([40010 => 'Candy']));

        $suggestion = $lookup->search('candy')->getSuggestions()[0];

        $this->assertNull($suggestion->getDetail());
        $this->assertNull($suggestion->getScore());
    }

    /**
     * The catalogue is 779 entries; fetching it per keystroke is exactly what
     * the cache exists to prevent.
     */
    public function testCatalogueIsFetchedOncePerInstance()
    {
        $lookup = $this->lookup();
        $this->client->expects($this->once())
            ->method('GetTICs')
            ->willReturn($this->envelope([40010 => 'Candy', 20000 => 'Clothing']));

        $lookup->search('candy');
        $lookup->search('clothing');
        $lookup->search('cand');
    }

    public function testResolveReturnsOnlyTheExactCode()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([
            40010 => 'Candy',
            40080 => 'Gift basket',
        ]));

        $suggestions = $lookup->resolve('40010')->getSuggestions();

        $this->assertCount(1, $suggestions);
        $this->assertSame('Candy', $suggestions[0]->getLabel());
    }

    /**
     * "00000" in the shipped default and 0 from the API are the same TIC.
     */
    public function testCodeComparisonIsNumeric()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([
            0 => 'Uncategorized tangible personal property',
        ]));

        $this->assertCount(1, $lookup->resolve('00000')->getSuggestions());
    }

    public function testUnknownCodeResolvesToNothingRatherThanFailing()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([40010 => 'Candy']));

        $result = $lookup->resolve('45999');

        $this->assertTrue($result->isAvailable(), 'a search that ran but matched nothing is still available');
        $this->assertSame([], $result->getSuggestions());
    }

    public function testMissingCredentialsReportNotConfigured()
    {
        $result = $this->lookup([])->search('candy');

        $this->assertFalse($result->isAvailable());
        $this->assertSame(TicSearchResult::REASON_NOT_CONFIGURED, $result->getReason());
    }

    public function testRejectedCredentialsReportAuthFailure()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willReturn($this->envelope([], 'AuthorizationError'));

        $result = $lookup->search('candy');

        $this->assertFalse($result->isAvailable());
        $this->assertSame(TicSearchResult::REASON_AUTH_FAILED, $result->getReason());
    }

    /**
     * Diagnostics must never cost the admin their form: a SOAP fault becomes an
     * unavailable result, not an exception.
     */
    public function testSoapFaultBecomesUnavailable()
    {
        $lookup = $this->lookup();
        $this->client->method('GetTICs')->willThrowException(new \RuntimeException('connection reset'));

        $result = $lookup->search('candy');

        $this->assertFalse($result->isAvailable());
        $this->assertSame(TicSearchResult::REASON_TRANSPORT, $result->getReason());
    }

    public function testEmptyQueryDoesNotSearch()
    {
        $lookup = $this->lookup();
        $this->client->expects($this->never())->method('GetTICs');

        $this->assertSame([], $lookup->search('   ')->getSuggestions());
    }
}
