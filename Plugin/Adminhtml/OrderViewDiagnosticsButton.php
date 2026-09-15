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

namespace Taxcloud\Magento2\Plugin\Adminhtml;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Block\Adminhtml\Order\View;

/**
 * Adds "TaxCloud Diagnostics" to the order view button bar.
 *
 * Opens the same generation dialog as the configuration button, bound to this
 * order: the bundle is generated at the order's store scope and narrowed to
 * the order. Hidden from admins without the diagnostics ACL grant.
 */
class OrderViewDiagnosticsButton
{
    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @param AuthorizationInterface $authorization
     * @param UrlInterface           $url Backend URL builder
     */
    public function __construct(AuthorizationInterface $authorization, UrlInterface $url)
    {
        $this->authorization = $authorization;
        $this->url = $url;
    }

    /**
     * @param View $subject
     * @return void
     */
    public function beforeSetLayout(View $subject)
    {
        if (!$this->authorization->isAllowed('Taxcloud_Magento2::diagnostics')) {
            return;
        }

        $order = $subject->getOrder();
        if (!$order || !$order->getId()) {
            return;
        }

        $subject->addButton(
            'taxcloud_diagnostics',
            [
                'label' => __('TaxCloud Diagnostics'),
                'class' => 'taxcloud-diagnostics',
                'data_attribute' => [
                    'mage-init' => [
                        'Taxcloud_Magento2/js/diagnostics/export' => [
                            'url' => $this->url->getUrl('taxcloud/diagnostics/export'),
                            'website' => '',
                            'store' => '',
                            'orderId' => (string) $order->getId(),
                        ],
                    ],
                ],
            ]
        );
    }
}
