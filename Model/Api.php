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

namespace Taxcloud\Magento2\Model;

/**
 * Tax Calculation Model
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Api
{

    /**#@+
     * Constants defined for type of items
     */
    const ITEM_TYPE_SHIPPING = 'shipping';
    const ITEM_TYPE_PRODUCT = 'product';
    /**#@+
     * Constants for array keys
     */
    const KEY_ITEM = 'item';
    const KEY_BASE_ITEM = 'base_item';

    /**
     * Magento Config Object
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig = null;

    /**
     * Magento Cache Object
     *
     * @var \Vendor\Cachetype\Model\Cache\Type
     */
    protected $_cacheType;

    /**
     * Magento Event Manager
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager;

    /**
     * Soap client
     *
     * @var \SoapClient
     */
    protected $_client = null;

    /**
     * Soap loader
     *
     * @var \Magento\Framework\Webapi\Soap\ClientFactory
     */
    protected $_soapClientFactory;

    /**
     * Object Factory
     *
     * @var \Magento\Framework\DataObjectFactory
     */
    protected $_objectFactory;

    /**
     * Product Factory
     *
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $_productFactory;

    /**
     * Region Factory
     *
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $_regionFactory;

    /**
     * TaxCloud Logger
     *
     * @var \Taxcloud\Magento2\Logger\Logger
     */
    protected $_tclogger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\CacheInterface $cacheType
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\Webapi\Soap\ClientFactory $soapClientFactory
     * @param \Magento\Framework\DataObjectFactory $objectFactory
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Taxcloud\Magento2\Logger\Logger $tclogger
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\CacheInterface $cacheType,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\Webapi\Soap\ClientFactory $soapClientFactory,
        \Magento\Framework\DataObjectFactory $objectFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Taxcloud\Magento2\Logger\Logger $tclogger
    )
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_cacheType = $cacheType;
        $this->_eventManager = $eventManager;
        $this->_soapClientFactory = $soapClientFactory;
        $this->_objectFactory = $objectFactory;
        $this->_productFactory = $productFactory;
        $this->_regionFactory = $regionFactory;

        if($scopeConfig->getValue('tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->_tclogger = $tclogger;
        } else {
            $this->_tclogger = new class {
                public function info() {}
            };
        }
    }

    /**
     * Get TaxCloud API ID
     * @return string
     */
    protected function _getApiId()
    {
        return $this->_scopeConfig->getValue('tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get TaxCloud API Key
     * @return string
     */
    protected function _getApiKey()
    {
        return $this->_scopeConfig->getValue('tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get TaxCloud Guest Customer Id
     * @return string
     */
    protected function _getGuestCustomerId()
    {
        return $this->_scopeConfig->getValue('tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) ?? '-1';
    }

    /**
     * Get TaxCloud Default Product TIC
     * @return string
     */
    protected function _getDefaultTic()
    {
        return $this->_scopeConfig->getValue('tax/taxcloud_settings/default_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) ?? '00000';
    }

    /**
     * Get TaxCloud Default Shipping TIC
     * @return string
     */
    protected function _getShippingTic()
    {
        return $this->_scopeConfig->getValue('tax/taxcloud_settings/shipping_tic', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) ?? '11010';
    }

    /**
     * Get TaxCloud Cache Lifetime
     * @return string
     */
    protected function _getCacheLifetime()
    {
        return intval($this->_scopeConfig->getValue('tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
    }

    /**
     * Get TaxCloud Shipping Origin
     * @return array
     */
    protected function _getOrigin()
    {
        $scope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

        return array(
            'Address1' => $this->_scopeConfig->getValue('shipping/origin/street_line1', $scope),
            'Address2' => $this->_scopeConfig->getValue('shipping/origin/street_line2', $scope),
            'City' => $this->_scopeConfig->getValue('shipping/origin/city', $scope),
            'State' => $this->_regionFactory->create()->load($this->_scopeConfig->getValue('shipping/origin/region_id', $scope))->getCode(),
            'Zip5' => explode('-', $this->_scopeConfig->getValue('shipping/origin/postcode', $scope) ?? '')[0] ?? null,
            'Zip4' => explode('-', $this->_scopeConfig->getValue('shipping/origin/postcode', $scope) ?? '')[1] ?? null,
        );
    }

    /**
     * Get SoapClient
     * @return \SoapClient
     */
    public function getClient()
    {
        if($this->_client === null) {
            try {
                $wsdl = 'https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl';
                // $this->_client = $this->_soapClientFactory->create($wsdl);
                $this->_client = new \SoapClient($wsdl);
            } catch(Throwable $e) {
                $this->_tclogger->info('Cannot get SoapClient:');
                $this->_tclogger->info($e->getMessage());
            }
        }
        return $this->_client;
    }

    /**
     * Look up tax using TaxCloud web services
     * @param $items
     * @param $shipping
     * @param $quote
     * @return array
     */
    function lookupTaxes($itemsByType, $shippingAssignment, $quote)
    {
        $this->_tclogger->info('Calling lookupTaxes');

        $result = array(self::ITEM_TYPE_PRODUCT => array(), self::ITEM_TYPE_SHIPPING => 0);

        $customer = $quote->getCustomer();

        $address = $shippingAssignment->getShipping()->getAddress();

        $destination = array(
            'Address1' => $address->getStreet()[0] ?? '',
            'Address2' => $address->getStreet()[1] ?? '',
            'City' => $address->getCity(),
            'State' => $this->_regionFactory->create()->load($address->getRegionId())->getCode(),
            'Zip5' => explode('-', $address->getPostcode() ?? '')[0] ?? '',
            'Zip4' => explode('-', $address->getPostcode() ?? '')[1] ?? '',
        );

        if(!$address) {
            $this->_tclogger->info('No address, returning 0');
            return $result;
        }

        if($address->getCountryId() !== 'US') {
            $this->_tclogger->info('Not US, returning 0');
            return $result;
        }

        if($address->getRegionId() == 0) {
            $this->_tclogger->info('No region, returning 0');
            return $result;
        }

        if(!$address->getCity()) {
            $this->_tclogger->info('No city, returning 0');
            return $result;
        }

        if(!$address->getPostcode()) {
            $this->_tclogger->info('No postcode, returning 0');
            return $result;
        }

        $keyedAddressItems = [];
        foreach($shippingAssignment->getItems() as $item) {
            $keyedAddressItems[$item->getTaxCalculationItemId()] = $item;
        }

        $index = 0;
        $indexedItems = array();
        $cartItems = array();

        if(isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
            foreach($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                $item = $keyedAddressItems[$code];
                if($item->getProduct()->getTaxClassId() === '0') {
                    // Skip products with tax_class_id of None, store owners should avoid doing this
                    continue;
                }
                $productModel = $this->_productFactory->create()->load($item->getProduct()->getId());
                $tic = $productModel->getCustomAttribute('taxcloud_tic');
                $cartItems[] = array(
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $tic ? $tic->getValue() : $this->_getDefaultTic(),
                    'Price' => $item->getPrice() - $item->getDiscountAmount() / $item->getQty(),
                    'Qty' => $item->getQty(),
                );
                $indexedItems[$index++] = $code;
            }
        }

        if(isset($itemsByType[self::ITEM_TYPE_SHIPPING])) {
            foreach($itemsByType[self::ITEM_TYPE_SHIPPING] as $code => $itemTaxDetail) {
                // Shipping as a cart item - shipping needs to be taxed
                $cartItems[] = array(
                    'ItemID' => 'shipping',
                    'Index' => $index++,
                    'TIC' => $this->_getShippingTic(),
                    'Price' => $itemTaxDetail[self::KEY_ITEM]->getRowTotal(),
                    'Qty' => 1,
                );
            }
        }

        if(count($cartItems) === 0) {
            $this->_tclogger->info('No cart items, returning 0');
            return $result;
        }

        $certificateID = null;
        if($customer) {
            $certificate = $customer->getCustomAttribute('taxcloud_cert');
            if($certificate) {
                $certificateID = $certificate->getValue();
            }
        }

        $params = array(
            'apiLoginID' => $this->_getApiId(),
            'apiKey' => $this->_getApiKey(),
            'customerID' => $customer->getId() ?? $this->_getGuestCustomerId(),
            'cartID' => $quote->getId(),
            'cartItems' => $cartItems,
            'origin' => $this->_getOrigin(),
            'destination' => $destination,
            'deliveredBySeller' => false,
            'exemptCert' => array(
                'CertificateID' => $certificateID,
            ),
        );

        // hash, check cache
        $cacheKeyApi = 'taxcloud_rates_' . md5(json_encode($params));

        $cacheResult = unserialize($this->_cacheType->load($cacheKeyApi));

        if($this->_getCacheLifetime() && $cacheResult) {
            $this->_tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient();

        if(!$client) {
            $this->_tclogger->info('Error encountered during lookupTaxes: Cannot get SoapClient');
            return $result;
        }

        // Call before event
        $obj = $this->_objectFactory->create();
        $obj->setParams($params);

        $this->_eventManager->dispatch('taxcloud_lookup_before', array(
            'obj' => $obj,
            'customer' => $customer,
            'address' => $address,
            'quote' => $quote,
            'itemsByType' => $itemsByType,
            'shippingAssignment' => $shippingAssignment,
        ));

        $params = $obj->getParams();

        // Call the TaxCloud web service

        $this->_tclogger->info('Calling lookupTaxes LIVE API');
        $this->_tclogger->info('lookupTaxes PARAMS:');
        $this->_tclogger->info(print_r($params, true));

        try {
            $lookupResponse = $client->lookup($params);
        } catch(Throwable $e) {
            // Retry
            try {
                $lookupResponse = $client->lookup($params);
            } catch(Throwable $e) {
                $this->_tclogger->info('Error encountered during lookupTaxes: ' . $e->getMessage());
                return $result;
            }
        }

        // Force into array
        $lookupResponse = json_decode(json_encode($lookupResponse), true);

        $this->_tclogger->info('lookupTaxes RESPONSE:');
        $this->_tclogger->info(print_r($lookupResponse, true));

        $lookupResult = $lookupResponse['LookupResult'];

        // Call after event
        $obj = $this->_objectFactory->create();
        $obj->setResult($lookupResult);

        $this->_eventManager->dispatch('taxcloud_lookup_after', array(
            'obj' => $obj,
            'customer' => $customer,
            'address' => $address,
            'quote' => $quote,
            'itemsByType' => $itemsByType,
            'shippingAssignment' => $shippingAssignment,
        ));

        $lookupResult = $obj->getResult();

        if($lookupResult['ResponseType'] == 'OK' || $lookupResult['ResponseType'] == 'Informational') {
            $cartItemResponse = $lookupResult['CartItemsResponse']['CartItemResponse'];
            $cartItemResponse = is_array($cartItemResponse) ? $cartItemResponse : array($cartItemResponse);

            foreach($cartItemResponse as $c) {
                $index = $c['CartItemIndex'];
                if($cartItems[$index]['ItemID'] === 'shipping') {
                    $result[self::ITEM_TYPE_SHIPPING] += $c['TaxAmount'];
                } else {
                    $code = $indexedItems[$index];
                    $result[self::ITEM_TYPE_PRODUCT][$code] = $c['TaxAmount'];
                }
            }

            $this->_tclogger->info('Caching lookupTaxes result for ' . $this->_getCacheLifetime());
            $this->_cacheType->save(serialize($result), $cacheKeyApi, array('taxcloud_rates'), $this->_getCacheLifetime());

            return $result;

        } else {
            $this->_tclogger->info('Error encountered during lookupTaxes ' . $lookupResult['Messages']['ResponseMessage']['Message']);
            return $result;
        }
    }

    /**
     * Authorized with capture using TaxCloud web services
     * This represents the combination of the Authorized and Captured process in one step. You can
     * also make these calls separately if you use a two stepped commit.
     * @param $order
     * @return bool
     */
    public function authorizeCapture($order)
    {
        $this->_tclogger->info('Calling authorizeCapture');

        $client = $this->getClient();

        if(!$client) {
            $this->_tclogger->info('Error encountered during authorizeCapture: Cannot get SoapClient');
            return false;
        }

        $dup = 'This transaction has already been marked as authorized';

        $params = array(
            'apiLoginID' => $this->_getApiId(),
            'apiKey' => $this->_getApiKey(),
            'customerID' => $order->getCustomerId() ?? $this->_getGuestCustomerId(),
            'cartID' => $order->getQuoteId(),
            'orderID' => $order->getIncrementId(),
            'dateAuthorized' => date('c'), // date('Y-m-d') . 'T00:00:00'
            'dateCaptured' => date('c'), // date('Y-m-d') . 'T00:00:00'
        );

        // Call before event
        $obj = $this->_objectFactory->create();
        $obj->setParams($params);

        $this->_eventManager->dispatch('taxcloud_authorized_with_capture_before', array(
            'obj' => $obj,
            'order' => $order,
        ));

        $params = $obj->getParams();

        $this->_tclogger->info('authorizedWithCapture PARAMS:');
        $this->_tclogger->info(print_r($params, true));

        try {
            $authorizedResponse = $client->authorizedWithCapture($params);
        } catch(Throwable $e) {
            // Retry
            try {
                $authorizedResponse = $client->authorizedWithCapture($params);
            } catch(Throwable $e) {
                $this->_tclogger->info('Error encountered during authorizeCapture: ' . $e->getMessage());
                return false;
            }
        }

        // Force into array
        $authorizedResponse = json_decode(json_encode($authorizedResponse), true);

        $this->_tclogger->info('authorizedWithCapture RESPONSE:');
        $this->_tclogger->info(print_r($authorizedResponse, true));

        $authorizedResult = $authorizedResponse['AuthorizedWithCaptureResult'];

        // Call after event
        $obj = $this->_objectFactory->create();
        $obj->setResult($authorizedResult);

        $this->_eventManager->dispatch('taxcloud_authorized_with_capture_after', array(
            'obj' => $obj,
            'order' => $order,
        ));

        $authorizedResult = $obj->getResult();

        if($authorizedResult['ResponseType'] != 'OK') {
            $respMsg = $authorizedResult['Messages']['ResponseMessage']['Message'];
            if(trim(substr($respMsg, 0, strlen($dup))) === $dup) {
                // Duplicate means the the previous call was good. Therefore, consider this to be good
                $this->_tclogger->info('Warning encountered during authorizeCapture: Duplicate transaction');
                return true;
            } else {
                $this->_tclogger->info('Error encountered during authorizeCapture: ' . $respMsg);
                return false;
            }
        }

        return true;
    }

    /**
     * Return order using TaxCloud web services
     * @param $creditmemo
     * @return bool
     */
    public function returnOrder($creditmemo)
    {
        $this->_tclogger->info('Calling returnOrder');

        $client = $this->getClient();

        if(!$client) {
            $this->_tclogger->info('Error encountered during returnOrder: Cannot get SoapClient');
            return false;
        }

        $order = $creditmemo->getOrder();
        $items = $creditmemo->getAllItems();

        $index = 0;
        $cartItems = array();

        if($items) {
            foreach($items as $creditItem) {
                $item = $creditItem->getOrderItem();
                $productModel = $this->_productFactory->create()->load($item->getProduct()->getId());
                $tic = $productModel->getCustomAttribute('taxcloud_tic');
                $cartItems[] = array(
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $tic ? $tic->getValue() : $this->_getDefaultTic(),
                    'Price' => $creditItem->getPrice() - $creditItem->getDiscountAmount() / $creditItem->getQty(),
                    'Qty' => $creditItem->getQty(),
                );
                $index++;
            }
        }

        $shippingAmount = $creditmemo->getShippingAmount();

        if($shippingAmount > 0) {
            $cartItems[] = array(
                'ItemID' => 'shipping',
                'Index' => $index,
                'TIC' => $this->_getShippingTic(),
                'Price' => $shippingAmount,
                'Qty' => 1,
            );
        }

        $params = array(
            'apiLoginID' => $this->_getApiId(),
            'apiKey' => $this->_getApiKey(),
            'orderID' => $order->getIncrementId(),
            'cartItems' => $cartItems,
            'returnedDate' => date('c'), // date('Y-m-d') . 'T00:00:00';
        );

        // Call before event
        $obj = $this->_objectFactory->create();
        $obj->setParams($params);

        $this->_eventManager->dispatch('taxcloud_returned_before', array(
            'obj' => $obj,
            'order' => $order,
            'items' => $creditmemo->getAllItems(),
            'creditmemo' => $creditmemo,
        ));

        $params = $obj->getParams();

        $this->_tclogger->info('returnOrder PARAMS:');
        $this->_tclogger->info(print_r($params, true));

        try {
            $returnResponse = $client->Returned($params);
        } catch(Throwable $e) {
            // Retry
            try {
                $returnResponse = $client->Returned($params);
            } catch(Throwable $e) {
                $this->_tclogger->info('Error encountered during returnOrder: ' . $e->getMessage());
                return false;
            }
        }

        // Force into array
        $returnResponse = json_decode(json_encode($returnResponse), true);

        $this->_tclogger->info('returnOrder RESPONSE:');
        $this->_tclogger->info(print_r($returnResponse, true));

        $returnResult = $returnResponse['ReturnedResult'];

        // Call after event
        $obj = $this->_objectFactory->create();
        $obj->setResult($returnResult);

        $this->_eventManager->dispatch('taxcloud_returned_after', array(
            'obj' => $obj,
            'order' => $order,
            'items' => $creditmemo->getAllItems(),
            'creditmemo' => $creditmemo,
        ));

        $returnResult = $obj->getResult();

        if($returnResult['ResponseType'] != 'OK') {
            $this->_tclogger->info('Error encountered during returnOrder: ' . $returnResult['Messages']['ResponseMessage']['Message']);
            return false;
        }

        return true;
    }

    /**
     * Verify address using TaxCloud web services
     * @param $creditmemo
     * @return bool|array
     */
    public function verifyAddress($address)
    {
        $this->_tclogger->info('Calling verifyAddress');

        $params = array(
            'apiLoginID' => $this->_getApiId(),
            'apiKey' => $this->_getApiKey(),
            'address1' => $address['Address1'],
            'address2' => $address['Address2'],
            'city' => $address['City'],
            'state' => $address['State'],
            'zip5' => $address['Zip5'],
            'zip4' => $address['Zip4'],
        );

        // hash, check cache
        $cacheKeyApi = 'taxcloud_address_' . md5(json_encode($params));

        $cacheResult = unserialize($this->_cacheType->load($cacheKeyApi));

        if($this->_getCacheLifetime() && $cacheResult) {
            $this->_tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient();

        if(!$client) {
            $this->_tclogger->info('Error encountered during lookupTaxes: Cannot get SoapClient');
            return $result;
        }

        // Call before event

        $obj = $this->_objectFactory->create();
        $obj->setParams($params);

        $this->_eventManager->dispatch('taxcloud_verify_address_before', array(
            'obj' => $obj,
        ));

        $params = $obj->getParams();

        // Call the TaxCloud web service

        $this->_tclogger->info('Calling verifyAddress LIVE API');
        $this->_tclogger->info('verifyAddress PARAMS:');
        $this->_tclogger->info(print_r($params, true));

        try {
            $verifyResponse = $client->verifyAddress($params);
        } catch(Throwable $e) {
            // Retry
            try {
                $verifyResponse = $client->verifyAddress($params);
            } catch(Throwable $e) {
                $this->_tclogger->info('Error encountered during verifyAddress: ' . $e->getMessage());
                return $result;
            }
        }

        // Force into array
        $verifyResponse = json_decode(json_encode($verifyResponse), true);

        $this->_tclogger->info('verifyAddress RESPONSE:');
        $this->_tclogger->info(print_r($verifyResponse, true));

        $verifyResult = $verifyResponse['VerifyAddressResult'];

        // Call after event
        $obj = $this->_objectFactory->create();
        $obj->setResult($verifyResult);

        $this->_eventManager->dispatch('taxcloud_verify_address_after', array(
            'obj' => $obj,
        ));

        $verifyResult = $obj->getResult();

        if($verifyResult['ErrNumber'] == 0) {

            $result = array(
                'Address1' => $verifyResult['Address1'] ?? '',
                'Address2' => $verifyResult['Address2'] ?? '',
                'City' => $verifyResult['City'],
                'State' => $verifyResult['State'],
                'Zip5' => $verifyResult['Zip5'] ?? '',
                'Zip4' => $verifyResult['Zip4'] ?? '',
            );

            $this->_tclogger->info('Caching verifyAddress result for ' . $this->_getCacheLifetime());
            $this->_cacheType->save(serialize($result), $cacheKeyApi, array('taxcloud_address'), $this->_getCacheLifetime());

            return $result;

        } else {
            $this->_tclogger->info('Error encountered during verifyAddress: ' . $verifyResult['ErrDescription']);
            return false;
        }
    }

}
