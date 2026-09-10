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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Backend\Block\Template\Context;

/**
 * Hosts the TIC autocomplete on the Default TIC and Shipping TIC settings.
 *
 * Both fields render through this one block; the field metadata comes from the
 * element being rendered, so nothing here is specific to either.
 *
 * The configuration form is not a UI form and has no data provider, so this
 * bootstraps the standalone adapter (js/config/tic) rather than the form one.
 * The control writes through to the real config input, which is what the
 * configuration form actually posts — the autocomplete decorates the field, it
 * does not replace how the setting is saved.
 */
class TicField extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Taxcloud_Magento2::system/config/tic_field.phtml';

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Context $context
     * @param Json $json
     * @param array $data
     */
    public function __construct(Context $context, Json $json, array $data = [])
    {
        parent::__construct($context, $data);
        $this->json = $json;
    }

    /**
     * @var AbstractElement|null
     */
    private $element;

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->element = $element;

        return $this->_toHtml();
    }

    /**
     * The element being rendered — Default TIC or Shipping TIC.
     *
     * @return AbstractElement|null
     */
    public function getElement()
    {
        return $this->element;
    }

    /**
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('taxcloud/tic/search');
    }

    /**
     * Store view being edited, so lookup resolves against that store's API and
     * credentials rather than the ambient ones. Empty at default and website
     * scope, which resolves at default scope.
     *
     * @return string
     */
    public function getStoreParam(): string
    {
        return (string) $this->getRequest()->getParam('store', '');
    }

    /**
     * What an empty value falls back to, stated per field.
     *
     * Default TIC is the end of the product → category → default chain, and
     * Shipping TIC has no chain at all, so neither can promise an inherited
     * value the way the product and category fields can.
     *
     * @return string
     */
    public function getFallbackHint(): string
    {
        $id = $this->element ? (string) $this->element->getHtmlId() : '';

        if (strpos($id, 'shipping_tic') !== false) {
            return (string) __('Leave empty to use the shipped default, 11010 (Transportation, shipping, postage, and similar charges).');
        }

        return (string) __('Leave empty to use the shipped default, 00000 (Uncategorized tangible personal property).');
    }

    /**
     * x-magento-init payload bootstrapping the standalone adapter.
     *
     * @return string
     */
    public function getComponentJson(): string
    {
        $element = $this->element;
        $uid = $element ? (string) $element->getHtmlId() : 'taxcloud_tic';

        return $this->json->serialize([
            '*' => [
                'Magento_Ui/js/core/app' => [
                    'components' => [
                        $uid => [
                            'component' => 'Taxcloud_Magento2/js/config/tic',
                            'searchUrl' => $this->getAjaxUrl(),
                            'storeId' => $this->getStoreParam() !== '' ? (int) $this->getStoreParam() : null,
                            'fallbackHint' => $this->getFallbackHint(),
                            'inputName' => $element ? (string) $element->getName() : '',
                            'uid' => $uid,
                            'value' => $element ? (string) $element->getValue() : '',
                            'disabled' => $element ? (bool) $element->getDisabled() : false,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
