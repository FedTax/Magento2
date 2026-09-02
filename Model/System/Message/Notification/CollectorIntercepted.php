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

namespace Taxcloud\Magento2\Model\System\Message\Notification;

use Taxcloud\Magento2\Model\Diagnostics\CollectorVerdict;
use Taxcloud\Magento2\Model\Diagnostics\StoreVerdict;

/**
 * TaxCloud still owns the collector slot, but something wraps its collect().
 *
 * An `around` plugin that never calls $proceed suppresses the calculation while
 * every other check reads green — which is precisely why this is reported
 * separately rather than folded into the override finding.
 */
class CollectorIntercepted extends AbstractCollectorNotification
{
    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'TAXCLOUD_COLLECTOR_INTERCEPTED';
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed(CollectorVerdict $verdict)
    {
        return $this->affected($verdict) !== [];
    }

    /**
     * @inheritDoc
     */
    public function getText(CollectorVerdict $verdict)
    {
        $affected = $this->affected($verdict);
        if ($affected === []) {
            return '';
        }

        $perStore = [];
        foreach ($affected as $storeVerdict) {
            $perStore[] = $storeVerdict->getInterceptors();
        }
        $classes = array_merge([], ...$perStore);

        return $this->render(
            (string) __('Another module is intercepting TaxCloud tax calculation.'),
            $affected,
            (string) __(
                'TaxCloud is still the configured tax calculation, but these plugins wrap it and can '
                . 'prevent it from running: %1.',
                $this->formatClasses(array_values(array_unique($classes)))
            )
        );
    }

    /**
     * @param CollectorVerdict $verdict
     * @return StoreVerdict[]
     */
    private function affected(CollectorVerdict $verdict): array
    {
        return array_values(array_filter(
            $verdict->getUnhealthyStoreVerdicts(),
            static function (StoreVerdict $storeVerdict) {
                return !$storeVerdict->isFailure() && $storeVerdict->getInterceptors() !== [];
            }
        ));
    }
}
