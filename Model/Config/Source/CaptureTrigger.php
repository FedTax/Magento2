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

class CaptureTrigger implements OptionSourceInterface
{
    const ORDER_CREATION = 'order_creation';
    const PAYMENT = 'payment';
    const SHIPMENT = 'shipment';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::ORDER_CREATION, 'label' => __('On order creation')],
            ['value' => self::PAYMENT, 'label' => __('On payment')],
            ['value' => self::SHIPMENT, 'label' => __('On shipment')],
        ];
    }
}
