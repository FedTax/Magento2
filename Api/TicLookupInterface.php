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

namespace Taxcloud\Magento2\Api;

use Taxcloud\Magento2\Model\Tic\TicSearchResult;

/**
 * Finds Taxability Information Codes for the admin TIC fields.
 *
 * Implementations are per API generation; di.xml binds this to the store-aware
 * dispatcher so call sites stay unaware of which one serves them.
 *
 * The contract is deliberately total: searching never throws. A TIC field must
 * remain usable — and savable — when TaxCloud is unreachable, the credentials
 * are missing or rejected, or the search is rate-limited, so every such outcome
 * is a {@see TicSearchResult} reporting unavailability rather than an exception
 * the admin form has to survive.
 */
interface TicLookupInterface
{
    /**
     * Suggest TICs matching a query, for the given store's configured API.
     *
     * The store must be the one whose value is being edited, never the ambient
     * one: it selects both the API generation and the credentials used.
     *
     * @param string $query Free text, or a TIC code
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return TicSearchResult Suggestions, or an unavailable result — never throws
     */
    public function search(string $query, $store = null): TicSearchResult;

    /**
     * Resolve a single stored code to its TIC, so a field can show what a
     * stored number means.
     *
     * @param string $code
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return TicSearchResult Zero or one suggestion, or an unavailable result
     */
    public function resolve(string $code, $store = null): TicSearchResult;
}
