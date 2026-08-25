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

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * A customer's own certificates, for the account area and the checkout
 * selector.
 *
 * Optionally filtered to a destination state: at checkout, offering a
 * certificate that does not cover where the order is going would invite a
 * customer to pick something that cannot work and leave them wondering why they
 * are still being taxed.
 *
 * Reports whether the customer may CREATE certificates as well as list them.
 * The two differ — selecting among certificates a merchant has accepted is not
 * the same act as asserting a new exemption — and the page needs to know which
 * to offer.
 */
class Listing extends AbstractCustomerAction implements HttpGetActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->currentCustomer();
        if ($customer === null || !$this->mayUseExemptions($customer)) {
            return $this->refuse();
        }

        $storeId = $this->currentStoreId();
        $destination = strtoupper(trim((string) $this->getRequest()->getParam('state')));

        try {
            $certificates = $this->certificates->forCustomer(
                $this->identity->resolve($customer),
                $storeId
            );
        } catch (\Throwable $e) {
            // Never an empty list: a shopper told "you have no certificates"
            // would go and create a duplicate of one they already hold.
            return $this->json([
                'success' => false,
                'message' => (string) __('We could not reach TaxCloud to load your certificates. Please try again.'),
            ]);
        }

        $offered = [];
        foreach ($certificates as $certificate) {
            if ($destination !== '' && !$certificate->covers($destination)) {
                continue;
            }
            if ($destination === '' && ($certificate->isDisabled() || $certificate->isSinglePurchase())) {
                // Outside a destination context there is no coverage to test,
                // but a certificate that can never apply is still not worth
                // offering.
                continue;
            }

            $offered[] = [
                'certificateId' => $certificate->getCertificateId(),
                'states' => $certificate->getStates(),
                'purchaserName' => $certificate->getDetailValue('purchaserName'),
                'reason' => $certificate->getDetailValue('reason'),
                'createdDate' => $certificate->getDetailValue('createdDate'),
            ];
        }

        return $this->json([
            'success' => true,
            'mayCreate' => $this->policy->mayCreate($customer, $storeId),
            'certificates' => $offered,
        ]);
    }
}
