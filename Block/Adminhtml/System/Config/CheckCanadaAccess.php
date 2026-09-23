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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Check Canada Access" button in the TaxCloud settings group.
 *
 * Posts the scope being edited to the taxcloud/canada/check endpoint, which
 * runs a sample Canadian lookup against that scope's saved settings.
 */
class CheckCanadaAccess extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Taxcloud_Magento2::system/config/check_canada_access.phtml';

    /**
     * A button carries no value: strip the scope/inheritance chrome.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
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
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('taxcloud/canada/check');
    }

    /**
     * Website id of the scope being edited, from the config-edit URL.
     *
     * @return string
     */
    public function getWebsiteParam(): string
    {
        return (string) $this->getRequest()->getParam('website', '');
    }

    /**
     * Store id of the scope being edited, from the config-edit URL.
     *
     * @return string
     */
    public function getStoreParam(): string
    {
        return (string) $this->getRequest()->getParam('store', '');
    }
}
