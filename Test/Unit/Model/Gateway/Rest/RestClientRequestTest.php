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
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Test\Unit\BuildsUserAgent;

/**
 * The generic request() entry point: URL and header construction for
 * connection-scoped and account-level paths, JSON body handling, the Bearer
 * 401-invalidate-retry-once rule, and scrubbed transport failures.
 */
#[AllowMockObjectsWithoutExpectations]
class RestClientRequestTest extends TestCase
{
    use BuildsUserAgent;

    private const CONN = '25eb9b97-5acb-492d-b720-c03e79cf715a';

    /**
     * @var Curl&\PHPUnit\Framework\MockObject\MockObject
     */
    private $curl;

    /**
     * @var TokenExchange&\PHPUnit\Framework\MockObject\MockObject
     */
    private $exchange;

    /**
     * @var TokenCache&\PHPUnit\Framework\MockObject\MockObject
     */
    private $cache;

    private function client(array $configMap): RestClient
    {
        $this->curl = $this->createMock(Curl::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);
        $config = new TaxcloudConfig($scopeConfig);

        $this->exchange = $this->createMock(TokenExchange::class);
        $this->cache = $this->createMock(TokenCache::class);

        return new RestClient(
            $curlFactory,
            $config,
            new AuthProvider($config, $this->exchange, $this->cache),
            $this->userAgent()
        );
    }

    private static function value(string $path, $value): array
    {
        return [$path, ScopeInterface::SCOPE_STORE, null, $value];
    }

    private static function apiKeyScopeConfig(): array
    {
        return [
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'rest-api-key'),
        ];
    }

    private static function bearerScopeConfig(): array
    {
        return [
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ];
    }

    public function testPostBuildsConnectionScopedUrlWithJsonBodyAndHeaders()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->expects($this->once())
            ->method('post')
            ->with(
                TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/connections/' . self::CONN . '/carts',
                '{"items":[]}'
            );
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"items":[]}');

        $response = $client->request('POST', '/carts', ['items' => []]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['items' => []], $response->getBody());
        $this->assertSame('rest-api-key', $headers['X-API-KEY']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame($this->expectedUserAgent(), $headers['User-Agent']);
    }

    /**
     * request() is the single method every v3 operation funnels through, so
     * identifying it here is what makes the coverage structural rather than a
     * per-operation checklist — including on account-level paths, which skip
     * the connection prefix and could plausibly have taken another route.
     */
    public function testAccountLevelRequestCarriesTheUserAgent()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $client->request('GET', '/tax/verify-address', null, null, false);

        $this->assertSame($this->expectedUserAgent(), $headers['User-Agent'] ?? null);
    }

    public function testGetOnAccountLevelPathSkipsConnectionPrefixAndBody()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->expects($this->once())
            ->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/verify-address');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $client->request('GET', '/tax/verify-address', null, null, false);

        $this->assertArrayNotHasKey('Content-Type', $headers);
    }

    public function testBearer401InvalidatesAndRetriesOnceWithFreshToken()
    {
        $client = $this->client(self::bearerScopeConfig());

        // Stale cached token first, fresh exchange after invalidation.
        $this->cache->method('get')->willReturnOnConsecutiveCalls(
            new BearerToken('stale-jwt', time() + 3600),
            null
        );
        $this->exchange->method('exchange')->willReturn(new BearerToken('fresh-jwt', time() + 3600));
        $this->cache->expects($this->once())->method('invalidate');

        $authHeaders = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$authHeaders) {
            if ($n === 'Authorization') {
                $authHeaders[] = $v;
            }
        });
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(401, 201);
        $this->curl->method('getBody')->willReturn('{"orderId":"100000001"}');
        $this->curl->expects($this->exactly(2))->method('post');

        $response = $client->request('POST', '/orders', ['orderId' => '100000001']);

        $this->assertSame(201, $response->getStatus());
        $this->assertSame(['Bearer stale-jwt', 'Bearer fresh-jwt'], $authHeaders);
    }

    public function testApiKey401IsReturnedWithoutRetry()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('');
        $this->curl->expects($this->once())->method('post');
        $this->cache->expects($this->never())->method('invalidate');

        $response = $client->request('POST', '/carts', ['items' => []]);

        $this->assertTrue($response->isUnauthorized());
    }

    public function testMissingConnectionIdFailsLocallyForConnectionScopedCalls()
    {
        $client = $this->client([
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'rest-api-key'),
        ]);

        $this->expectException(RestConfigurationException::class);
        $client->request('POST', '/carts', ['items' => []]);
    }

    public function testTransportFailureThrowsScrubbedException()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $this->curl->method('post')->willThrowException(
            new \Exception('Could not resolve host for /tax/connections/' . self::CONN . '/carts')
        );

        try {
            $client->request('POST', '/carts', ['items' => []]);
            $this->fail('Expected RestTransportException');
        } catch (RestTransportException $e) {
            $this->assertStringNotContainsString(self::CONN, $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    public function testUnsupportedMethodIsRejected()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $this->expectException(\InvalidArgumentException::class);
        $client->request('DELETE', '/carts', null);
    }
}
