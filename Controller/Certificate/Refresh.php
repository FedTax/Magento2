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
 * A nominated customer discarding their cached certificates, so the next read
 * reflects a change made outside Magento without waiting for the cache to
 * expire.
 *
 * Confined to the nominated because each refresh costs a live TaxCloud call on
 * the next read; everyone else sees changes when the cache expires.
 */
class Refresh extends AbstractCustomerAction implements HttpPostActionInterface
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

        $this->certificates->invalidate(
            $this->identity->resolve($customer),
            $this->currentStoreId()
        );

        return $this->json(['success' => true]);
    }
}
