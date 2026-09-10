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
 * A customer deleting one of their own certificates.
 *
 * The ownership check is the only thing preventing a shopper deleting somebody
 * else's exemption: TaxCloud deletes whatever identifier it is given, without
 * regard for whose it is, and a deleted certificate cannot be restored.
 *
 * A refusal says nothing about why — whether the certificate belongs to another
 * customer or does not exist at all — so this endpoint cannot be used to
 * discover which identifiers are real.
 */
class Delete extends AbstractCustomerAction implements HttpPostActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->currentCustomer();
        $certificateId = (string) $this->getRequest()->getParam('certificate_id');

        if ($customer === null || $certificateId === '' || !$this->mayUseExemptions($customer)) {
            return $this->refuse();
        }

        $storeId = $this->currentStoreId();

        if (!$this->resolver->belongsToCustomer($customer, $certificateId, $storeId)) {
            return $this->refuse();
        }

        try {
            $this->certificates->delete(
                $certificateId,
                $this->identity->resolve($customer),
                $storeId
            );
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => (string) __('We could not remove that certificate. Please try again.'),
            ]);
        }

        return $this->json(['success' => true]);
    }
}
