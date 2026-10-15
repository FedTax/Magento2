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

/**
 * Options for "Report orders to TaxCloud".
 *
 * Deliberately inverted: the field is stored at
 * tax/taxcloud_settings/calculations_only, where 1 has always meant "never
 * report". Keeping that path and its values means existing scope overrides,
 * deploy scripts and config.php entries need no migration — so "Yes, report"
 * is the stored 0 and "No" is the stored 1.
 */
class ReportOrders implements OptionSourceInterface
{
    public const REPORT = '0';
    public const DO_NOT_REPORT = '1';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::REPORT, 'label' => __('Yes')],
            ['value' => self::DO_NOT_REPORT, 'label' => __('No')],
        ];
    }
}
