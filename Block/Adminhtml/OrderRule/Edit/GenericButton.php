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

use Magento\Backend\Block\Widget\Context;

/**
 * Shared bits of the rule form's buttons.
 */
class GenericButton
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * @return int
     */
    protected function getRuleId(): int
    {
        return (int) $this->context->getRequest()->getParam('rule_id');
    }

    /**
     * @param string $route
     * @param array $params
     * @return string
     */
    protected function getUrl(string $route, array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
