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
 * Supplementary context: third-party collectors ordered after the tax total,
 * which are in a position to overwrite what tax calculation produced.
 *
 * Advisory only, and deliberately never raises the banner by itself. Loyalty,
 * gift card and fee modules sit here on perfectly healthy installs, so treating
 * their presence as a fault would train admins to dismiss a banner that
 * sometimes carries a real finding. It appears only alongside a store that is
 * already unhealthy for another reason, where it narrows the search.
 */
class CollectorOverwritten extends AbstractCollectorNotification
{
    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'TAXCLOUD_COLLECTOR_OVERWRITTEN';
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
            $perStore[] = $storeVerdict->getLaterCollectors();
        }
        $classes = array_merge([], ...$perStore);

        return '<p>' . __(
            'Also worth checking: these totals run after tax and could change it: %1.',
            $this->formatClasses(array_values(array_unique($classes)))
        ) . '</p>';
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
                return $storeVerdict->getLaterCollectors() !== [];
            }
        ));
    }
}
