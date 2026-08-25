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

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;

/**
 * Choose — or decline — an exemption certificate for the cart being checked
 * out.
 *
 * Records the choice on the quote and forces a totals recalculation, so the
 * customer sees the tax change rather than being told it will change later.
 *
 * DECLINING IS RECORDED, not merely absent. For a customer in an exempt group a
 * covering certificate applies on its own, so "no certificate chosen" and "this
 * customer said no" have to be different states — otherwise the next
 * recalculation would helpfully put the exemption straight back and the
 * customer could never turn it off.
 *
 * The submitted identifier is never trusted. It is checked against this
 * customer's own certificates first, because TaxCloud will apply any
 * certificate on the account to any cart that names it.
 */
class Select extends AbstractCustomerAction implements HttpPostActionInterface
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param CertificateRepository $certificates
     * @param CertificateResolver $resolver
     * @param TaxCloudCustomerIdentity $identity
     * @param ExemptionPolicy $policy
     * @param StoreManagerInterface $storeManager
     * @param CheckoutSession $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        CertificateRepository $certificates,
        CertificateResolver $resolver,
        TaxCloudCustomerIdentity $identity,
        ExemptionPolicy $policy,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository
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
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->currentCustomer();
        if ($customer === null || !$this->mayUseExemptions($customer)) {
            return $this->refuse();
        }

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Throwable $e) {
            return $this->refuse((string) __('Your cart could not be loaded.'));
        }

        $certificateId = trim((string) $this->getRequest()->getParam('certificate_id'));
        $storeId = $this->currentStoreId();

        if ($certificateId === '') {
            // An explicit decline, recorded as such.
            $quote->setData('taxcloud_certificate_id', null);
            $quote->setData('taxcloud_certificate_cleared', 1);
        } else {
            if (!$this->resolver->belongsToCustomer($customer, $certificateId, $storeId)) {
                return $this->refuse();
            }

            $quote->setData('taxcloud_certificate_id', $certificateId);
            $quote->setData('taxcloud_certificate_cleared', 0);
        }

        try {
            // Recalculate now: the customer chose in order to see the tax
            // change, and deferring it to the next page load reads as the
            // choice not having worked.
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->quoteRepository->save($quote);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => (string) __('We could not recalculate your total. Please try again.'),
            ]);
        }

        return $this->json([
            'success' => true,
            'certificateId' => $certificateId,
            'taxAmount' => (float) $quote->getShippingAddress()->getTaxAmount(),
            'grandTotal' => (float) $quote->getGrandTotal(),
        ]);
    }
}
