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

namespace Taxcloud\Magento2\Block\Certificate;

use Magento\Customer\Block\Account\SortLinkInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\App\DefaultPathInterface;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;

/**
 * The "Exemption Certificates" item in the My Account menu.
 *
 * Hidden by exactly the policy that guards the page it points at, so a customer
 * is never shown a menu item that answers with a 404 — and a consumer
 * storefront that restricts exemptions to vetted groups does not advertise the
 * feature to everyone else.
 */
class NavigationLink extends Current implements SortLinkInterface
{
    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var ExemptionPolicy
     */
    private $policy;

    /**
     * @param Context $context
     * @param DefaultPathInterface $defaultPath
     * @param Session $customerSession
     * @param ExemptionPolicy $policy
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        Session $customerSession,
        ExemptionPolicy $policy,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
        $this->customerSession = $customerSession;
        $this->policy = $policy;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder()
    {
        return $this->getData(self::SORT_ORDER);
    }

    /**
     * @inheritDoc
     */
    protected function _toHtml()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }

        try {
            $customer = $this->customerSession->getCustomerData();
            $storeId = (int) $this->_storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return '';
        }

        if (!$this->policy->isVisibleTo($customer, $storeId)) {
            return '';
        }

        return parent::_toHtml();
    }
}
