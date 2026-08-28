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

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\CertificateAttachment;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Create a certificate for a customer.
 *
 * The customer identity is resolved server-side and never taken from the
 * request. A submitted identity would let an administrator file a certificate
 * under someone else's identifier — which, because identity is what ownership
 * is decided by, is the same as granting that other customer an exemption.
 *
 * What the form supplies is the attestation: who is claiming exemption, on what
 * grounds, in which states. None of it is verified by anyone — TaxCloud accepts
 * an invented tax id without complaint — so this endpoint validates shape, not
 * truth, and the permission guarding it is what actually matters.
 */
class Add extends AbstractCertificateAction implements HttpPostActionInterface
{
    /**
     * @var CertificateFormReader
     */
    private $formReader;

    /**
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param CertificateRepository $certificates
     * @param CertificateResolver $resolver
     * @param TaxCloudCustomerIdentity $identity
     * @param CertificateAttachment $attachment
     * @param CertificateFormReader $formReader
     */
    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        CertificateRepository $certificates,
        CertificateResolver $resolver,
        TaxCloudCustomerIdentity $identity,
        CertificateAttachment $attachment,
        CertificateFormReader $formReader
    ) {
        parent::__construct($context, $customerRepository, $certificates, $resolver, $identity, $attachment);
        $this->formReader = $formReader;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->customer();
        if ($customer === null) {
            return $this->error(__('Select a customer first.')->render());
        }

        $submitted = $this->getRequest()->getParam('certificate');
        $form = $this->formReader->read(is_array($submitted) ? $submitted : []);

        $problem = $this->formReader->firstProblem($form);
        if ($problem !== null) {
            return $this->error($problem);
        }

        try {
            $certificateId = $this->certificates->create(
                // Server-resolved, never submitted: see the class docblock.
                $this->identity->resolve($customer),
                $form,
                $this->storeId($customer)
            );
        } catch (\Throwable $e) {
            return $this->error(
                __('TaxCloud refused the certificate: %1', $e->getMessage())->render()
            );
        }

        // The administrator adding a certificate for this customer has already
        // said what they mean. Requiring a second click on a control they have
        // not yet noticed is exactly the gap this closes — but an existing
        // attachment is never displaced.
        $attached = $this->attachment->setIfUnattached(
            $customer,
            $certificateId,
            $this->administrator(),
            $this->storeId($customer)
        );

        return $this->json([
            'success' => true,
            'certificateId' => $certificateId,
            'attached' => $attached,
        ]);
    }
}
