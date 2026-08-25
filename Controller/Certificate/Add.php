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
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * A customer creating their own exemption certificate.
 *
 * Narrower than merely seeing the account page: creation requires the store to
 * treat this customer as exempt. Nothing verifies an exemption claim — TaxCloud
 * accepts a certificate carrying an invented tax id — so a merchant putting a
 * customer in an exempt group is the vetting step that stands in for
 * verification, and it is the only one there is.
 *
 * Certificates created here are blanket: they apply to every subsequent order
 * in the states they cover. The v3 API cannot create a single-purchase
 * certificate at all, so a customer who believes they are claiming a one-off
 * exemption would otherwise be made permanently exempt without being told. The
 * form says so; this endpoint cannot.
 */
class Add extends AbstractCustomerAction implements HttpPostActionInterface
{
    /**
     * @var CertificateFormReader
     */
    private $formReader;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param CertificateRepository $certificates
     * @param CertificateResolver $resolver
     * @param TaxCloudCustomerIdentity $identity
     * @param ExemptionPolicy $policy
     * @param StoreManagerInterface $storeManager
     * @param CertificateFormReader $formReader
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        CertificateRepository $certificates,
        CertificateResolver $resolver,
        TaxCloudCustomerIdentity $identity,
        ExemptionPolicy $policy,
        StoreManagerInterface $storeManager,
        CertificateFormReader $formReader
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $certificates,
            $resolver,
            $identity,
            $policy,
            $storeManager
        );
        $this->formReader = $formReader;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->currentCustomer();
        $storeId = $this->currentStoreId();

        if ($customer === null || !$this->policy->mayCreate($customer, $storeId)) {
            return $this->refuse((string) __('You cannot add an exemption certificate on this store.'));
        }

        $submitted = $this->getRequest()->getParam('certificate');
        $form = $this->formReader->read(is_array($submitted) ? $submitted : []);

        $problem = $this->formReader->firstProblem($form);
        if ($problem !== null) {
            // A form problem IS worth reporting precisely — the customer has to
            // be able to fix it. It reveals nothing about the merchant's other
            // certificates.
            return $this->json(['success' => false, 'message' => $problem]);
        }

        try {
            $certificateId = $this->certificates->create(
                // Resolved from the session customer, never submitted.
                $this->identity->resolve($customer),
                $form,
                $storeId
            );
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => (string) __('TaxCloud could not accept that certificate. Please check the details and try again.'),
            ]);
        }

        return $this->json(['success' => true, 'certificateId' => $certificateId]);
    }
}
