<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Cache;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Cache\ResultCache;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Covers the serialize-and-store response cache extracted from Model\Api:
 * reads are gated on caching being enabled, and writes persist with the
 * configured lifetime as TTL.
 */
#[AllowMockObjectsWithoutExpectations]
class ResultCacheTest extends TestCase
{
    private $cacheType;
    private $serializer;
    private $config;

    protected function setUp(): void
    {
        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->config = $this->createMock(TaxcloudConfig::class);
    }

    private function cache(): ResultCache
    {
        return new ResultCache($this->cacheType, $this->serializer, $this->config);
    }

    public function testGetReturnsNullOnCacheMiss()
    {
        $this->cacheType->method('load')->willReturn(false);

        $this->assertNull($this->cache()->get('key'));
    }

    public function testGetReturnsUnserializedValueWhenEnabledAndPresent()
    {
        $this->cacheType->method('load')->willReturn('serialized');
        $this->serializer->method('unserialize')->with('serialized')->willReturn(['product' => []]);
        $this->config->method('getCacheLifetime')->willReturn(3600);

        $this->assertSame(['product' => []], $this->cache()->get('key'));
    }

    public function testGetReturnsNullWhenCachingDisabled()
    {
        $this->cacheType->method('load')->willReturn('serialized');
        $this->serializer->method('unserialize')->willReturn(['product' => []]);
        $this->config->method('getCacheLifetime')->willReturn(0);

        $this->assertNull($this->cache()->get('key'), 'lifetime 0 must not serve a cached value');
    }

    public function testSavePersistsSerializedValueWithConfiguredLifetime()
    {
        $this->config->method('getCacheLifetime')->willReturn(1800);
        $this->serializer->method('serialize')->with(['x' => 1])->willReturn('serialized-x');

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with('serialized-x', 'key', ['taxcloud_rates'], 1800);

        $this->cache()->save('key', ['x' => 1], ['taxcloud_rates']);
    }
}
