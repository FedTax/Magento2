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

namespace Taxcloud\Magento2\Block\Adminhtml\OrderRule\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array
     */
    public function getButtonData()
    {
        if (!$this->getRuleId()) {
            return [];
        }
        $escaper = $this->context->getEscaper();
        $message = $escaper->escapeJs($escaper->escapeHtml(
            __('Delete this rule? Orders it already decided keep their outcome.')
        ));
        return [
            'label' => __('Delete Rule'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {\"data\": {}})",
                $message,
                $this->getUrl('*/*/delete', ['rule_id' => $this->getRuleId()])
            ),
            'sort_order' => 20,
        ];
    }
}
