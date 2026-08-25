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
use Magento\Framework\Controller\ResultFactory;

/**
 * The "Exemption Certificates" page in My Account.
 *
 * Renders a page; the certificates themselves are fetched by
 * {@see Listing}. A signed-out visitor is sent to log in — the page is
 * meaningless without an account, since certificates are held per customer.
 *
 * A store that has not enabled exemptions serves a 404 rather than an empty
 * page. There is nothing here for that customer, and a page explaining that a
 * feature exists but is switched off is worse than the page not existing.
 */
class Index extends AbstractCustomerAction implements HttpGetActionInterface
{
    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $customer = $this->currentCustomer();

        if ($customer === null) {
            /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

            return $redirect->setPath('customer/account/login');
        }

        if (!$this->mayUseExemptions($customer)) {
            return $this->resultFactory->create(ResultFactory::TYPE_FORWARD)
                ->forward('noroute');
        }

        /** @var \Magento\Framework\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Exemption Certificates'));

        return $page;
    }
}
