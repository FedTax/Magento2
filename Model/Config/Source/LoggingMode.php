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

namespace Taxcloud\Magento2\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Options for the "Logging" setting.
 *
 * The stored values are chosen so pre-existing installs keep their behavior
 * without a data migration: 1 (the old "Enable") becomes Basic, 0 stays
 * Disable. Advanced is the new value 2.
 */
class LoggingMode implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => (string) TaxcloudConfig::LOGGING_BASIC, 'label' => __('Enable - Basic')],
            ['value' => (string) TaxcloudConfig::LOGGING_ADVANCED, 'label' => __('Enable - Advanced')],
            ['value' => (string) TaxcloudConfig::LOGGING_DISABLED, 'label' => __('Disable')],
        ];
    }
}
