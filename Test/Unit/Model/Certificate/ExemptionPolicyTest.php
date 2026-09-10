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
        $config->method('isEnabled')->willReturn($settings['module'] ?? true);
        $config->method('areExemptionsEnabled')->willReturn($settings['exemptions'] ?? false);

        return new ExemptionPolicy($config);
    }

    private function customer($entityId = 1)
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn($entityId);

        return $customer;
    }

    public function testEverythingIsOffByDefault()
    {
        $this->assertFalse($this->policy()->isEnabled());
        $this->assertFalse($this->policy()->isVisibleTo($this->customer()));
    }

    public function testDisablingTheModuleDisablesExemptions()
    {
        $policy = $this->policy(['module' => false, 'exemptions' => true]);

        $this->assertFalse($policy->isEnabled());
        $this->assertFalse($policy->isVisibleTo($this->customer()));
    }

    public function testAnySignedInCustomerSeesTheInterfaceWhenItIsOn()
    {
        // There is no narrower notion of who exemptions are "for": a
        // certificate belongs to a customer, and an administrator decides which
        // one applies by attaching it.
        $this->assertTrue($this->policy(['exemptions' => true])->isVisibleTo($this->customer()));
    }

    public function testGuestsNeverSeeIt()
    {
        $policy = $this->policy(['exemptions' => true]);

        $this->assertFalse($policy->isVisibleTo(null));
        $this->assertFalse($policy->isVisibleTo($this->customer(null)));
    }
}
