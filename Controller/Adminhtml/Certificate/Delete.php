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

/**
 * Delete one of a customer's certificates.
 *
 * The ownership check is not a formality here. TaxCloud will delete any
 * certificate on the account that is named to it, with no regard for whose it
 * is — so without re-resolving first, a mistyped or crafted identifier would
 * destroy another customer's exemption, and certificates cannot be restored.
 */
class Delete extends AbstractCertificateAction implements HttpPostActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->customer();
        $certificateId = (string) $this->getRequest()->getParam('certificate_id');

        if ($customer === null || $certificateId === '') {
            return $this->error(__('Select a customer and a certificate.')->render());
        }

        $storeId = $this->storeId($customer);

        if (!$this->resolver->belongsToCustomer($customer, $certificateId, $storeId)) {
            // Deliberately the same answer whether the certificate belongs to
            // someone else or does not exist: an admin has no business learning
            // which from a failed delete.
            return $this->error(__('That certificate does not belong to this customer.')->render());
        }

        // Refuse to delete the certificate the customer's orders are currently
        // filed against. Deleting it is irreversible at TaxCloud and would leave
        // the attachment pointing at something that no longer exists — the
        // customer silently stops being exempt, with nothing on the screen
        // saying so. Clearing the attachment first makes that consequence an
        // explicit act rather than a side effect.
        if ($this->resolver->attachedCertificateId($customer) === $certificateId) {
            return $this->error(
                __('This certificate is in use for this customer. Choose "Stop using" first, then delete it.')
                    ->render()
            );
        }

        try {
            $this->certificates->delete($certificateId, $this->identity->resolve($customer), $storeId);
        } catch (\Throwable $e) {
            return $this->error(
                __('TaxCloud refused the deletion: %1', $e->getMessage())->render()
            );
        }

        return $this->json(['success' => true]);
    }
}
