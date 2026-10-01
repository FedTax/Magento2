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
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Deleting a certificate from the customer's admin page.
 *
 * The rule worth pinning is what happens to the attachment. Deleting the
 * certificate in use also clears it, after TaxCloud has accepted the deletion:
 * an attachment left naming a certificate that no longer exists exempts
 * nothing, and would stop the next certificate created for the customer from
 * being attached. A deletion TaxCloud refuses leaves the exemption as it was.
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

        return new CertificateResolver($this->repository(), new TaxCloudCustomerIdentity());
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

        $user = $this->createStub(\Magento\User\Model\User::class);
        $user->method('getUserName')->willReturn('alice');
        $auth = $this->createStub(\Magento\Backend\Model\Auth::class);
        $auth->method('getUser')->willReturn($user);

        $context = $this->createStub(Context::class);
        $context->method('getAuth')->willReturn($auth);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return $context;
    }

    /**
     * @param CertificateRepository|null $certificates
     * @param CertificateAttachment|null $attachment
     */
    private function controller($certificates = null, $attachment = null): Delete
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
            $attachment ?? $this->createStub(CertificateAttachment::class)
        );
    }

    public function testDeletingTheCertificateInUseAlsoClearsTheAttachment(): void
    {
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-tx';

        $deleting = $this->createMock(CertificateRepository::class);
        $deleting->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });
        $deleting->expects($this->once())->method('delete');

        $attachment = $this->createMock(CertificateAttachment::class);
        $attachment->expects($this->once())
            ->method('set')
            ->with($this->anything(), '', 'alice', 1)
            ->willReturn(true);

        $this->controller($deleting, $attachment)->execute();

        $this->assertTrue($this->answer['success']);
        $this->assertTrue(
            $this->answer['detached'],
            'an attachment left naming a deleted certificate exempts nothing and blocks the next one'
        );
    }

    public function testARefusedDeletionLeavesTheAttachmentAlone(): void
    {
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-tx';

        $deleting = $this->createStub(CertificateRepository::class);
        $deleting->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });
        $deleting->method('delete')->willThrowException(new \RuntimeException('TaxCloud said no'));

        $attachment = $this->createMock(CertificateAttachment::class);
        $attachment->expects($this->never())->method('set');

        $this->controller($deleting, $attachment)->execute();

        $this->assertFalse(
            $this->answer['success'],
            'a certificate that still exists must stay in force'
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

        $attachment = $this->createMock(CertificateAttachment::class);
        $attachment->expects($this->never())->method('set');

        $this->controller($deleting, $attachment)->execute();

        $this->assertTrue($this->answer['success']);
    }

    public function testDeletingAnotherCertificateKeepsTheOneInUse(): void
    {
        $this->held[] = new Certificate('cert-tx-2', '7', ['TX'], false, false);
        $this->attached = 'cert-tx';
        $this->params['certificate_id'] = 'cert-tx-2';

        $deleting = $this->createMock(CertificateRepository::class);
        $deleting->method('forCustomer')->willReturnCallback(function () {
            return $this->held;
        });
        $deleting->expects($this->once())->method('delete');

        $attachment = $this->createMock(CertificateAttachment::class);
        $attachment->expects($this->never())->method('set');

        $this->controller($deleting, $attachment)->execute();

        $this->assertTrue($this->answer['success']);
    }

    public function testAForeignCertificateIsStillRefused(): void
    {
        $this->attached = '';
        $this->params['certificate_id'] = 'someone-elses';

        $this->controller()->execute();

        $this->assertFalse($this->answer['success']);
    }
}
