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
 * The check itself could not run.
 *
 * Building the collector list instantiates third-party collectors, so a broken
 * one elsewhere on the install can take the probe down with it. Reported rather
 * than swallowed: "we cannot tell whether TaxCloud is calculating" is itself
 * something a merchant needs to know.
 */
class DiagnosticsUnavailable extends AbstractCollectorNotification
{
    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'TAXCLOUD_COLLECTOR_DIAGNOSTICS_UNAVAILABLE';
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

        $reasons = [];
        foreach ($affected as $storeVerdict) {
            $reasons[] = (string) $storeVerdict->getFailureReason();
        }

        return $this->render(
            (string) __('TaxCloud could not verify that it is the active tax calculation.'),
            $affected,
            (string) __('The check failed with: %1', $this->formatClasses(array_values(array_unique($reasons))))
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
                return $storeVerdict->isFailure();
            }
        ));
    }
}
