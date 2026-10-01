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
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateAttachment;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * A nominated customer filing their own exemption certificate.
 *
 * The admin's create, for customers the store has nominated for self-service,
 * with one addition: the customer attests to the claim. An administrator
 * records a certificate someone else signed; a customer here is making the
 * claim themselves, and the log keeps that they said so.
 *
 * The certificate is filed under the customer's TaxCloud identity, resolved on
 * the server. Nothing in the request can choose whose certificate it becomes.
 */
class Add extends AbstractCustomerAction implements HttpPostActionInterface
{
    /**
     * @var CertificateFormReader
     */
    private $formReader;

    /**
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param CertificateRepository $certificates
     * @param CertificateResolver $resolver
     * @param TaxCloudCustomerIdentity $identity
     * @param ExemptionPolicy $policy
     * @param StoreManagerInterface $storeManager
     * @param CertificateAttachment $attachment
     * @param CertificateFormReader $formReader
     * @param GatewayLogger $logger
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        CertificateRepository $certificates,
        CertificateResolver $resolver,
        TaxCloudCustomerIdentity $identity,
        ExemptionPolicy $policy,
        StoreManagerInterface $storeManager,
        CertificateAttachment $attachment,
        CertificateFormReader $formReader,
        GatewayLogger $logger
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $certificates,
            $resolver,
            $identity,
            $policy,
            $storeManager,
            $attachment
        );
        $this->formReader = $formReader;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->currentCustomer();
        if ($customer === null || !$this->mayManage($customer)) {
            return $this->refuse();
        }

        // Checked here, not only in the browser: the attestation is the
        // customer's statement, and a request that skipped the page has not
        // made it.
        if ((string) $this->getRequest()->getParam('attestation') !== '1') {
            return $this->refuse((string) __(
                'Please confirm that the exemption claim is accurate and that you will provide the signed certificate on request.'
            ));
        }

        $submitted = $this->getRequest()->getParam('certificate');
        $form = $this->formReader->read(is_array($submitted) ? $submitted : []);

        $problem = $this->formReader->firstProblem($form);
        if ($problem !== null) {
            return $this->refuse($problem);
        }

        $storeId = $this->currentStoreId();

        try {
            $certificateId = $this->certificates->create(
                $this->identity->resolve($customer),
                $form,
                $storeId
            );
        } catch (\Throwable $e) {
            return $this->refuse((string) __('We could not file your certificate with TaxCloud: %1', $e->getMessage()));
        }

        $this->logger->setStore($storeId);
        $this->logger->info(
            'TaxCloud certificate ' . $certificateId . ' created covering ' . implode(', ', $form['states'])
            . ' by ' . $this->actor($customer) . ', who attested the claim is accurate'
        );

        try {
            $attached = $this->attachment->setIfUnattached(
                $customer,
                $certificateId,
                $this->actor($customer),
                $storeId
            );
        } catch (\Throwable $e) {
            // Filed, but not put in force. Said so rather than reported as a
            // failure: a retry would file a duplicate certificate.
            $attached = false;
        }

        return $this->json([
            'success' => true,
            'certificateId' => $certificateId,
            'attached' => $attached,
        ]);
    }
}
