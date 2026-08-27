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
 * Attach a certificate to a customer, or clear the attachment.
 *
 * The write path for something the resolver has always read. Without it an
 * administrator could create a certificate for a customer and have no way to
 * make it apply, short of nominating an exempt customer group in global
 * configuration.
 *
 * The submitted identifier is never trusted: it is re-resolved against the
 * customer's own certificates, exactly as Delete does, and refused with the
 * same answer whether it belongs to someone else or does not exist.
 */
class Attach extends AbstractCertificateAction implements HttpPostActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->customer();
        if ($customer === null) {
            return $this->error(__('Select a customer first.')->render());
        }

        $certificateId = trim((string) $this->getRequest()->getParam('certificate_id'));
        $storeId = $this->storeId($customer);

        // An empty identifier clears the attachment, which needs no ownership
        // check: removing a claim can never grant an exemption.
        if ($certificateId !== '' && !$this->resolver->belongsToCustomer($customer, $certificateId, $storeId)) {
            return $this->error(__('That certificate does not belong to this customer.')->render());
        }

        try {
            $this->attachment->set($customer, $certificateId, $this->administrator(), $storeId);
        } catch (\Throwable $e) {
            return $this->error(
                __('Could not attach the certificate: %1', $e->getMessage())->render()
            );
        }

        return $this->json([
            'success' => true,
            'attached' => $certificateId,
        ]);
    }
}
