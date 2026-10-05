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

use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Every customer group a rule can filter on, guests (NOT LOGGED IN) included —
 * unlike the certificate self-service setting, an order rule applies to guest
 * orders too.
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
     * @return array
     */
    public function toOptionArray()
    {
        $guest = $this->groupManagement->getNotLoggedInGroup();
        $options = [['value' => (string) $guest->getId(), 'label' => (string) $guest->getCode()]];
        foreach ($this->groupManagement->getLoggedInGroups() as $group) {
            $options[] = ['value' => (string) $group->getId(), 'label' => (string) $group->getCode()];
        }
        return $options;
    }
}
