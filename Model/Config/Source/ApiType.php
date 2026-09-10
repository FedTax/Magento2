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

use \Magento\Framework\Data\OptionSourceInterface;

class ApiType implements OptionSourceInterface
{
    public const SOAP = 'soap';
    public const REST = 'rest';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::SOAP, 'label' => __('V1 SOAP (legacy)')],
            ['value' => self::REST, 'label' => __('V3 REST')],
        ];
    }
}
