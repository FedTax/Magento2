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
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

class Address implements ObserverInterface
{

    /**
     * TaxCloud store-scoped configuration reader
     *
     * @var TaxcloudConfig
     */
    protected $config;

    /**
     * TaxCloud address-verification gateway
     *
     * @var \Taxcloud\Magento2\Api\AddressGatewayInterface
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $tclogger;

    /**
     * @param TaxcloudConfig $config
     * @param \Taxcloud\Magento2\Api\AddressGatewayInterface $tcapi
     * @param \Psr\Log\LoggerInterface $tclogger Config-gated proxy, bound in di.xml
     */
    public function __construct(
        TaxcloudConfig $config,
        \Taxcloud\Magento2\Api\AddressGatewayInterface $tcapi,
        \Psr\Log\LoggerInterface $tclogger
    ) {
        $this->config = $config;
        $this->tcapi = $tcapi;

        $this->tclogger = $tclogger;
    }

    /**
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    ) {
        // The taxcloud_lookup_before payload carries the quote (see
        // Model/Api.php lookupTaxes); enabled/verify_address must be read
        // against ITS store — admin order creation runs with the default
        // store view as the ambient store.
        $quote = $observer->getEvent()->getQuote();
        $storeId = $quote ? $quote->getStoreId() : null;

        if ($this->tclogger instanceof GatewayLogger) {
            $this->tclogger->setStore($storeId);
        }

        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        if (!$this->config->isVerifyAddressEnabled($storeId)) {
            return;
        }

        $this->tclogger->info('Running Observer taxcloud_lookup_before');

        $obj = $observer->getEvent()->getObj();
        $params = $obj->getParams();

        try {
            $result = $this->tcapi->verifyAddress($params['destination'], $storeId);
        } catch (\Throwable $e) {
            // Never block checkout on an address-verification failure.
            $this->tclogger->warning('verifyAddress threw exception, leaving address unchanged: ' . $e->getMessage());
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
