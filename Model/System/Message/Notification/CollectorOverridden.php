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
 * The load-bearing finding: another module occupies the tax total collector,
 * so TaxCloud's calculation never runs at all.
 */
class CollectorOverridden extends AbstractCollectorNotification
{
    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'TAXCLOUD_COLLECTOR_OVERRIDDEN';
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

        $classes = [];
        foreach ($affected as $storeVerdict) {
            $class = $storeVerdict->getActiveCollectorClass();
            $classes[] = $class === null ? (string) __('none') : $class;
        }

        return $this->render(
            (string) __(
                'TaxCloud is enabled but is not calculating tax. '
                . 'Another module has taken over tax calculation.'
            ),
            $affected,
            (string) __(
                'The tax calculation currently in use is: %1. Until this is resolved, TaxCloud does not '
                . 'calculate or file tax for the affected stores.',
                $this->formatClasses(array_unique($classes))
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
                return !$storeVerdict->isFailure() && !$storeVerdict->isOwned();
            }
        ));
    }
}
