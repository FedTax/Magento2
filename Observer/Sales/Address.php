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

class Address implements ObserverInterface
{

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig = null;

    /**
     * TaxCloud address-verification gateway
     *
     * @var \Taxcloud\Magento2\Api\AddressGatewayInterface
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Taxcloud\Magento2\Logger\Logger
     */
    protected $tclogger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Taxcloud\Magento2\Api\AddressGatewayInterface $tcapi
     * @param \Taxcloud\Magento2\Logger\Logger $tclogger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Taxcloud\Magento2\Api\AddressGatewayInterface $tcapi,
        \Taxcloud\Magento2\Logger\Logger $tclogger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->tcapi = $tcapi;

        if ($scopeConfig->getValue('tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->tclogger = $tclogger;
        } else {
            $this->tclogger = new class {
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- Null logger: logging is off, discard the message.
                public function info()
                {
                }
            };
        }
    }

    /**
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    ) {
        if (!$this->scopeConfig->getValue(
            'tax/taxcloud_settings/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) {
            return;
        }

        if (!$this->scopeConfig->getValue(
            'tax/taxcloud_settings/verify_address',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) {
            return;
        }

        $this->tclogger->info('Running Observer taxcloud_lookup_before');

        $obj = $observer->getEvent()->getObj();
        $params = $obj->getParams();

        try {
            $result = $this->tcapi->verifyAddress($params['destination']);
        } catch (\Throwable $e) {
            // Never block checkout on an address-verification failure.
            $this->tclogger->info('verifyAddress threw exception, leaving address unchanged: ' . $e->getMessage());
            return;
        }

        if ($result) {
            $result['Address1'] = $result['Address1'] ?: ($params['destination']['Address1'] ?? '');
            $result['Address2'] = $result['Address2'] ?: ($params['destination']['Address2'] ?? '');
            $params['destination'] = $result;
            $obj->setParams($params);
        }
    }
}
