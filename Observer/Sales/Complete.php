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

namespace Taxcloud\Magento2\Observer\Sales;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;

class Complete implements ObserverInterface
{

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig = null;

    /**
     * TaxCloud Api Object
     *
     * @var \Taxcloud\Magento2\Model\Api
     */
    protected $_tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Taxcloud\Magento2\Logger\Logger
     */
    protected $_tclogger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Taxcloud\Magento2\Model\Api $tcapi
     * @param \Taxcloud\Magento2\Logger\Logger $tclogger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Taxcloud\Magento2\Model\Api $tcapi,
        \Taxcloud\Magento2\Logger\Logger $tclogger
    )
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_tcapi = $tcapi;

        if($scopeConfig->getValue('tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->_tclogger = $tclogger;
        } else {
            $this->_tclogger = new class {
                public function info() {}
            };
        }
    }

    /**
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    )
    {
        if(!$this->_scopeConfig->getValue('tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            return;
        }

        $this->_tclogger->info('Running Observer sales_order_place_after');

        $order = $observer->getEvent()->getOrder();

        $this->_tcapi->authorizeCapture($order);

    }

}
