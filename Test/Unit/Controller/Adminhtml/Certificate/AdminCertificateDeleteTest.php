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


namespace Taxcloud\Magento2\Test\Unit\Controller\Adminhtml\Certificate;

use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Controller\Adminhtml\Certificate\Delete;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateAttachment;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Deleting a certificate from the customer's admin page.
 *
 * The rule worth pinning is that the certificate currently IN USE cannot be
 * deleted. Deletion is irreversible at TaxCloud, and doing it to the one the
 * customer's orders are filed against leaves the attachment pointing at
 * something that no longer exists: the customer quietly stops being exempt,
 * with nothing on the screen having said so. Clearing the attachment first
 * makes that an explicit act.
 *
 * The panel also disables the button, but the endpoint is what enforces it — a
 * disabled button is a courtesy, not a control.
 */
class AdminCertificateDeleteTest extends TestCase
{
    /** @var array<string, mixed> */
    private $answer = [];

    /** @var array<string, mixed> */
    private $params = ['customer_id' => 7];

    /** @var Certificate[] */
    private $held = [];

    /** @var string */
    private $attached = '';

    protected function setUp(): void
    {
        $this->answer = [];
        $this->params = ['customer_id' => 7];
        $this->held = [new Certificate('cert-tx', '7', ['TX'], false, false)];
        $this->attached = '';
    }

    private function customer()
    {
        $customer = $this->createStub(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getGroupId')->willReturn(1);
        $customer->method('getStoreId')->willReturn(1);

        $attached = $this->attached;
        $customer->method('getCustomAttribute')->willReturnCallback(function ($code) use ($attached) {
            if ($code !== CertificateResolver::ATTACHED_ATTRIBUTE || $attached === '') {
                return null;
            }

            $attribute = $this->createStub(\Magento\Framework\Api\AttributeInterface::class);
            $attribute->method('getValue')->willReturn($attached);

            return $attribute;
        });

        return $customer;
    }

    private function repository(): CertificateRepository
    {
        $repository = $this->createStub(CertificateRepository::class);
        $repository->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });

        return $repository;
    }

    private function resolver(): CertificateResolver
    {
        $config = $this->createStub(\Taxcloud\Magento2\Model\Config\TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('areExemptionsEnabled')->willReturn(true);
        $config->method('getExemptCustomerGroups')->willReturn([]);
        $config->method('isRestrictedToExemptGroups')->willReturn(false);

        return new CertificateResolver(
            $this->repository(),
            new TaxCloudCustomerIdentity(),
            new ExemptionPolicy($config)
        );
    }

    private function context(): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(function ($name, $default = null) {
            return $this->params[$name] ?? $default;
        });

        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->answer = $data;

            return $json;
        });

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($json);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return $context;
    }

    /**
     * @param CertificateRepository|null $certificates
     */
    private function controller($certificates = null): Delete
    {
        $customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturnCallback(function () {
            return $this->customer();
        });

        return new Delete(
            $this->context(),
            $customerRepository,
            $certificates ?? $this->repository(),
            $this->resolver(),
            new TaxCloudCustomerIdentity(),
            $this->createStub(CertificateAttachment::class)
        );
    }

    public function testTheCertificateInUseCannotBeDeleted(): void
    {
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-tx';

        $deleting = $this->createMock(CertificateRepository::class);
        $deleting->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });
        $deleting->expects($this->never())->method('delete');

        $this->controller($deleting)->execute();

        $this->assertFalse($this->answer['success']);
        $this->assertStringContainsString(
            'Stop using',
            $this->answer['message'],
            'the refusal must name the control that unblocks it, or it is a dead end'
        );
    }

    public function testACertificateNotInUseCanBeDeleted(): void
    {
        $this->attached = '';
        $this->params['certificate_id'] = 'cert-tx';

        $deleting = $this->createMock(CertificateRepository::class);
        $deleting->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });
        $deleting->expects($this->once())->method('delete');

        $this->controller($deleting)->execute();

        $this->assertTrue($this->answer['success']);
    }

    public function testAnotherCertificateIsStillDeletableWhileOneIsInUse(): void
    {
        // Only the certificate in force is protected — the rule is about the
        // consequence of deleting THAT one, not about locking the whole set.
        $this->held[] = new Certificate('cert-tx-2', '7', ['TX'], false, false);
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-tx-2';

        $deleting = $this->createMock(CertificateRepository::class);
        $deleting->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });
        $deleting->expects($this->once())->method('delete');

        $this->controller($deleting)->execute();

        $this->assertTrue($this->answer['success']);
    }

    public function testAForeignCertificateIsStillRefused(): void
    {
        $this->attached = '';
        $this->params['certificate_id'] = 'someone-elses';

        $this->controller()->execute();

        $this->assertFalse($this->answer['success']);
        $this->assertStringNotContainsString(
            'Stop using',
            $this->answer['message'],
            'ownership and in-use are different refusals and must not be conflated'
        );
    }
}
