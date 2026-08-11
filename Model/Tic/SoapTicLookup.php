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

use Taxcloud\Magento2\Api\TicLookupInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapClientProviderInterface;
use Throwable;

/**
 * TIC lookup over the v1 SOAP API.
 *
 * v1 has no search: GetTICs returns the entire catalogue — 779 entries, about
 * 58 KB, each just an id and a short description. That is small enough to hold
 * whole and match in PHP, which is what makes this backend answer every
 * keystroke without a network round trip. The list is cached for a day.
 *
 * Matching is substring, ranked so the most useful answers surface first:
 * exact code, then exact description, then descriptions starting with the
 * query, then descriptions containing it. There is no relevance score to
 * report — v1 supplies none, and inventing one would misrepresent it.
 */
class SoapTicLookup implements TicLookupInterface
{
    /**
     * Suggestions returned per search, matching the v3 backend's page size so
     * the picker feels the same on either API.
     */
    private const LIMIT = 10;

    /**
     * @var SoapClientProviderInterface
     */
    private $clientProvider;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var TicCache
     */
    private $cache;

    /**
     * @var TicSuggestionSorter
     */
    private $sorter;

    /**
     * @param SoapClientProviderInterface $clientProvider
     * @param TaxcloudConfig $config
     * @param TicCache $cache
     * @param TicSuggestionSorter $sorter
     */
    public function __construct(
        SoapClientProviderInterface $clientProvider,
        TaxcloudConfig $config,
        TicCache $cache,
        TicSuggestionSorter $sorter
    ) {
        $this->clientProvider = $clientProvider;
        $this->config = $config;
        $this->cache = $cache;
        $this->sorter = $sorter;
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, $store = null): TicSearchResult
    {
        $query = trim($query);
        if ($query === '') {
            return new TicSearchResult(TicSearchResult::AVAILABLE);
        }

        $list = $this->list($store);
        if (!$list->isAvailable()) {
            return $list;
        }

        return new TicSearchResult(
            TicSearchResult::AVAILABLE,
            array_slice($this->match($list->getSuggestions(), $query), 0, self::LIMIT)
        );
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $code, $store = null): TicSearchResult
    {
        $code = trim($code);
        if ($code === '') {
            return new TicSearchResult(TicSearchResult::AVAILABLE);
        }

        $list = $this->list($store);
        if (!$list->isAvailable()) {
            return $list;
        }

        return new TicSearchResult(
            TicSearchResult::AVAILABLE,
            $this->sorter->exactCodeOnly($list->getSuggestions(), $code)
        );
    }

    /**
     * The store's TIC catalogue, from cache when warm.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return TicSearchResult
     */
    private function list($store): TicSearchResult
    {
        $apiId = (string) $this->config->getApiId($store);
        $apiKey = (string) $this->config->getApiKey($store);
        if ($apiId === '' || $apiKey === '') {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_NOT_CONFIGURED);
        }

        $fingerprint = sha1($apiId . '|' . $apiKey);
        $cached = $this->cache->loadList($fingerprint);
        if ($cached !== null) {
            return new TicSearchResult(TicSearchResult::AVAILABLE, $cached);
        }

        $client = $this->clientProvider->getClient($store);
        if ($client === null) {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_TRANSPORT);
        }

        try {
            $response = $client->GetTICs(['apiLoginID' => $apiId, 'apiKey' => $apiKey]);
        } catch (Throwable $e) {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_TRANSPORT);
        }

        $result = $response->GetTICsResult ?? null;
        $type = $result->ResponseType ?? '';
        if ($type !== 'OK' && $type !== 'Informational') {
            // v1 answers a bad credential pair with a non-OK ResponseType
            // rather than a fault.
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_AUTH_FAILED);
        }

        $suggestions = $this->mapTics($result);
        if ($suggestions === []) {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_TRANSPORT);
        }

        $this->cache->saveList($fingerprint, $suggestions);

        return new TicSearchResult(TicSearchResult::AVAILABLE, $suggestions);
    }

    /**
     * Map the GetTICs envelope onto suggestions.
     *
     * The TICs member is an object with a TIC array; PHP's SoapClient collapses
     * a single-element array to a bare object, so both shapes are handled.
     *
     * @param object|null $result
     * @return TicSuggestion[]
     */
    private function mapTics($result): array
    {
        $tics = $result->TICs->TIC ?? null;
        if ($tics === null) {
            return [];
        }
        if (!is_array($tics)) {
            $tics = [$tics];
        }

        $suggestions = [];
        foreach ($tics as $tic) {
            $code = $tic->TICID ?? null;
            $label = trim((string) ($tic->Description ?? ''));
            if ($code === null || $label === '') {
                continue;
            }
            // No detail and no score: v1 has neither, and the UI renders the
            // row accordingly rather than showing empty affordances.
            $suggestions[] = new TicSuggestion((string) $code, $label);
        }

        return $suggestions;
    }

    /**
     * Rank the catalogue against a query.
     *
     * @param TicSuggestion[] $list
     * @param string $query
     * @return TicSuggestion[]
     */
    private function match(array $list, string $query): array
    {
        $needle = mb_strtolower($query);
        $isCode = $this->sorter->looksLikeCode($query);

        $tiers = [[], [], [], []];
        foreach ($list as $suggestion) {
            $label = mb_strtolower($suggestion->getLabel());

            if ($isCode && $this->sorter->sameCode($suggestion->getCode(), $query)) {
                $tiers[0][] = $suggestion;
                continue;
            }
            if ($label === $needle) {
                $tiers[1][] = $suggestion;
                continue;
            }
            if (mb_strpos($label, $needle) === 0) {
                $tiers[2][] = $suggestion;
                continue;
            }
            if (mb_strpos($label, $needle) !== false) {
                $tiers[3][] = $suggestion;
                continue;
            }
            // A numeric query also matches codes that merely start with it, so
            // typing "400" surfaces the 40xxx family while it is being typed.
            if ($isCode && mb_strpos($suggestion->getCode(), $query) === 0) {
                $tiers[3][] = $suggestion;
            }
        }

        return array_merge($tiers[0], $tiers[1], $tiers[2], $tiers[3]);
    }
}
