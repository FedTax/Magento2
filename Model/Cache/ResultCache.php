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
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;

/**
 * Serialize-and-store cache for TaxCloud response payloads (Lookup rates and
 * VerifyAddress results).
 *
 * A read only returns a hit when caching is actually enabled
 * (cache lifetime > 0); writes always persist with the configured lifetime as
 * the TTL. Callers supply the cache key (see
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
     * @param FrontendInterface   $cacheType Bound to the TaxCloud cache type in di.xml
     * @param SerializerInterface $serializer
     * @param CacheKeyBuilder     $cacheKeyBuilder
     * @param TaxcloudConfig      $config
     */
    public function __construct(
        FrontendInterface $cacheType,
        SerializerInterface $serializer,
        CacheKeyBuilder $cacheKeyBuilder,
        TaxcloudConfig $config
    ) {
        $this->cacheType = $cacheType;
        $this->serializer = $serializer;
        $this->cacheKeyBuilder = $cacheKeyBuilder;
        $this->config = $config;
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
        $this->save($this->cacheKeyBuilder->forLookup($params), $data, ['taxcloud_rates'], $store);
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
        $raw = $this->cacheType->load($cacheKey);
        if (!$raw) {
            return null;
        }
        $value = $this->serializer->unserialize($raw);
        if ($this->config->getCacheLifetime($store) && $value) {
            return $value;
        }
        return null;
    }

    /**
     * Persist a value under a key, serialized, with the configured cache
     * lifetime as the TTL.
     *
     * @param string $cacheKey
     * @param mixed  $data
     * @param array  $tags
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    public function save($cacheKey, $data, array $tags, $store = null)
    {
        $this->cacheType->save(
            $this->serializer->serialize($data),
            $cacheKey,
            $tags,
            $this->config->getCacheLifetime($store)
        );
    }
}
