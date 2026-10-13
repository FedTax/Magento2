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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\OrderRule\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Shipping\Model\Config\Source\Allmethods;

/**
 * Every shipping method of every carrier, active or not, grouped by carrier.
 *
 * Inactive carriers are included on purpose: a marketplace importer's carrier
 * is often never enabled for checkout, yet its orders are exactly what a rule
 * needs to recognise.
 */
class ShippingMethods implements OptionSourceInterface
{
    /**
     * @var Allmethods
     */
    private $allMethods;

    /**
     * @param Allmethods $allMethods
     */
    public function __construct(Allmethods $allMethods)
    {
        $this->allMethods = $allMethods;
    }

    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        foreach ($this->allMethods->toOptionArray(false) as $group) {
            // Core leads with a blank option; a multiselect has no use for it.
            if (!is_array($group['value'] ?? null) || !$group['value']) {
                continue;
            }
            $options[] = $group;
        }
        return $options;
    }
}
