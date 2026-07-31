<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Cache;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;

/**
 * Serialize-and-store cache for TaxCloud response payloads (Lookup rates and
 * VerifyAddress results).
 *
 * Caching is off entirely when the configured lifetime is <= 0: reads never
 * serve a hit and writes are skipped, so a disabled cache leaves nothing behind
 * for a later lifetime change to start serving.
 *
 * Rate lifetimes are additionally capped at the store's next local midnight.
 * The Lookup key is a hash of the request payload, so payload-driven changes
 * (TIC, exemption, address) invalidate on their own, but a rate change or a
 * sales-tax-holiday boundary does not — both take effect on a calendar-day
 * boundary in the jurisdiction, so a cached rate must never outlive one.
 * Address results carry no such boundary and keep the configured lifetime.
 *
 * Callers supply the cache key (see
 * {@see \Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder}) and the tags.
 */
class ResultCache
{
    /**
     * @var FrontendInterface
     */
    private $cacheType;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var CacheKeyBuilder
     */
    private $cacheKeyBuilder;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @param FrontendInterface   $cacheType Bound to the TaxCloud cache type in di.xml
     * @param SerializerInterface $serializer
     * @param CacheKeyBuilder     $cacheKeyBuilder
     * @param TaxcloudConfig      $config
     * @param TimezoneInterface   $timezone  Resolves the store's local day boundary
     */
    public function __construct(
        FrontendInterface $cacheType,
        SerializerInterface $serializer,
        CacheKeyBuilder $cacheKeyBuilder,
        TaxcloudConfig $config,
        TimezoneInterface $timezone
    ) {
        $this->cacheType = $cacheType;
        $this->serializer = $serializer;
        $this->cacheKeyBuilder = $cacheKeyBuilder;
        $this->config = $config;
        $this->timezone = $timezone;
    }

    /**
     * Cached Lookup (tax rate) response for a request payload, or null.
     *
     * @param array $params Lookup request params (post-observer)
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose cache lifetime applies
     * @return mixed|null
     */
    public function getLookup(array $params, $store = null)
    {
        return $this->get($this->cacheKeyBuilder->forLookup($params), $store);
    }

    /**
     * Persist a Lookup response keyed on its request payload.
     *
     * @param array $params
     * @param mixed $data
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    public function saveLookup(array $params, $data, $store = null)
    {
        $this->save(
            $this->cacheKeyBuilder->forLookup($params),
            $data,
            ['taxcloud_rates'],
            $store,
            $this->getLookupLifetime($store)
        );
    }

    /**
     * Cached VerifyAddress response for a request payload, or null.
     *
     * @param array $params
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return mixed|null
     */
    public function getAddress(array $params, $store = null)
    {
        return $this->get($this->cacheKeyBuilder->forAddress($params), $store);
    }

    /**
     * Persist a VerifyAddress response keyed on its request payload.
     *
     * @param array $params
     * @param mixed $data
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    public function saveAddress(array $params, $data, $store = null)
    {
        $this->save($this->cacheKeyBuilder->forAddress($params), $data, ['taxcloud_address'], $store);
    }

    /**
     * Return the cached, unserialized value for a key, or null when the cache
     * is empty or caching is disabled (lifetime <= 0).
     *
     * @param string $cacheKey
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return mixed|null
     */
    public function get($cacheKey, $store = null)
    {
        if ($this->config->getCacheLifetime($store) <= 0) {
            return null;
        }
        $raw = $this->cacheType->load($cacheKey);
        if (!$raw) {
            return null;
        }
        $value = $this->serializer->unserialize($raw);

        return $value ?: null;
    }

    /**
     * Persist a value under a key, serialized, with the configured cache
     * lifetime as the TTL.
     *
     * Nothing is written when the effective lifetime is <= 0. A 0 TTL means
     * "never expire" on the cache frontend rather than "do not cache", so
     * writing with caching disabled would leave entries that never age out and
     * that a later lifetime change would start serving.
     *
     * @param string   $cacheKey
     * @param mixed    $data
     * @param array    $tags
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @param int|null $lifetime TTL override; defaults to the configured lifetime
     * @return void
     */
    public function save($cacheKey, $data, array $tags, $store = null, $lifetime = null)
    {
        $lifetime = $lifetime === null ? $this->config->getCacheLifetime($store) : (int) $lifetime;
        if ($lifetime <= 0) {
            return;
        }

        $this->cacheType->save(
            $this->serializer->serialize($data),
            $cacheKey,
            $tags,
            $lifetime
        );
    }

    /**
     * TTL for a cached Lookup result: the configured lifetime, shortened so the
     * entry cannot survive past the store's next local midnight.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int
     */
    public function getLookupLifetime($store = null)
    {
        $lifetime = $this->config->getCacheLifetime($store);
        if ($lifetime <= 0) {
            return 0;
        }

        return min($lifetime, $this->getSecondsUntilNextLocalMidnight($store));
    }

    /**
     * Seconds remaining until midnight in the store's configured timezone.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return int
     */
    private function getSecondsUntilNextLocalMidnight($store = null)
    {
        try {
            $timezone = new \DateTimeZone(
                (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, $store)
            );
        } catch (\Throwable $e) {
            $timezone = new \DateTimeZone('UTC');
        }

        $now = new \DateTime('now', $timezone);
        $midnight = (clone $now)->modify('tomorrow midnight');

        return max(1, $midnight->getTimestamp() - $now->getTimestamp());
    }
}
