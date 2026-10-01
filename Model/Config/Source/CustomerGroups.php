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

use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Customer groups that can be nominated for certificate self-service.
 *
 * Signed-in groups only: NOT LOGGED IN has no My Account to manage anything
 * from, so offering it would be a choice that can never take effect.
 */
class CustomerGroups implements OptionSourceInterface
{
    /**
     * @var GroupManagementInterface
     */
    private $groupManagement;

    /**
     * @param GroupManagementInterface $groupManagement
     */
    public function __construct(GroupManagementInterface $groupManagement)
    {
        $this->groupManagement = $groupManagement;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray()
    {
        $options = [];

        foreach ($this->groupManagement->getLoggedInGroups() as $group) {
            $options[] = [
                'value' => (string) $group->getId(),
                'label' => (string) $group->getCode(),
            ];
        }

        return $options;
    }
}
