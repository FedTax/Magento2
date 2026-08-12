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
use Taxcloud\Magento2\Model\Tic\TicCache;
use Taxcloud\Magento2\Model\Tic\TicSuggestion;

/**
 * Caching is what keeps the v1 backend network-free per keystroke and keeps the
 * v3 backend clear of its documented rate limit, so what matters is that
 * entries round-trip intact and that two stores never read each other's.
 */
#[AllowMockObjectsWithoutExpectations]
class TicCacheTest extends TestCase
{
    /**
     * A frontend backed by an in-memory store, recording the TTL it was given.
     *
     * @param array $store
     * @param array $ttls
     * @return FrontendInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function frontend(array &$store, array &$ttls)
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('save')->willReturnCallback(
            static function ($data, $key, $tags, $lifetime) use (&$store, &$ttls) {
                $store[$key] = $data;
                $ttls[$key] = $lifetime;

                return true;
            }
        );
        $frontend->method('load')->willReturnCallback(
            static function ($key) use (&$store) {
                return $store[$key] ?? false;
            }
        );

        return $frontend;
    }

    public function testQueryResultsRoundTrip()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $cache->save('candy', [new TicSuggestion('40010', 'Candy', 'Sweet things', 0.9)]);
        $loaded = $cache->load('candy');

        $this->assertCount(1, $loaded);
        $this->assertSame('40010', $loaded[0]->getCode());
        $this->assertSame('Candy', $loaded[0]->getLabel());
        $this->assertSame('Sweet things', $loaded[0]->getDetail());
        $this->assertSame(0.9, $loaded[0]->getScore());
    }

    /**
     * v1 suggestions have neither, and they must not come back as empty
     * strings or zero — the UI decides what to render from their absence.
     */
    public function testAbsentDetailAndScoreSurviveTheRoundTrip()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $cache->saveList('fp', [new TicSuggestion('40010', 'Candy')]);
        $loaded = $cache->loadList('fp');

        $this->assertNull($loaded[0]->getDetail());
        $this->assertNull($loaded[0]->getScore());
    }

    public function testMissReturnsNull()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $this->assertNull($cache->load('never searched'));
        $this->assertNull($cache->loadList('unknown'));
    }

    /**
     * Whitespace and case are not different searches.
     */
    public function testQueryKeyIsNormalized()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $cache->save('Candy', [new TicSuggestion('40010', 'Candy')]);

        $this->assertNotNull($cache->load('  candy '));
        $this->assertNotNull($cache->load('CANDY'));
    }

    /**
     * Two stores can be on different TaxCloud accounts; sharing a cache entry
     * would show one merchant the other's catalogue.
     */
    public function testDifferentStoresDoNotShareQueryEntries()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $cache->save('candy', [new TicSuggestion('1', 'store one')], 1);

        $this->assertNull($cache->load('candy', 2));
        $this->assertNotNull($cache->load('candy', 1));
    }

    public function testDifferentCredentialsDoNotShareTheList()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $cache->saveList('fingerprint-a', [new TicSuggestion('1', 'account a')]);

        $this->assertNull($cache->loadList('fingerprint-b'));
    }

    /**
     * The catalogue is stable enough for a day; a query result is only cached
     * long enough to absorb retyping against the rate limit.
     */
    public function testListOutlivesQueryResults()
    {
        $store = [];
        $ttls = [];
        $cache = new TicCache($this->frontend($store, $ttls), new Json());

        $cache->saveList('fp', [new TicSuggestion('1', 'x')]);
        $cache->save('candy', [new TicSuggestion('1', 'x')]);

        $listTtl = 0;
        $queryTtl = 0;
        foreach ($ttls as $key => $ttl) {
            if (strpos($key, 'taxcloud_tic_list_') === 0) {
                $listTtl = $ttl;
            } else {
                $queryTtl = $ttl;
            }
        }

        $this->assertGreaterThan($queryTtl, $listTtl);
        $this->assertGreaterThan(0, $queryTtl);
    }

    /**
     * A corrupt or foreign entry must not reach the UI as a broken suggestion.
     */
    public function testGarbageEntryIsIgnored()
    {
        $store = [];
        $ttls = [];
        $frontend = $this->frontend($store, $ttls);
        $cache = new TicCache($frontend, new Json());

        $cache->saveList('fp', [new TicSuggestion('40010', 'Candy')]);
        // Overwrite with rows missing the required keys.
        foreach (array_keys($store) as $key) {
            $store[$key] = json_encode([['nonsense' => true]]);
        }

        $this->assertSame([], $cache->loadList('fp'));
    }
}
