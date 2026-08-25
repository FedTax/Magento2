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

namespace Taxcloud\Magento2\Block\Adminhtml\Customer\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard;

/**
 * The exemption-certificate panel on the customer edit page.
 *
 * Renders a shell and lets the browser fetch the certificates, rather than
 * loading them server-side into the page. Deliberate: reading them is a live
 * call to TaxCloud, and a slow or unreachable API would otherwise make the
 * whole customer page slow or unreachable — for an administrator who may only
 * be changing an address.
 *
 * It also lets the panel say WHY it is empty. "No certificates" and "could not
 * ask TaxCloud" look identical in a rendered list and mean opposite things.
 */
class Certificates extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param AuthorizationInterface $authorization
     * @param StoreManagerInterface $storeManager
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        AuthorizationInterface $authorization,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->authorization = $authorization;
        $this->storeManager = $storeManager;
    }

    /**
     * Whether to render at all.
     *
     * The endpoints enforce this too — this only decides what an administrator
     * is shown. A panel of buttons that all fail is worse than no panel.
     *
     * @return bool
     */
    public function canManageCertificates(): bool
    {
        return $this->getCustomerId() > 0
            && $this->authorization->isAllowed(CustomerIdentityGuard::ACL_RESOURCE);
    }

    /**
     * @return int
     */
    public function getCustomerId(): int
    {
        return (int) $this->registry->registry(\Magento\Customer\Controller\RegistryConstants::CURRENT_CUSTOMER_ID);
    }

    /**
     * The store whose TaxCloud account the panel reports on.
     *
     * Named in the UI rather than assumed. Certificates belong to an account,
     * and a multi-store install may point stores at different accounts — so a
     * panel that silently reported one while an administrator was thinking
     * about another would be actively misleading.
     *
     * @return string
     */
    public function getStoreLabel(): string
    {
        try {
            return (string) $this->storeManager->getStore($this->getStoreId())->getName();
        } catch (\Throwable $e) {
            return (string) __('the default store');
        }
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        $customer = $this->registry->registry('current_customer');

        return $customer ? (int) $customer->getStoreId() : (int) $this->_storeManager->getStore()->getId();
    }

    /**
     * @param string $action
     * @return string
     */
    public function getEndpoint(string $action): string
    {
        return $this->getUrl('taxcloud/certificate/' . $action, ['customer_id' => $this->getCustomerId()]);
    }

    /**
     * Options for the creation form, taken from the single list both APIs
     * accept so the form cannot offer a value the request would be rejected for.
     *
     * @return array<string, string[]>
     */
    public function getFormOptions(): array
    {
        return [
            'reasons' => CertificateFormReader::REASONS,
            'businessTypes' => CertificateFormReader::BUSINESS_TYPES,
        ];
    }

    /**
     * @return string
     */
    public function getJsConfig(): string
    {
        return (string) json_encode([
            'endpoints' => [
                'list' => $this->getEndpoint('index'),
                'add' => $this->getEndpoint('add'),
                'delete' => $this->getEndpoint('delete'),
                'refresh' => $this->getEndpoint('refresh'),
            ],
            'options' => $this->getFormOptions(),
            'reasonDescriptionLimit' => CertificateFormReader::REASON_DESCRIPTION_LIMIT,
        ]);
    }
}
