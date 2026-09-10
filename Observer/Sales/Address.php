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
     * Fires on both lookup before-events — taxcloud_lookup_before (SOAP, v1
     * params) and taxcloud_rest_lookup_before (REST, v3 cart payload) — and
     * replaces the request's destination with the verified address in the
     * payload shape of that transport, so enabling REST does not silently
     * disable in-quote address verification.
     *
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    ) {
        // The lookup before-event payload carries the quote (see
        // Model/Api.php and Model/Gateway/Rest/RestGateway lookupTaxes);
        // enabled/verify_address must be read against ITS store — admin order
        // creation runs with the default store view as the ambient store.
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

        $this->tclogger->info('Running Observer ' . $observer->getEvent()->getName());

        $obj = $observer->getEvent()->getObj();
        $params = $obj->getParams();

        if (isset($params['items']) && is_array($params['items'])) {
            $params = $this->verifyRestCarts($params, $storeId);
            if ($params !== null) {
                $obj->setParams($params);
            }
            return;
        }

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

    /**
     * Verify the destination of each cart in a v3 lookup payload, returning
     * the updated payload or null when nothing changed.
     *
     * The gateway contract speaks the v1 address shape, so each v3
     * destination round-trips v3 → v1 → verify → v3.
     *
     * @param array $params v3 CreateCartsRequest payload
     * @param int|string|null $storeId
     * @return array|null
     */
    private function verifyRestCarts(array $params, $storeId)
    {
        $changed = false;
        foreach ($params['items'] as $i => $cart) {
            $destination = $cart['destination'] ?? null;
            if (!is_array($destination)) {
                continue;
            }

            try {
                $result = $this->tcapi->verifyAddress($this->toV1Address($destination), $storeId);
            } catch (\Throwable $e) {
                // Never block checkout on an address-verification failure.
                $this->tclogger->warning(
                    'verifyAddress threw exception, leaving address unchanged: ' . $e->getMessage()
                );
                return null;
            }

            if ($result) {
                $result['Address1'] = $result['Address1'] ?: ($destination['line1'] ?? '');
                $result['Address2'] = $result['Address2'] ?: ($destination['line2'] ?? '');
                $params['items'][$i]['destination'] = $this->toV3Address($result);
                $changed = true;
            }
        }

        return $changed ? $params : null;
    }

    /**
     * @param array $address v3 shape (line1/line2/city/state/zip)
     * @return array v1 shape (Address1/Address2/City/State/Zip5/Zip4)
     */
    private function toV1Address(array $address)
    {
        $zip5 = (string) ($address['zip'] ?? '');
        $zip4 = '';
        if (strpos($zip5, '-') !== false) {
            [$zip5, $zip4] = explode('-', $zip5, 2);
        }

        return [
            'Address1' => (string) ($address['line1'] ?? ''),
            'Address2' => (string) ($address['line2'] ?? ''),
            'City' => (string) ($address['city'] ?? ''),
            'State' => (string) ($address['state'] ?? ''),
            'Zip5' => $zip5,
            'Zip4' => $zip4,
        ];
    }

    /**
     * @param array $address v1 shape (Address1/Address2/City/State/Zip5/Zip4)
     * @return array v3 shape (line1/line2/city/state/zip)
     */
    private function toV3Address(array $address)
    {
        $zip = (string) ($address['Zip5'] ?? '');
        if (!empty($address['Zip4'])) {
            $zip .= '-' . $address['Zip4'];
        }

        $v3 = [
            'line1' => (string) ($address['Address1'] ?? ''),
            'city' => (string) ($address['City'] ?? ''),
            'state' => (string) ($address['State'] ?? ''),
            'zip' => $zip,
        ];
        if (!empty($address['Address2'])) {
            $v3['line2'] = (string) $address['Address2'];
        }

        return $v3;
    }
}
