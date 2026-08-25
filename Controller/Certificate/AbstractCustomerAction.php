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

namespace Taxcloud\Magento2\Controller\Certificate;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Shared plumbing for the storefront certificate endpoints.
 *
 * The customer is taken from the SESSION and never from the request. That is
 * the whole reason these actions can be short: there is no customer id to
 * validate because none is accepted, so no endpoint here can be pointed at
 * somebody else by changing a parameter.
 *
 * What a request may still carry is a certificate identifier, and that is
 * checked against the session customer's own certificates before anything is
 * done with it — TaxCloud will honour any identifier on the account regardless
 * of whose it is, so this module is the only thing standing in the way.
 */
abstract class AbstractCustomerAction extends Action
{
    /**
     * @var Session
     */
    protected $customerSession;

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
     * @var ExemptionPolicy
     */
    protected $policy;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param CertificateRepository $certificates
     * @param CertificateResolver $resolver
     * @param TaxCloudCustomerIdentity $identity
     * @param ExemptionPolicy $policy
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        CertificateRepository $certificates,
        CertificateResolver $resolver,
        TaxCloudCustomerIdentity $identity,
        ExemptionPolicy $policy,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->customerSession = $customerSession;
        $this->certificates = $certificates;
        $this->resolver = $resolver;
        $this->identity = $identity;
        $this->policy = $policy;
        $this->storeManager = $storeManager;
    }

    /**
     * The signed-in customer, or null.
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface|null
     */
    protected function currentCustomer()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        try {
            return $this->customerSession->getCustomerData();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return int
     */
    protected function currentStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Whether the store offers this customer the exemption interface at all.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @return bool
     */
    protected function mayUseExemptions($customer): bool
    {
        return $this->policy->isVisibleTo($customer, $this->currentStoreId());
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
     * A refusal that says nothing about why.
     *
     * Deliberately uniform: whether the certificate belongs to another customer,
     * does not exist, or the store has exemptions switched off, the answer is
     * the same. A shopper has no business learning which certificates exist on
     * a merchant's TaxCloud account from the shape of an error message.
     *
     * @param string|null $message
     * @return \Magento\Framework\Controller\Result\Json
     */
    protected function refuse(?string $message = null)
    {
        return $this->json([
            'success' => false,
            'message' => $message ?? (string) __('That exemption certificate is not available.'),
        ]);
    }
}
