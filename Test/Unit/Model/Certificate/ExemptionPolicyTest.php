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

namespace Taxcloud\Magento2\Test\Unit\Model\Certificate;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * The gate in front of every exemption surface.
 *
 * The default matters more than any single rule here: a store that upgrades and
 * changes nothing must offer nothing. Certificates are attestations nobody
 * verifies — a certificate created with an invented tax id is accepted — so the
 * feature arriving switched on would hand every signed-in shopper a way to stop
 * paying tax.
 */
class ExemptionPolicyTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function policy(array $settings = []): ExemptionPolicy
    {
        $config = $this->createStub(TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn($settings['moduleEnabled'] ?? true);
        $config->method('areExemptionsEnabled')->willReturn($settings['exemptions'] ?? false);
        $config->method('getExemptCustomerGroups')->willReturn($settings['groups'] ?? []);
        $config->method('isRestrictedToExemptGroups')->willReturn($settings['restricted'] ?? false);

        return new ExemptionPolicy($config);
    }

    private function customer($groupId = 1, $entityId = 42)
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn($entityId);
        $customer->method('getGroupId')->willReturn($groupId);

        return $customer;
    }

    /**
     * The single most important assertion in this file.
     */
    public function testEverythingIsOffByDefault()
    {
        $policy = $this->policy();

        $this->assertFalse($policy->isEnabled());
        $this->assertFalse($policy->isVisibleTo($this->customer()));
        $this->assertFalse($policy->isTreatedAsExempt($this->customer()));
        $this->assertFalse($policy->mayCreate($this->customer()));
    }

    /**
     * The module's own master switch still wins: exemptions cannot be offered
     * by a store that is not using TaxCloud at all.
     */
    public function testDisablingTheModuleDisablesExemptions()
    {
        $policy = $this->policy(['moduleEnabled' => false, 'exemptions' => true, 'groups' => [7]]);

        $this->assertFalse($policy->isEnabled());
        $this->assertFalse($policy->isTreatedAsExempt($this->customer(7)));
    }

    public function testEnabledAndUnrestrictedIsVisibleToAnySignedInCustomer()
    {
        $policy = $this->policy(['exemptions' => true]);

        $this->assertTrue($policy->isVisibleTo($this->customer(1)));
    }

    public function testRestrictedModeHidesTheInterfaceOutsideTheGroups()
    {
        $policy = $this->policy(['exemptions' => true, 'groups' => [7], 'restricted' => true]);

        $this->assertTrue($policy->isVisibleTo($this->customer(7)));
        $this->assertFalse($policy->isVisibleTo($this->customer(1)));
    }

    public function testOnlyExemptGroupCustomersAreTreatedAsExempt()
    {
        $policy = $this->policy(['exemptions' => true, 'groups' => [7, 9]]);

        $this->assertTrue($policy->isTreatedAsExempt($this->customer(9)));
        $this->assertFalse($policy->isTreatedAsExempt($this->customer(1)));
    }

    /**
     * Seeing the interface and creating a certificate are different acts.
     * Creating one asserts an exemption nobody checks, so it stays with
     * customers the merchant has vouched for — even where the interface itself
     * is open to everyone.
     */
    public function testCreationIsNarrowerThanVisibility()
    {
        $policy = $this->policy(['exemptions' => true, 'groups' => [7]]);

        $this->assertTrue($policy->isVisibleTo($this->customer(1)), 'unrestricted: visible to all');
        $this->assertFalse($policy->mayCreate($this->customer(1)), 'but creation needs an exempt group');
        $this->assertTrue($policy->mayCreate($this->customer(7)));
    }

    /**
     * Nominating no groups is not "everyone is exempt" — it is "nobody is",
     * which is the safe reading of an unconfigured setting.
     */
    public function testNoNominatedGroupsMeansNobodyIsAutoExempt()
    {
        $policy = $this->policy(['exemptions' => true, 'groups' => []]);

        $this->assertFalse($policy->isTreatedAsExempt($this->customer(1)));
        $this->assertFalse($policy->mayCreate($this->customer(1)));
    }

    public function testGuestsAreNeverExempt()
    {
        $policy = $this->policy(['exemptions' => true, 'groups' => [0, 1, 7]]);

        $this->assertFalse($policy->isVisibleTo(null));
        $this->assertFalse($policy->isTreatedAsExempt(null));
        $this->assertFalse($policy->mayCreate(null));
        $this->assertFalse(
            $policy->isTreatedAsExempt($this->customer(0, null)),
            'a customer object without an entity id is a guest'
        );
    }
}
