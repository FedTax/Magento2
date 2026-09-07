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

namespace Taxcloud\Magento2\Model\Diagnostics;

/**
 * One store's answer to "will TaxCloud's tax collector actually run here?".
 *
 * Ownership of the tax total is global — DI preferences and the merged
 * sales.xml are not store-scoped — but the collector ORDER is store-scoped
 * (`sales/totals_sort`), and which stores are even evaluated depends on the
 * per-store `enabled` flag. So the verdict is modelled per store and
 * aggregated by {@see CollectorVerdict}.
 *
 * Immutable: every accessor is a read of constructor state.
 */
class StoreVerdict
{
    /**
     * @var int
     */
    private $storeId;

    /**
     * @var string
     */
    private $storeName;

    /**
     * @var string|null Null when nothing occupies the tax total slot at all
     */
    private $activeCollectorClass;

    /**
     * @var bool
     */
    private $owned;

    /**
     * @var string[]
     */
    private $interceptors;

    /**
     * @var string[]
     */
    private $laterCollectors;

    /**
     * @var string|null
     */
    private $failureReason;

    /**
     * @param int         $storeId
     * @param string      $storeName
     * @param string|null $activeCollectorClass Class occupying the `tax` total, or null if absent
     * @param bool        $owned                Whether that class is TaxCloud's collector
     * @param string[]    $interceptors         Classes of `around` plugins on collect()
     * @param string[]    $laterCollectors      Non-Magento collectors ordered after the tax total
     * @param string|null $failureReason        Set when the verdict could not be computed
     */
    public function __construct(
        int $storeId,
        string $storeName,
        ?string $activeCollectorClass,
        bool $owned,
        array $interceptors = [],
        array $laterCollectors = [],
        ?string $failureReason = null
    ) {
        $this->storeId = $storeId;
        $this->storeName = $storeName;
        $this->activeCollectorClass = $activeCollectorClass;
        $this->owned = $owned;
        $this->interceptors = $interceptors;
        $this->laterCollectors = $laterCollectors;
        $this->failureReason = $failureReason;
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * @return string
     */
    public function getStoreName(): string
    {
        return $this->storeName;
    }

    /**
     * @return string|null
     */
    public function getActiveCollectorClass(): ?string
    {
        return $this->activeCollectorClass;
    }

    /**
     * Whether TaxCloud's collector occupies the tax total slot.
     *
     * @return bool
     */
    public function isOwned(): bool
    {
        return $this->owned;
    }

    /**
     * @return string[]
     */
    public function getInterceptors(): array
    {
        return $this->interceptors;
    }

    /**
     * Non-Magento collectors ordered after the tax total.
     *
     * Advisory only: running later does not prove a collector writes tax.
     * Loyalty, gift card and fee modules routinely sit here harmlessly, so
     * this never makes a store unhealthy on its own.
     *
     * @return string[]
     */
    public function getLaterCollectors(): array
    {
        return $this->laterCollectors;
    }

    /**
     * @return string|null
     */
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * @return bool
     */
    public function isFailure(): bool
    {
        return $this->failureReason !== null;
    }

    /**
     * Healthy means TaxCloud's collector will run: it owns the slot, nothing
     * wraps collect(), and the probe itself succeeded.
     *
     * It does NOT mean tax is correct — credentials can still be wrong and the
     * Magento fallback can still swallow a Lookup failure. Callers must not
     * word it as a correctness claim.
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        return !$this->isFailure() && $this->owned && $this->interceptors === [];
    }

    /**
     * Structured description of what is wrong on this store, used to fingerprint
     * the conflict for the dismissal gate.
     *
     * Kind-tagged rather than a flat list of class names, because the same class
     * can displace TaxCloud two different ways — occupying the collector slot,
     * or wrapping collect() with an `around` plugin. Flattening them made the two
     * fingerprint identically, so dismissing the first would have silently hidden
     * the second.
     *
     * Everything the admin notification displays is represented here, so that
     * anything a merchant can read is also something they can acknowledge.
     *
     * @return array<string, mixed>
     */
    public function getConflictSignature(): array
    {
        $interceptors = $this->interceptors;
        sort($interceptors);

        $later = $this->laterCollectors;
        sort($later);

        return [
            'active' => $this->owned ? null : $this->activeCollectorClass,
            'owned' => $this->owned,
            'interceptors' => array_values($interceptors),
            'later' => array_values($later),
            'failure' => $this->failureReason,
        ];
    }
}
