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

namespace Taxcloud\Magento2\Controller\Adminhtml\Certificate;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Controller\ResultFactory;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Shared plumbing for the admin certificate endpoints.
 *
 * Everything here exists so the individual actions stay short enough to read in
 * one go — and so that none of them has a reason to decide anything about
 * ownership or permissions for itself.
 *
 * The ACL resource is the certificate one rather than the customer one. Setting
 * a customer's exemptions is not an ordinary customer edit: it stops tax being
 * collected, and TaxCloud performs no check of its own on whose certificate is
 * applied to whose order.
 */
abstract class AbstractCertificateAction extends Action
{
    /**
     * @see \Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard::ACL_RESOURCE
     */
    public const ADMIN_RESOURCE = CustomerIdentityGuard::ACL_RESOURCE;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var CertificateRepository
     */
    protected $certificates;

    /**
     * @var CertificateResolver
     */
    protected $resolver;

    /**
     * @var TaxCloudCustomerIdentity
     */
    protected $identity;

    /**
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param CertificateRepository $certificates
     * @param CertificateResolver $resolver
     * @param TaxCloudCustomerIdentity $identity
     */
    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        CertificateRepository $certificates,
        CertificateResolver $resolver,
        TaxCloudCustomerIdentity $identity
    ) {
        parent::__construct($context);
        $this->customerRepository = $customerRepository;
        $this->certificates = $certificates;
        $this->resolver = $resolver;
        $this->identity = $identity;
    }

    /**
     * The customer this request concerns, or null when it names none.
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface|null
     */
    protected function customer()
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id');
        if ($customerId <= 0) {
            return null;
        }

        try {
            return $this->customerRepository->getById($customerId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * The store whose TaxCloud account this request is about.
     *
     * Certificates belong to an account, and a multi-store install may point
     * stores at different accounts — so "this customer's certificates" is only
     * a well-formed question once a store is named. Falls back to the
     * customer's own store.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @return int|null
     */
    protected function storeId($customer)
    {
        $requested = $this->getRequest()->getParam('store_id');
        if ($requested !== null && $requested !== '') {
            return (int) $requested;
        }

        return $customer ? (int) $customer->getStoreId() : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return \Magento\Framework\Controller\Result\Json
     */
    protected function json(array $payload)
    {
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        return $result->setData($payload);
    }

    /**
     * @param string $message
     * @return \Magento\Framework\Controller\Result\Json
     */
    protected function error(string $message)
    {
        return $this->json(['success' => false, 'message' => $message]);
    }

    /**
     * Shape a certificate for the grid.
     *
     * Detail keys absent from a certificate stay absent here rather than
     * becoming empty strings: v3 carries less than v1 does, and a blank field
     * reads as "the certificate says nothing", which is a different claim from
     * "this transport cannot tell us".
     *
     * @param Certificate $certificate
     * @return array<string, mixed>
     */
    protected function present(Certificate $certificate)
    {
        return [
            'certificateId' => $certificate->getCertificateId(),
            'customerId' => $certificate->getCustomerId(),
            'states' => $certificate->getStates(),
            'disabled' => $certificate->isDisabled(),
            'singlePurchase' => $certificate->isSinglePurchase(),
            'detail' => $certificate->getDetail(),
        ];
    }
}
