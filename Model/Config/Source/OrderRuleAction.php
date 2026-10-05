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

namespace Taxcloud\Magento2\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;

/**
 * What an order processing rule does with the orders it matches.
 */
class OrderRuleAction implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => OrderRuleInterface::ACTION_REPORT, 'label' => __('Report to TaxCloud')],
            ['value' => OrderRuleInterface::ACTION_CALCULATE_ONLY, 'label' => __('Calculate only')],
            ['value' => OrderRuleInterface::ACTION_SKIP, 'label' => __('Skip TaxCloud')],
        ];
    }

    /**
     * Label for one action, or the raw value when unknown.
     *
     * @param string $action
     * @return string
     */
    public function getLabel(string $action): string
    {
        foreach ($this->toOptionArray() as $option) {
            if ($option['value'] === $action) {
                return (string) $option['label'];
            }
        }
        return $action;
    }
}
