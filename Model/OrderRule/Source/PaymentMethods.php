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
use Magento\Payment\Model\Config\Source\Allmethods;

/**
 * Every payment method defined in configuration, active or not — marketplace
 * importers register methods that are never offered at checkout.
 */
class PaymentMethods implements OptionSourceInterface
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
        return $this->normalise($this->allMethods->toOptionArray());
    }

    /**
     * Core keys each group's methods by method code, and lists groups that
     * have no methods with a null value. The admin multiselect only renders
     * plain lists, so a keyed group shows as a lone, unselectable heading.
     *
     * @param array $options
     * @return array
     */
    private function normalise(array $options): array
    {
        $result = [];
        foreach ($options as $option) {
            $value = $option['value'] ?? null;
            if (is_array($value)) {
                $children = $this->normalise($value);
                if ($children) {
                    $result[] = ['label' => $option['label'] ?? '', 'value' => $children];
                }
                continue;
            }
            if ($value !== null && $value !== '') {
                $result[] = $option;
            }
        }
        return $result;
    }
}
