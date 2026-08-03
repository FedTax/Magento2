<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestCredentials;

/**
 * The v3 ping transport: URL/header construction against the documented
 * endpoint, the status-code → outcome mapping the admin form relies on, and
 * the guarantee that credential values never leak through error reasons.
 */
#[AllowMockObjectsWithoutExpectations]
class RestClientTest extends TestCase
{
    private const API_KEY = 'test-api-key-value';
    private const CONNECTION_ID = '25eb9b97-5acb-492d-b720-c03e79cf715a';

    /**
     * @var Curl&\PHPUnit\Framework\MockObject\MockObject
     */
    private $curl;

    private function client(array $configMap = []): RestClient
    {
        $this->curl = $this->createMock(Curl::class);

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        return new RestClient($curlFactory, new TaxcloudConfig($scopeConfig));
    }

    private function credentials(): RestCredentials
    {
        return new RestCredentials(self::API_KEY, self::CONNECTION_ID);
    }

    /**
     * GET {endpoint}/tax/connections/{id}/ping with X-API-KEY and Accept
     * headers — the documented v3 verification request, against the
     * production default endpoint when none is configured.
     */
    public function testPingHitsTheDocumentedEndpointWithAuthHeaders()
    {
        $client = $this->client();

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($name, $value) use (&$headers) {
            $headers[$name] = $value;
        });
        $this->curl->expects($this->once())
            ->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/connections/' . self::CONNECTION_ID . '/ping');
        $this->curl->method('getStatus')->willReturn(200);

        $result = $client->ping($this->credentials());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            ['X-API-KEY' => self::API_KEY, 'Accept' => 'application/json'],
            $headers
        );
    }

    /**
     * Endpoint and timeout are store-scoped: a store-7 ping must use store 7's
     * configured endpoint and api_timeout, not the ambient values.
     */
    public function testPingResolvesEndpointAndTimeoutForThePassedStore()
    {
        $client = $this->client([
            [TaxcloudConfig::XML_PATH_REST_ENDPOINT, ScopeInterface::SCOPE_STORE, 7, 'https://staging.example/'],
            [TaxcloudConfig::XML_PATH_REST_ENDPOINT, ScopeInterface::SCOPE_STORE, null, 'https://ambient.example'],
            [TaxcloudConfig::XML_PATH_API_TIMEOUT, ScopeInterface::SCOPE_STORE, 7, '25'],
            [TaxcloudConfig::XML_PATH_API_TIMEOUT, ScopeInterface::SCOPE_STORE, null, '5'],
        ]);

        $this->curl->expects($this->once())->method('setTimeout')->with(25);
        $this->curl->expects($this->once())
            ->method('get')
            ->with('https://staging.example/tax/connections/' . self::CONNECTION_ID . '/ping');
        $this->curl->method('getStatus')->willReturn(200);

        $client->ping($this->credentials(), 7);
    }

    /**
     * @dataProvider statusOutcomeProvider
     */
    #[DataProvider('statusOutcomeProvider')]
    public function testPingMapsHttpStatusToOutcome(int $status, string $outcome, string $reason)
    {
        $client = $this->client();
        $this->curl->method('getStatus')->willReturn($status);

        $result = $client->ping($this->credentials());

        $this->assertSame($outcome, $result->getOutcome());
        $this->assertSame($reason, $result->getReason());
    }

    public static function statusOutcomeProvider(): array
    {
        return [
            '200 is success' => [200, PingResult::OK, ''],
            '401 is a bad key' => [401, PingResult::AUTH_FAILED, ''],
            '404 is an unknown connection' => [404, PingResult::UNKNOWN_CONNECTION, ''],
            '500 is a transport error with the code' => [500, PingResult::TRANSPORT_ERROR, 'HTTP 500'],
            '429 is a transport error with the code' => [429, PingResult::TRANSPORT_ERROR, 'HTTP 429'],
        ];
    }

    /**
     * A network-level failure surfaces as a transport error carrying the curl
     * reason — with any credential value scrubbed, since curl errors can echo
     * the request URL and the URL carries the connection id.
     */
    public function testTransportExceptionIsScrubbedOfCredentialValues()
    {
        $client = $this->client();
        $this->curl->method('get')->willThrowException(new \Exception(
            'Could not resolve host for /tax/connections/' . self::CONNECTION_ID . '/ping key=' . self::API_KEY
        ));

        $result = $client->ping($this->credentials());

        $this->assertFalse($result->isSuccess());
        $this->assertSame(PingResult::TRANSPORT_ERROR, $result->getOutcome());
        $this->assertStringNotContainsString(self::CONNECTION_ID, $result->getReason());
        $this->assertStringNotContainsString(self::API_KEY, $result->getReason());
        $this->assertStringContainsString('Could not resolve host', $result->getReason());
    }

    /**
     * The connection id lands in a URL path segment; a value with reserved
     * characters must be encoded, not spliced in raw.
     */
    public function testConnectionIdIsUrlEncodedInThePath()
    {
        $client = $this->client();
        $this->curl->expects($this->once())
            ->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/connections/bad%2F..%2Fid/ping');
        $this->curl->method('getStatus')->willReturn(200);

        $client->ping(new RestCredentials(self::API_KEY, 'bad/../id'));
    }
}
