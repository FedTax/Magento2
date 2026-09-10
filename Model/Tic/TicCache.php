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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Tic;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Caches for the two TIC lookup backends. Both ride the module's own cache
 * type, so `bin/magento cache:clean taxcloud` already clears them and no new
 * cache type is registered.
 *
 * Deliberately NOT gated on the configured tax cache lifetime, unlike
 * {@see \Taxcloud\Magento2\Model\Cache\ResultCache}. Turning off tax-result
 * caching is a statement about the freshness of rates charged to customers;
 * it should not also make an admin's TIC picker slower and more likely to be
 * throttled. These entries carry no tax amounts and expire on their own fixed
 * schedules.
 *
 * Entries are keyed by scope and by the credentials in play, so two stores on
 * different TaxCloud accounts never read each other's results.
 */
class TicCache
{
    /**
     * The v1 list changes rarely; a day old is fine, and a TIC issued since is
     * still perfectly saveable because the not-found warning never blocks.
     */
    private const LIST_LIFETIME = 86400;

    /**
     * Short by design: this exists to absorb retyping and backspacing against
     * the documented v3 rate limit, not to serve stale results for long.
     */
    private const QUERY_LIFETIME = 300;

    /**
     * @var FrontendInterface
     */
    private $cacheType;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @param FrontendInterface $cacheType Bound to the TaxCloud cache type in di.xml
     * @param SerializerInterface $serializer
     */
    public function __construct(FrontendInterface $cacheType, SerializerInterface $serializer)
    {
        $this->cacheType = $cacheType;
        $this->serializer = $serializer;
    }

    /**
     * Cached suggestions for a v3 query, or null.
     *
     * @param string $query
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return TicSuggestion[]|null
     */
    public function load(string $query, $store = null): ?array
    {
        return $this->read($this->queryKey($query, $store));
    }

    /**
     * @param string $query
     * @param TicSuggestion[] $suggestions
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    public function save(string $query, array $suggestions, $store = null): void
    {
        $this->write($this->queryKey($query, $store), $suggestions, self::QUERY_LIFETIME);
    }

    /**
     * The cached v1 TIC list for a credential pair, or null.
     *
     * @param string $credentialFingerprint
     * @return TicSuggestion[]|null
     */
    public function loadList(string $credentialFingerprint): ?array
    {
        return $this->read('taxcloud_tic_list_' . $credentialFingerprint);
    }

    /**
     * @param string $credentialFingerprint
     * @param TicSuggestion[] $suggestions
     * @return void
     */
    public function saveList(string $credentialFingerprint, array $suggestions): void
    {
        $this->write('taxcloud_tic_list_' . $credentialFingerprint, $suggestions, self::LIST_LIFETIME);
    }

    /**
     * @param string $key
     * @return TicSuggestion[]|null
     */
    private function read(string $key): ?array
    {
        $raw = $this->cacheType->load($key);
        if (!$raw) {
            return null;
        }

        $rows = $this->serializer->unserialize($raw);
        if (!is_array($rows)) {
            return null;
        }

        $suggestions = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['code'], $row['label'])) {
                continue;
            }
            $suggestions[] = new TicSuggestion(
                (string) $row['code'],
                (string) $row['label'],
                isset($row['detail']) ? (string) $row['detail'] : null,
                isset($row['score']) ? (float) $row['score'] : null
            );
        }

        return $suggestions;
    }

    /**
     * @param string $key
     * @param TicSuggestion[] $suggestions
     * @param int $lifetime
     * @return void
     */
    private function write(string $key, array $suggestions, int $lifetime): void
    {
        $rows = array_map(
            static function (TicSuggestion $suggestion) {
                return $suggestion->toArray();
            },
            $suggestions
        );

        // The cache type is a TagScope: it stamps its own CACHE_TAG on every
        // entry, so callers pass only their own descriptive tag.
        $this->cacheType->save(
            $this->serializer->serialize($rows),
            $key,
            ['taxcloud_tic'],
            $lifetime
        );
    }

    /**
     * Normalized so "Candy", "candy " and "  CANDY" share one entry.
     *
     * @param string $query
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    private function queryKey(string $query, $store): string
    {
        $scope = is_object($store) ? (string) $store->getId() : (string) $store;

        return 'taxcloud_tic_q_' . sha1($scope . '|' . mb_strtolower(trim($query)));
    }
}
