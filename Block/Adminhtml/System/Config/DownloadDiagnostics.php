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

namespace Taxcloud\Magento2\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Download Diagnostics" button in the TaxCloud settings group.
 *
 * Opens the generation dialog (redaction, log window, live probe) and posts
 * the scope being edited, so a bundle generated at website or store scope
 * describes that scope and one generated at default describes every store.
 * Not rendered at all for admins without the diagnostics ACL grant.
 */
class DownloadDiagnostics extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Taxcloud_Magento2::system/config/download_diagnostics.phtml';

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        if (!$this->_authorization->isAllowed('Taxcloud_Magento2::diagnostics')) {
            return '';
        }
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Configuration for the dialog widget.
     *
     * @return array
     */
    public function getWidgetConfig(): array
    {
        return [
            'url' => $this->getUrl('taxcloud/diagnostics/export'),
            'website' => (string) $this->getRequest()->getParam('website', ''),
            'store' => (string) $this->getRequest()->getParam('store', ''),
            'orderId' => '',
        ];
    }
}
