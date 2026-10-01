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

namespace Taxcloud\Magento2\Test\Unit\Model\Config\Source;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\Source\CustomerGroups;

/**
 * The groups offered for certificate self-service are the signed-in ones
 * Magento reports — never NOT LOGGED IN, which has no My Account.
 */
class CustomerGroupsTest extends TestCase
{
    private function group(int $id, string $code): GroupInterface
    {
        $group = $this->createStub(GroupInterface::class);
        $group->method('getId')->willReturn($id);
        $group->method('getCode')->willReturn($code);

        return $group;
    }

    public function testOffersTheSignedInGroups(): void
    {
        $management = $this->createMock(GroupManagementInterface::class);
        $management->expects($this->once())
            ->method('getLoggedInGroups')
            ->willReturn([$this->group(1, 'General'), $this->group(2, 'Wholesale')]);
        $management->expects($this->never())->method('getNotLoggedInGroup');

        $this->assertSame(
            [
                ['value' => '1', 'label' => 'General'],
                ['value' => '2', 'label' => 'Wholesale'],
            ],
            (new CustomerGroups($management))->toOptionArray()
        );
    }
}
