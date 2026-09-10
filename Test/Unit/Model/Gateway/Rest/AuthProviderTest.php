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
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;

/**
 * Auth-mode precedence per store: saved rest_api_key → X-API-KEY; else V1
 * pair → Bearer (cache first, exchange on miss); else a local configuration
 * error. Store scope decides — two stores can run different modes.
 */
#[AllowMockObjectsWithoutExpectations]
class AuthProviderTest extends TestCase
{
    /**
     * @var TokenExchange&\PHPUnit\Framework\MockObject\MockObject
     */
    private $exchange;

    /**
     * @var TokenCache&\PHPUnit\Framework\MockObject\MockObject
     */
    private $cache;

    private function provider(array $configMap): AuthProvider
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        $this->exchange = $this->createMock(TokenExchange::class);
        $this->cache = $this->createMock(TokenCache::class);

        return new AuthProvider(new TaxcloudConfig($scopeConfig), $this->exchange, $this->cache);
    }

    private static function value(string $path, $value, $store = null): array
    {
        return [$path, ScopeInterface::SCOPE_STORE, $store, $value];
    }

    public function testSavedRestApiKeyWinsAndSkipsTheExchange()
    {
        $provider = $this->provider([
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'portal-key'),
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ]);
        $this->exchange->expects($this->never())->method('exchange');

        $auth = $provider->resolve();

        $this->assertFalse($auth->isBearer());
        $this->assertSame(['X-API-KEY' => 'portal-key'], $auth->getHeaders());
    }

    public function testV1PairYieldsBearerViaExchangeOnCacheMiss()
    {
        $provider = $this->provider([
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ]);

        $this->cache->method('get')->willReturn(null);
        $token = new BearerToken('jwt-fresh', time() + 3600);
        $this->exchange->expects($this->once())
            ->method('exchange')
            ->with('v1-id', 'v1-key', null)
            ->willReturn($token);
        $this->cache->expects($this->once())
            ->method('save')
            ->with(TaxcloudConfig::DEFAULT_REST_AUTH_ENDPOINT, 'v1-id', 'v1-key', $token);

        $auth = $provider->resolve();

        $this->assertTrue($auth->isBearer());
        $this->assertSame(['Authorization' => 'Bearer jwt-fresh'], $auth->getHeaders());
    }

    public function testCachedTokenIsReusedWithoutExchange()
    {
        $provider = $this->provider([
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ]);

        $this->cache->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_AUTH_ENDPOINT, 'v1-id', 'v1-key')
            ->willReturn(new BearerToken('jwt-cached', time() + 3600));
        $this->exchange->expects($this->never())->method('exchange');

        $auth = $provider->resolve();

        $this->assertSame(['Authorization' => 'Bearer jwt-cached'], $auth->getHeaders());
    }

    public function testNoCredentialsAtAllThrowsConfigurationError()
    {
        $provider = $this->provider([]);
        $this->exchange->expects($this->never())->method('exchange');

        $this->expectException(RestConfigurationException::class);

        $provider->resolve();
    }

    /**
     * Store-aware acceptance criterion: store 3 (portal key saved) resolves
     * X-API-KEY while store 7 (only V1 creds) resolves Bearer — from the same
     * provider instance.
     */
    public function testTwoStoresResolveDifferentModes()
    {
        $provider = $this->provider([
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'portal-key', 3),
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id', 7),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key', 7),
        ]);
        $this->cache->method('get')->willReturn(new BearerToken('jwt-7', time() + 3600));

        $this->assertFalse($provider->resolve(3)->isBearer());
        $this->assertTrue($provider->resolve(7)->isBearer());
    }

    public function testInvalidateDropsTheCachedTokenForTheStoresPair()
    {
        $provider = $this->provider([
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ]);
        $this->cache->expects($this->once())
            ->method('invalidate')
            ->with(TaxcloudConfig::DEFAULT_REST_AUTH_ENDPOINT, 'v1-id', 'v1-key');

        $provider->invalidate();
    }

    public function testInvalidateWithoutV1PairIsANoOp()
    {
        $provider = $this->provider([]);
        $this->cache->expects($this->never())->method('invalidate');

        $provider->invalidate();
    }
}
