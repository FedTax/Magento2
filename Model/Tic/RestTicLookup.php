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
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;
use Throwable;

/**
 * TIC lookup over the v3 semantic search endpoint.
 *
 * POST /tax/tic/search is account-level, not connection-scoped, so it goes out
 * with connectionScoped=false — a store with valid credentials but no
 * connection id can still search.
 *
 * Results are ranked by TaxCloud; this preserves that order rather than
 * re-sorting, except that an exact code match is promoted to the front (an
 * admin who typed a number wants confirmation, not a relevance contest).
 */
class RestTicLookup implements TicLookupInterface
{
    /**
     * Account-level search path, relative to the REST endpoint.
     */
    private const SEARCH_PATH = '/tax/tic/search';

    /**
     * Results requested per search. The endpoint allows 1-100 and defaults to
     * 10; a picker that needs scrolling has already failed to be useful.
     */
    private const LIMIT = 10;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var TicCache
     */
    private $cache;

    /**
     * @var TicSuggestionSorter
     */
    private $sorter;

    /**
     * @param RestClient $restClient
     * @param TicCache $cache
     * @param TicSuggestionSorter $sorter
     */
    public function __construct(RestClient $restClient, TicCache $cache, TicSuggestionSorter $sorter)
    {
        $this->restClient = $restClient;
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

        $cached = $this->cache->load($query, $store);
        if ($cached !== null) {
            return new TicSearchResult(TicSearchResult::AVAILABLE, $cached);
        }

        try {
            $response = $this->restClient->request(
                'POST',
                self::SEARCH_PATH,
                ['query' => $query, 'limit' => self::LIMIT],
                $store,
                false
            );
        } catch (RestConfigurationException $e) {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_NOT_CONFIGURED);
        } catch (TokenExchangeException $e) {
            return new TicSearchResult(
                TicSearchResult::UNAVAILABLE,
                [],
                $e->isRejected() ? TicSearchResult::REASON_AUTH_FAILED : TicSearchResult::REASON_TRANSPORT
            );
        } catch (RestTransportException $e) {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_TRANSPORT);
        } catch (Throwable $e) {
            // Nothing about finding a TIC may escape into the admin form.
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_TRANSPORT);
        }

        if ($response->isUnauthorized()) {
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_AUTH_FAILED);
        }
        if (!$response->isSuccess()) {
            // Includes the documented 429: throttling is a "try again in a
            // moment", not a statement about the TIC the admin typed.
            return new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_TRANSPORT);
        }

        $suggestions = $this->mapResults($response->getBody());
        $this->cache->save($query, $suggestions, $store);

        return new TicSearchResult(TicSearchResult::AVAILABLE, $this->sorter->exactCodeFirst($suggestions, $query));
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

        $result = $this->search($code, $store);
        if (!$result->isAvailable()) {
            return $result;
        }

        return new TicSearchResult(
            TicSearchResult::AVAILABLE,
            $this->sorter->exactCodeOnly($result->getSuggestions(), $code)
        );
    }

    /**
     * Map the v3 payload onto suggestions, skipping anything malformed rather
     * than failing the whole search over one bad row.
     *
     * @param array<string, mixed>|null $body
     * @return TicSuggestion[]
     */
    private function mapResults(?array $body): array
    {
        $results = is_array($body['results'] ?? null) ? $body['results'] : [];

        $suggestions = [];
        foreach ($results as $row) {
            if (!is_array($row) || !isset($row['ticId'])) {
                continue;
            }

            $label = (string) ($row['label'] ?? $row['naturalLabel'] ?? $row['description'] ?? '');
            if ($label === '') {
                continue;
            }

            // description first: it is the concise line; documentation is the
            // long-form guidance and would swamp a dropdown row.
            $detail = (string) ($row['description'] ?? $row['documentation'] ?? '');

            $suggestions[] = new TicSuggestion(
                (string) $row['ticId'],
                $label,
                $detail !== '' && $detail !== $label ? $detail : null,
                isset($row['score']) ? (float) $row['score'] : null
            );
        }

        return $suggestions;
    }
}
