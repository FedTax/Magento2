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
 * The whole install's answer to "is TaxCloud the active tax collector?",
 * as a set of per-store verdicts.
 *
 * One verdict object feeds all three surfaces — admin notification, CLI, and
 * the order-placement log line — so they can never disagree with each other.
 *
 * Immutable.
 */
class CollectorVerdict
{
    /**
     * @var StoreVerdict[]
     */
    private $storeVerdicts;

    /**
     * @param StoreVerdict[] $storeVerdicts Verdicts for the stores where TaxCloud is enabled
     */
    public function __construct(array $storeVerdicts)
    {
        $this->storeVerdicts = array_values($storeVerdicts);
    }

    /**
     * @return StoreVerdict[]
     */
    public function getStoreVerdicts(): array
    {
        return $this->storeVerdicts;
    }

    /**
     * Stores where TaxCloud will not calculate.
     *
     * @return StoreVerdict[]
     */
    public function getUnhealthyStoreVerdicts(): array
    {
        return array_values(array_filter(
            $this->storeVerdicts,
            static function (StoreVerdict $verdict) {
                return !$verdict->isHealthy();
            }
        ));
    }

    /**
     * An install with TaxCloud enabled nowhere is healthy by definition: there
     * is no store whose tax the extension was expected to calculate.
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        return $this->getUnhealthyStoreVerdicts() === [];
    }

    /**
     * Whether any store's verdict could not be computed at all.
     *
     * @return bool
     */
    public function hasFailures(): bool
    {
        foreach ($this->storeVerdicts as $verdict) {
            if ($verdict->isFailure()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fingerprint of the conflict currently being reported.
     *
     * Dismissing the admin notification acknowledges THIS value rather than
     * setting a permanent boolean the way core's tax notification does. A
     * different responsible class, or the same conflict reaching a store it did
     * not affect before, produces a different fingerprint and re-raises the
     * notification without anyone having to notice — which is the entire point
     * of the feature, so a dismissal must not be able to outlive its conflict.
     *
     * Covers everything the notification displays: the affected store ids and,
     * per store, the classes named in the message.
     *
     * @return string Empty string when the verdict is healthy — nothing to acknowledge
     */
    public function fingerprint(): string
    {
        $unhealthy = $this->getUnhealthyStoreVerdicts();
        if ($unhealthy === []) {
            return '';
        }

        $material = [];
        foreach ($unhealthy as $verdict) {
            $material[$verdict->getStoreId()] = $verdict->getConflictSignature();
        }
        ksort($material);

        return hash('sha256', (string) json_encode($material));
    }

    /**
     * Ids of the stores this verdict covers.
     *
     * @return int[]
     */
    public function getStoreIds(): array
    {
        return array_map(
            static function (StoreVerdict $verdict) {
                return $verdict->getStoreId();
            },
            $this->storeVerdicts
        );
    }

    /**
     * Verdict for one store, or null if that store was not evaluated.
     *
     * @param int $storeId
     * @return StoreVerdict|null
     */
    public function forStore(int $storeId): ?StoreVerdict
    {
        foreach ($this->storeVerdicts as $verdict) {
            if ($verdict->getStoreId() === $storeId) {
                return $verdict;
            }
        }

        return null;
    }
}
