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

namespace Taxcloud\Magento2\Test\Unit\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\Api\AttributeInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\AttachmentWriteScope;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Plugin\Customer\GuardCertificateAttachment;

/**
 * Which customer saves may change the attached certificate.
 *
 * The attachment decides whether a customer is taxed, and the customer APIs
 * carry it like any other attribute. Only CertificateAttachment — whose callers
 * check permission and ownership — and permitted backend contexts may change
 * it; everything else is reverted without failing the rest of the save.
 */
class GuardCertificateAttachmentTest extends TestCase
{
    /** @var string|null What the save carries; null when it carries nothing */
    private $submitted;

    /** @var string What the database holds */
    private $stored = '';

    /** @var bool Whether the request is a permitted non-customer-facing one */
    private $writeAllowed = false;

    /** @var string|null What the plugin put back on the customer */
    private $restored;

    /** @var string[] */
    private $warnings = [];

    /** @var int */
    private $loads = 0;

    protected function setUp(): void
    {
        $this->submitted = null;
        $this->stored = '';
        $this->writeAllowed = false;
        $this->restored = null;
        $this->warnings = [];
        $this->loads = 0;
    }

    private function customer(?int $id = 7): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getCustomAttribute')->willReturnCallback(function ($code) {
            if ($code !== CertificateResolver::ATTACHED_ATTRIBUTE || $this->submitted === null) {
                return null;
            }

            $attribute = $this->createStub(AttributeInterface::class);
            $attribute->method('getValue')->willReturn($this->submitted);

            return $attribute;
        });
        $customer->method('setCustomAttribute')->willReturnCallback(function ($code, $value) {
            if ($code === CertificateResolver::ATTACHED_ATTRIBUTE) {
                $this->restored = (string) $value;
            }

            return null;
        });

        return $customer;
    }

    private function plugin(AttachmentWriteScope $scope): GuardCertificateAttachment
    {
        $persisted = $this->createStub(Customer::class);
        $persisted->method('load')->willReturnCallback(function () use ($persisted) {
            $this->loads++;

            return $persisted;
        });
        $persisted->method('getId')->willReturn(7);
        $persisted->method('getData')->willReturnCallback(function ($key = '') {
            return $key === CertificateResolver::ATTACHED_ATTRIBUTE ? $this->stored : null;
        });

        $factory = $this->createStub(CustomerFactory::class);
        $factory->method('create')->willReturn($persisted);

        $guard = $this->createStub(CustomerIdentityGuard::class);
        $guard->method('isWriteAllowed')->willReturnCallback(function () {
            return $this->writeAllowed;
        });

        $logger = $this->createStub(GatewayLogger::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->warnings[] = (string) $message;
        });

        return new GuardCertificateAttachment($factory, $guard, $scope, $logger);
    }

    private function save(?AttachmentWriteScope $scope = null, ?int $customerId = 7): void
    {
        $scope = $scope ?? new AttachmentWriteScope();
        $this->plugin($scope)->beforeSave(
            $this->createStub(CustomerRepositoryInterface::class),
            $this->customer($customerId)
        );
    }

    public function testACustomerFacingChangeIsReverted(): void
    {
        // A signed-in customer sending custom_attributes to /V1/customers/me.
        $this->stored = 'cert-assigned';
        $this->submitted = 'cert-chosen-by-customer';

        $this->save();

        $this->assertSame('cert-assigned', $this->restored);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('customer 7', $this->warnings[0]);
    }

    public function testClearingIsRefusedToo(): void
    {
        $this->stored = 'cert-assigned';
        $this->submitted = '';

        $this->save();

        $this->assertSame('cert-assigned', $this->restored);
    }

    public function testANewCustomerCannotBeCreatedWithAnAttachment(): void
    {
        $this->submitted = 'cert-someone-elses';

        $this->save(null, null);

        $this->assertSame('', $this->restored);
    }

    public function testCertificateManagementMayChangeIt(): void
    {
        $this->stored = 'cert-old';
        $this->submitted = 'cert-new';
        $scope = new AttachmentWriteScope();

        $scope->run(function () use ($scope) {
            $this->save($scope);
        });

        $this->assertNull($this->restored, 'CertificateAttachment has already checked who is asking');
        $this->assertSame([], $this->warnings);
    }

    public function testAPermittedBackendContextMayChangeIt(): void
    {
        // An administrator holding the certificate permission, or CLI / cron.
        $this->stored = 'cert-old';
        $this->submitted = 'cert-new';
        $this->writeAllowed = true;

        $this->save();

        $this->assertNull($this->restored);
    }

    public function testAnUnchangedValuePassesWithoutComplaint(): void
    {
        // Every ordinary account edit carries the stored value back.
        $this->stored = 'cert-assigned';
        $this->submitted = 'cert-assigned';

        $this->save();

        $this->assertNull($this->restored);
        $this->assertSame([], $this->warnings);
    }

    public function testASaveThatDoesNotMentionTheAttributeCostsNoLoad(): void
    {
        $this->submitted = null;

        $this->save();

        $this->assertSame(0, $this->loads);
        $this->assertNull($this->restored);
    }
}
