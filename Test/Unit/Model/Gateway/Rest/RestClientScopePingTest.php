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
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;
use Taxcloud\Magento2\Test\Unit\BuildsUserAgent;

/**
 * pingForScope: the store's own resolved credentials drive the ping — Bearer
 * with the 401-invalidate-refresh-retry-once dance, X-API-KEY without it, and
 * local failures when the scope is unconfigured.
 */
#[AllowMockObjectsWithoutExpectations]
class RestClientScopePingTest extends TestCase
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

    private static function bearerScopeConfig(): array
    {
        return [
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ];
    }

    public function testBearerModeSendsAuthorizationHeaderAndSucceeds()
    {
        $client = $this->client(self::bearerScopeConfig());
        $this->cache->method('get')->willReturn(new BearerToken('jwt-1', time() + 3600));

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->expects($this->once())
            ->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/connections/' . self::CONN . '/ping');
        $this->curl->method('getStatus')->willReturn(200);

        $result = $client->pingForScope();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('Bearer jwt-1', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertArrayNotHasKey('X-API-KEY', $headers);
    }

    /**
     * The revoked-cached-token flow: 401 → invalidate → fresh exchange →
     * retry once → success.
     */
    public function testBearer401InvalidatesRefreshesAndRetriesOnce()
    {
        $client = $this->client(self::bearerScopeConfig());

        // First resolve: cached token. After invalidate: miss → fresh exchange.
        $this->cache->method('get')->willReturnOnConsecutiveCalls(
            new BearerToken('jwt-stale', time() + 3600),
            null
        );
        $this->cache->expects($this->once())->method('invalidate');
        $this->exchange->expects($this->once())
            ->method('exchange')
            ->willReturn(new BearerToken('jwt-fresh', time() + 3600));

        $sentAuth = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$sentAuth) {
            if ($n === 'Authorization') {
                $sentAuth[] = $v;
            }
        });
        $this->curl->expects($this->exactly(2))->method('get');
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(401, 200);

        $result = $client->pingForScope();

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['Bearer jwt-stale', 'Bearer jwt-fresh'], $sentAuth);
    }

    public function testBearerSecond401IsAuthFailureNotALoop()
    {
        $client = $this->client(self::bearerScopeConfig());
        $this->cache->method('get')->willReturnOnConsecutiveCalls(
            new BearerToken('jwt-stale', time() + 3600),
            null
        );
        $this->exchange->method('exchange')->willReturn(new BearerToken('jwt-fresh', time() + 3600));

        $this->curl->expects($this->exactly(2))->method('get');
        $this->curl->method('getStatus')->willReturn(401);

        $result = $client->pingForScope();

        $this->assertSame(PingResult::AUTH_FAILED, $result->getOutcome());
    }

    public function testXApiKeyMode401DoesNotRetry()
    {
        $client = $this->client([
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'portal-key'),
        ]);

        $this->curl->expects($this->once())->method('get');
        $this->curl->method('getStatus')->willReturn(401);
        $this->exchange->expects($this->never())->method('exchange');

        $result = $client->pingForScope();

        $this->assertSame(PingResult::AUTH_FAILED, $result->getOutcome());
    }

    public function testExchangeRejectionMapsToAuthFailedWithoutHttpCall()
    {
        $client = $this->client(self::bearerScopeConfig());
        $this->cache->method('get')->willReturn(null);
        $this->exchange->method('exchange')->willThrowException(
            new TokenExchangeException(TokenExchangeException::REJECTED, 'rejected (HTTP 400)')
        );
        $this->curl->expects($this->never())->method('get');

        $result = $client->pingForScope();

        $this->assertSame(PingResult::AUTH_FAILED, $result->getOutcome());
    }

    public function testExchangeUnreachableMapsToTransportError()
    {
        $client = $this->client(self::bearerScopeConfig());
        $this->cache->method('get')->willReturn(null);
        $this->exchange->method('exchange')->willThrowException(
            new TokenExchangeException(TokenExchangeException::UNREACHABLE, 'exchange failed (HTTP 500)')
        );

        $result = $client->pingForScope();

        $this->assertSame(PingResult::TRANSPORT_ERROR, $result->getOutcome());
        $this->assertStringContainsString('HTTP 500', $result->getReason());
    }

    /**
     * The admin test button passes the entered-but-unsaved Connection ID; it
     * must win over the configured one.
     */
    public function testConnectionIdOverrideWinsOverConfiguredValue()
    {
        $client = $this->client(self::bearerScopeConfig());
        $this->cache->method('get')->willReturn(new BearerToken('jwt-1', time() + 3600));

        $this->curl->expects($this->once())
            ->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/connections/entered-conn/ping');
        $this->curl->method('getStatus')->willReturn(200);

        $this->assertTrue($client->pingForScope(null, 'entered-conn')->isSuccess());
    }

    public function testMissingConnectionIdThrowsConfigurationErrorWithoutHttpCall()
    {
        $client = $this->client([
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ]);
        $this->curl->expects($this->never())->method('get');

        $this->expectException(RestConfigurationException::class);

        $client->pingForScope();
    }

    public function testUnconfiguredScopePropagatesConfigurationErrorWithoutHttpCall()
    {
        $client = $this->client([
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
        ]);
        $this->curl->expects($this->never())->method('get');

        $this->expectException(RestConfigurationException::class);

        $client->pingForScope();
    }
}
