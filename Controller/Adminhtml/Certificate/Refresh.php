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
 * Discard a customer's cached certificates.
 *
 * Certificates change outside Magento — in the TaxCloud portal, or through
 * another integration on the same account — and the module caches them for an
 * hour. Without this, an administrator who has just fixed a certificate has no
 * way to see the fix except to wait, which reads as the fix not having worked.
 *
 * Invalidates only this customer's entry: one merchant's correction should not
 * cost every other customer a fresh round trip.
 */
class Refresh extends AbstractCertificateAction implements HttpPostActionInterface
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

        $this->certificates->invalidate(
            $this->identity->resolve($customer),
            $this->storeId($customer)
        );

        return $this->json(['success' => true]);
    }
}
