<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Cache;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Cache\ResultCache;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;

/**
 * Covers the serialize-and-store response cache extracted from Model\Api:
 * reads are gated on caching being enabled, writes are skipped entirely when it
 * is disabled, and a cached rate never outlives the store's local day.
 */
#[AllowMockObjectsWithoutExpectations]
class ResultCacheTest extends TestCase
{
    private $cacheType;
    private $serializer;
    private $config;
    private $timezone;

    protected function setUp(): void
    {
        $this->cacheType = $this->createMock(FrontendInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn('America/New_York');
    }

    private function cache(): ResultCache
    {
        return new ResultCache(
            $this->cacheType,
            $this->serializer,
            new CacheKeyBuilder(),
            $this->config,
            $this->timezone
        );
    }

    /**
     * Seconds from now until the next midnight in the store timezone the mock
     * reports — the ceiling a cached rate lifetime is held to.
     */
    private function secondsUntilStoreMidnight(): int
    {
        $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
        return (clone $now)->modify('tomorrow midnight')->getTimestamp() - $now->getTimestamp();
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

    /**
     * A 0 lifetime means "do not cache", but the cache frontend reads a 0 TTL as
     * "never expire". Writing anyway would leave entries that never age out and
     * that a later lifetime bump would start serving.
     */
    public function testSaveWritesNothingWhenCachingDisabled()
    {
        $this->config->method('getCacheLifetime')->willReturn(0);

        $this->cacheType->expects($this->never())->method('save');

        $this->cache()->save('key', ['x' => 1], ['taxcloud_rates']);
    }

    public function testSaveWritesNothingWhenLifetimeIsNegative()
    {
        $this->config->method('getCacheLifetime')->willReturn(-1);

        $this->cacheType->expects($this->never())->method('save');

        $this->cache()->save('key', ['x' => 1], ['taxcloud_rates']);
    }

    public function testSaveLookupWritesNothingWhenCachingDisabled()
    {
        $this->config->method('getCacheLifetime')->willReturn(0);

        $this->cacheType->expects($this->never())->method('save');

        $this->cache()->saveLookup(['cartItems' => []], ['product' => []]);
    }

    public function testGetLookupKeysOnPayloadHash()
    {
        $params = ['cartItems' => [['ItemID' => 'sku']]];
        $expectedKey = (new CacheKeyBuilder())->forLookup($params);
        $this->config->method('getCacheLifetime')->willReturn(3600);
        $this->cacheType->method('load')->with($expectedKey)->willReturn('serialized');
        $this->serializer->method('unserialize')->willReturn(['product' => []]);

        $this->assertSame(['product' => []], $this->cache()->getLookup($params));
    }

    public function testSaveLookupPersistsUnderRatesTag()
    {
        $params = ['cartItems' => []];
        $expectedKey = (new CacheKeyBuilder())->forLookup($params);
        $this->config->method('getCacheLifetime')->willReturn(600);
        $this->serializer->method('serialize')->willReturn('blob');

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with('blob', $expectedKey, ['taxcloud_rates'], 600);

        $this->cache()->saveLookup($params, ['product' => []]);
    }

    public function testSaveAddressPersistsUnderAddressTag()
    {
        $params = ['zip5' => '95014'];
        $expectedKey = (new CacheKeyBuilder())->forAddress($params);
        $this->config->method('getCacheLifetime')->willReturn(600);
        $this->serializer->method('serialize')->willReturn('blob');

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with('blob', $expectedKey, ['taxcloud_address'], 600);

        $this->cache()->saveAddress($params, ['City' => 'CUPERTINO']);
    }

    /**
     * Rates change on a calendar-day boundary in the jurisdiction (rate updates,
     * sales-tax holidays) and the payload hash cannot see that, so the default
     * day-long lifetime must not let an entry straddle local midnight.
     */
    public function testSaveLookupCapsLifetimeAtTheStoresNextLocalMidnight()
    {
        $this->config->method('getCacheLifetime')->willReturn(86400);
        $this->serializer->method('serialize')->willReturn('blob');
        $expected = $this->secondsUntilStoreMidnight();

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with(
                'blob',
                $this->anything(),
                ['taxcloud_rates'],
                $this->logicalAnd(
                    $this->greaterThanOrEqual($expected - 5),
                    $this->lessThanOrEqual($expected)
                )
            );

        $this->cache()->saveLookup(['cartItems' => []], ['product' => []]);
    }

    public function testSaveLookupKeepsAShortConfiguredLifetime()
    {
        $this->config->method('getCacheLifetime')->willReturn(300);
        $this->serializer->method('serialize')->willReturn('blob');

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with('blob', $this->anything(), ['taxcloud_rates'], 300);

        $this->cache()->saveLookup(['cartItems' => []], ['product' => []]);
    }

    /**
     * VerifyAddress results carry no day boundary, so they keep the full
     * configured lifetime.
     */
    public function testSaveAddressIsNotCappedAtMidnight()
    {
        $this->config->method('getCacheLifetime')->willReturn(86400);
        $this->serializer->method('serialize')->willReturn('blob');

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with('blob', $this->anything(), ['taxcloud_address'], 86400);

        $this->cache()->saveAddress(['zip5' => '95014'], ['City' => 'CUPERTINO']);
    }

    public function testGetLookupLifetimeIsZeroWhenCachingDisabled()
    {
        $this->config->method('getCacheLifetime')->willReturn(0);

        $this->assertSame(0, $this->cache()->getLookupLifetime());
    }

    /**
     * An unusable timezone setting must not break caching — it falls back to UTC
     * rather than throwing out of a tax lookup.
     */
    public function testUnparseableStoreTimezoneFallsBackToUtcBoundary()
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Not/AZone');
        $cache = new ResultCache(
            $this->cacheType,
            $this->serializer,
            new CacheKeyBuilder(),
            $this->config,
            $timezone
        );
        $this->config->method('getCacheLifetime')->willReturn(86400);

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $expected = (clone $now)->modify('tomorrow midnight')->getTimestamp() - $now->getTimestamp();

        $this->assertEqualsWithDelta($expected, $cache->getLookupLifetime(), 5);
    }
}
