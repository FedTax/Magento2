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

/**
 * Ordering rules shared by both lookup backends, so "type a code, get that
 * code" behaves identically whichever API serves the store.
 *
 * Instance methods rather than statics: Magento discourages static calls
 * because they cannot be intercepted, and both backends receive this by
 * injection anyway.
 *
 * TIC codes are compared numerically, not as strings: the same TIC is written
 * "00000" in the shipped default and returned as 0 by both APIs, and an admin
 * may type either.
 */
class TicSuggestionSorter
{
    /**
     * Promote an exact code match to the front, leaving the rest in the order
     * the backend produced (relevance for v3, list order for v1).
     *
     * @param TicSuggestion[] $suggestions
     * @param string $query
     * @return TicSuggestion[]
     */
    public function exactCodeFirst(array $suggestions, string $query): array
    {
        if (!$this->looksLikeCode($query)) {
            return array_values($suggestions);
        }

        $exact = [];
        $rest = [];
        foreach ($suggestions as $suggestion) {
            if ($this->sameCode($suggestion->getCode(), $query)) {
                $exact[] = $suggestion;
            } else {
                $rest[] = $suggestion;
            }
        }

        return array_merge($exact, $rest);
    }

    /**
     * Only the suggestion whose code is exactly this one — used when resolving
     * a stored value, where a near match would mislabel it.
     *
     * @param TicSuggestion[] $suggestions
     * @param string $code
     * @return TicSuggestion[]
     */
    public function exactCodeOnly(array $suggestions, string $code): array
    {
        $matches = [];
        foreach ($suggestions as $suggestion) {
            if ($this->sameCode($suggestion->getCode(), $code)) {
                $matches[] = $suggestion;
            }
        }

        return $matches;
    }

    /**
     * @param string $query
     * @return bool
     */
    public function looksLikeCode(string $query): bool
    {
        return (bool) preg_match('/^\d+$/', trim($query));
    }

    /**
     * "00000" and "0" are the same TIC.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public function sameCode(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if (!preg_match('/^\d+$/', $a) || !preg_match('/^\d+$/', $b)) {
            return $a === $b;
        }

        return (int) $a === (int) $b;
    }
}
