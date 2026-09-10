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
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;

/**
 * Token cache contract: hit within validity, refusal at the expiry margin,
 * invalidation, and graceful degradation when the backend has nothing.
 */
#[AllowMockObjectsWithoutExpectations]
class TokenCacheTest extends TestCase
{
    private const ENDPOINT = 'https://auth.example';
    private const API_ID = 'login';
    private const API_KEY = 'key-uuid';

    /**
     * @var FrontendInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $cacheType;

    private function cache(): TokenCache
    {
        $this->cacheType = $this->createMock(FrontendInterface::class);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback('json_encode');
        $serializer->method('unserialize')->willReturnCallback(
            static fn ($raw) => json_decode($raw, true)
        );

        return new TokenCache($this->cacheType, $serializer);
    }

    public function testSaveThenGetRoundTripsWhileValid()
    {
        $cache = $this->cache();
        $validTo = time() + 3600;

        $stored = null;
        $storedLifetime = null;
        $this->cacheType->method('save')->willReturnCallback(
            function ($data, $key, $tags, $lifetime) use (&$stored, &$storedLifetime) {
                $stored = $data;
                $storedLifetime = $lifetime;
                return true;
            }
        );
        $this->cacheType->method('load')->willReturnCallback(static function () use (&$stored) {
            return $stored ?? false;
        });

        $cache->save(self::ENDPOINT, self::API_ID, self::API_KEY, new BearerToken('jwt-1', $validTo));

        $this->assertSame(
            $validTo - TokenCache::EXPIRY_MARGIN - time(),
            $storedLifetime,
            'entry must expire a safety margin before the token itself'
        );

        $token = $cache->get(self::ENDPOINT, self::API_ID, self::API_KEY);
        $this->assertNotNull($token);
        $this->assertSame('jwt-1', $token->getToken());
        $this->assertSame($validTo, $token->getValidTo());
    }

    public function testTokenInsideTheExpiryMarginIsNotHandedOut()
    {
        $cache = $this->cache();
        // Valid for less than the margin: backend may still hold it, but get()
        // must refuse it.
        $this->cacheType->method('load')->willReturn(
            json_encode(['token' => 'jwt-old', 'validTo' => time() + TokenCache::EXPIRY_MARGIN - 10])
        );

        $this->assertNull($cache->get(self::ENDPOINT, self::API_ID, self::API_KEY));
    }

    public function testAlreadyExpiredTokenIsNeverSaved()
    {
        $cache = $this->cache();
        $this->cacheType->expects($this->never())->method('save');

        $cache->save(
            self::ENDPOINT,
            self::API_ID,
            self::API_KEY,
            new BearerToken('jwt', time() + TokenCache::EXPIRY_MARGIN - 1)
        );
    }

    public function testMissAndGarbageBothReturnNull()
    {
        $cache = $this->cache();
        $this->cacheType->method('load')->willReturnOnConsecutiveCalls(false, 'not-json{', json_encode(['x' => 1]));

        $this->assertNull($cache->get(self::ENDPOINT, self::API_ID, self::API_KEY), 'miss');
        $this->assertNull($cache->get(self::ENDPOINT, self::API_ID, self::API_KEY), 'garbage');
        $this->assertNull($cache->get(self::ENDPOINT, self::API_ID, self::API_KEY), 'wrong shape');
    }

    public function testInvalidateRemovesTheEntryForExactlyThisPair()
    {
        $cache = $this->cache();

        $removedKey = null;
        $this->cacheType->expects($this->once())->method('remove')->willReturnCallback(
            function ($key) use (&$removedKey) {
                $removedKey = $key;
                return true;
            }
        );
        $savedKey = null;
        $this->cacheType->method('save')->willReturnCallback(
            function ($data, $key) use (&$savedKey) {
                $savedKey = $key;
                return true;
            }
        );

        $cache->save(self::ENDPOINT, self::API_ID, self::API_KEY, new BearerToken('jwt', time() + 3600));
        $cache->invalidate(self::ENDPOINT, self::API_ID, self::API_KEY);

        $this->assertSame($savedKey, $removedKey, 'invalidate must target the same key save used');
        $this->assertStringNotContainsString(self::API_KEY, (string) $removedKey, 'keys must not embed raw credentials');
    }

    public function testDifferentPairsUseDifferentKeys()
    {
        $cache = $this->cache();

        $keys = [];
        $this->cacheType->method('save')->willReturnCallback(
            function ($data, $key) use (&$keys) {
                $keys[] = $key;
                return true;
            }
        );

        $token = new BearerToken('jwt', time() + 3600);
        $cache->save(self::ENDPOINT, self::API_ID, self::API_KEY, $token);
        $cache->save(self::ENDPOINT, 'other-login', 'other-key', $token);

        $this->assertCount(2, array_unique($keys));
    }
}
