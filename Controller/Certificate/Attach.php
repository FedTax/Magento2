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

use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * A nominated customer choosing which of their certificates is in use, or
 * clearing it with an empty identifier.
 *
 * The same operation as the admin's attach, for customers the store has
 * nominated for self-service. The identifier is re-resolved against the
 * session customer's own certificates: TaxCloud would honour any certificate
 * on the account, so attaching someone else's would exempt this customer on
 * their paperwork.
 */
class Attach extends AbstractCustomerAction implements HttpPostActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->currentCustomer();
        if ($customer === null || !$this->mayManage($customer)) {
            return $this->refuse();
        }

        $certificateId = trim((string) $this->getRequest()->getParam('certificate_id'));
        $storeId = $this->currentStoreId();

        // An empty identifier clears the attachment, which needs no ownership
        // check: removing a claim can never grant an exemption.
        if ($certificateId !== '' && !$this->resolver->belongsToCustomer($customer, $certificateId, $storeId)) {
            return $this->refuse();
        }

        try {
            $this->attachment->set($customer, $certificateId, $this->actor($customer), $storeId);
        } catch (\Throwable $e) {
            return $this->refuse((string) __('We could not update your certificate just now. Please try again.'));
        }

        return $this->json([
            'success' => true,
            'attached' => $certificateId,
        ]);
    }
}
