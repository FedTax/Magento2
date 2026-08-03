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

/**
 * Store-aware transport dispatch for the TaxCloud gateway.
 *
 * di.xml binds every gateway interface here, so call sites stay
 * transport-unaware while the api_type setting — resolved for the store of
 * the entity in hand, never the ambient store — decides which implementation
 * handles each call.
 *
 * Pending migration: the REST transport implements no tax operations yet, so
 * both API types currently dispatch to the SOAP implementation and selecting
 * "V3 REST" changes no gateway behavior. When REST operations land, only
 * restTarget() and the injected REST gateway change — no call site does.
 */
class Router implements GatewayInterface
{
    /**
     * @var Api
     */
    private $soap;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param Api $soap
     * @param TaxcloudConfig $config
     */
    public function __construct(Api $soap, TaxcloudConfig $config)
    {
        $this->soap = $soap;
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
    public function getValidatedCertificateID($certificateID, $customerID, $destinationState, $store = null)
    {
        return $this->target($store)
            ->getValidatedCertificateID($certificateID, $customerID, $destinationState, $store);
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
        // Pending migration: no REST tax operations exist, so REST-selected
        // stores transact over SOAP. Swap in the REST gateway here once its
        // operations land.
        return $this->soap;
    }
}
