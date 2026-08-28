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

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * The identity is what decides whose certificates a customer resolves, and
 * TaxCloud enforces no ownership of its own — so these tests cover both halves
 * of the guarantee: the resolution defaults that keep existing installs
 * working, and the guard that keeps the value out of a customer's hands.
 */
class TaxCloudCustomerIdentityTest extends TestCase
{
    /**
     * @param string|null $configured Null = attribute absent entirely
     * @param int|null $entityId
     * @return \Magento\Customer\Api\Data\CustomerInterface
     */
    private function customer($configured = null, $entityId = 42)
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn($entityId);

        if ($configured === null) {
            $customer->method('getCustomAttribute')->willReturn(null);
            return $customer;
        }

        $attribute = $this->createStub(\Magento\Framework\Api\AttributeInterface::class);
        $attribute->method('getValue')->willReturn($configured);
        $customer->method('getCustomAttribute')->willReturn($attribute);

        return $customer;
    }

    /**
     * The compatibility guarantee: before this attribute existed the module
     * queried the entity id, so an unset identity must still query it.
     */
    public function testDefaultsToTheMagentoEntityId()
    {
        $identity = new TaxCloudCustomerIdentity();

        $this->assertSame('42', $identity->resolve($this->customer(null)));
        $this->assertSame('42', $identity->resolve($this->customer('')));
        $this->assertSame('42', $identity->resolve($this->customer('   ')));
        $this->assertFalse($identity->isOverridden($this->customer(null)));
    }

    public function testConfiguredValueWins()
    {
        $identity = new TaxCloudCustomerIdentity();

        $this->assertSame('acme-corp', $identity->resolve($this->customer('acme-corp')));
        $this->assertTrue($identity->isOverridden($this->customer('acme-corp')));
    }

    public function testConfiguredValueIsTrimmed()
    {
        $identity = new TaxCloudCustomerIdentity();

        $this->assertSame('acme-corp', $identity->resolve($this->customer('  acme-corp  ')));
    }

    /**
     * Supported on purpose: several buyers at one company, one company
     * certificate. It is also why the write guard matters.
     */
    public function testTwoCustomersMayShareOneIdentity()
    {
        $identity = new TaxCloudCustomerIdentity();

        $this->assertSame(
            $identity->resolve($this->customer('acme-corp', 42)),
            $identity->resolve($this->customer('acme-corp', 77))
        );
    }

    /**
     * An empty identity must never be used to query: it would match whatever
     * TaxCloud files under the empty string, which is not this customer's.
     */
    public function testNoCustomerResolvesToNothing()
    {
        $identity = new TaxCloudCustomerIdentity();

        $this->assertSame('', $identity->resolve(null));
        $this->assertSame('', $identity->resolve($this->customer(null, null)));
    }

    // ─── the write guard ─────────────────────────────────────────────────

    /**
     * @param string|null $area Null = no area set (CLI / setup)
     * @param bool $allowed
     * @return CustomerIdentityGuard
     */
    private function guard($area, bool $allowed = true)
    {
        $state = $this->createStub(State::class);
        if ($area === null) {
            $state->method('getAreaCode')->willThrowException(new LocalizedException(__('no area')));
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn($allowed);

        return new CustomerIdentityGuard($state, $authorization);
    }

    /**
     * The attack this exists to stop: a customer setting their own identity to
     * another customer's, and inheriting their exemptions. Hiding the form
     * field would not prevent it — a crafted submission or a customer-token
     * API call would still carry the attribute.
     *
     * @dataProvider customerFacingAreaProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('customerFacingAreaProvider')]
    public function testCustomerFacingWritesAreRefused(string $area)
    {
        // Even with authorization saying yes, the area alone refuses.
        $this->assertFalse($this->guard($area, true)->isWriteAllowed(), $area . ' must not be able to write');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function customerFacingAreaProvider(): array
    {
        return [
            'storefront' => [Area::AREA_FRONTEND],
            'rest api' => [Area::AREA_WEBAPI_REST],
            'soap api' => [Area::AREA_WEBAPI_SOAP],
            'graphql' => [Area::AREA_GRAPHQL],
        ];
    }

    public function testAdminWriteRequiresThePermission()
    {
        $this->assertTrue($this->guard(Area::AREA_ADMINHTML, true)->isWriteAllowed());
        $this->assertFalse(
            $this->guard(Area::AREA_ADMINHTML, false)->isWriteAllowed(),
            'an administrator without the certificate permission must not set the identity'
        );
    }

    /**
     * Data patches, seed scripts and console commands have no area and no
     * customer driving them.
     */
    public function testBackendContextsMayWrite()
    {
        $this->assertTrue($this->guard(null)->isWriteAllowed(), 'CLI / setup');
        $this->assertTrue($this->guard(Area::AREA_CRONTAB)->isWriteAllowed(), 'cron');
    }
}
