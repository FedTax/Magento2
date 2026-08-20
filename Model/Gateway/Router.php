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

namespace Taxcloud\Magento2\Model\Gateway;

use Taxcloud\Magento2\Api\GatewayInterface;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\RestGateway;

/**
 * Store-aware transport dispatch for the TaxCloud gateway.
 *
 * di.xml binds every gateway interface here, so call sites stay
 * transport-unaware while the api_type setting — resolved for the store of
 * the entity in hand, never the ambient store — decides which implementation
 * handles each call: SOAP-selected stores dispatch to the v1 SOAP gateway,
 * REST-selected stores to the v3 REST gateway.
 */
class Router implements GatewayInterface
{
    /**
     * @var Api
     */
    private $soap;

    /**
     * @var RestGateway
     */
    private $rest;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param Api $soap
     * @param RestGateway $rest Proxied in di.xml so SOAP-only requests never build the REST stack
     * @param TaxcloudConfig $config
     */
    public function __construct(Api $soap, RestGateway $rest, TaxcloudConfig $config)
    {
        $this->soap = $soap;
        $this->rest = $rest;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function lookupTaxes($itemsByType, $shippingAssignment, $quote)
    {
        return $this->target($quote ? $quote->getStoreId() : null)
            ->lookupTaxes($itemsByType, $shippingAssignment, $quote);
    }

    /**
     * @inheritDoc
     */
    public function listCertificates($customerIdentity, $store = null)
    {
        return $this->target($store)->listCertificates($customerIdentity, $store);
    }

    /**
     * @inheritDoc
     */
    public function createCertificate($customerIdentity, array $data, $store = null)
    {
        return $this->target($store)->createCertificate($customerIdentity, $data, $store);
    }

    /**
     * @inheritDoc
     */
    public function deleteCertificate($certificateId, $customerIdentity, $store = null)
    {
        $this->target($store)->deleteCertificate($certificateId, $customerIdentity, $store);
    }

    /**
     * @inheritDoc
     */
    public function authorizeCapture($order)
    {
        return $this->target($order ? $order->getStoreId() : null)->authorizeCapture($order);
    }

    /**
     * @inheritDoc
     */
    public function returnOrder($creditmemo)
    {
        return $this->target($creditmemo ? $creditmemo->getStoreId() : null)->returnOrder($creditmemo);
    }

    /**
     * @inheritDoc
     */
    public function getOrderDetails($order)
    {
        return $this->target($order ? $order->getStoreId() : null)->getOrderDetails($order);
    }

    /**
     * @inheritDoc
     */
    public function returnOrderCancellation($order)
    {
        return $this->target($order ? $order->getStoreId() : null)->returnOrderCancellation($order);
    }

    /**
     * @inheritDoc
     */
    public function verifyAddress($address, $store = null)
    {
        return $this->target($store)->verifyAddress($address, $store);
    }

    /**
     * Pick the transport for the store's api_type.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return GatewayInterface
     */
    private function target($store): GatewayInterface
    {
        if ($this->config->getApiType($store) === ApiType::REST) {
            return $this->restTarget();
        }
        return $this->soap;
    }

    /**
     * Gateway serving REST-selected stores.
     *
     * @return GatewayInterface
     */
    private function restTarget(): GatewayInterface
    {
        return $this->rest;
    }
}
