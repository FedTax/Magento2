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

use Magento\Framework\Serialize\SerializerInterface;
use Taxcloud\Magento2\Model\PostalCodeParser;

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
    const ITEM_CODE_SHIPPING = 'shipping';
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
     * TaxCloud Logger
     *
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    private $serializer;

    /**
     * Cart Item Response Handler
     *
     * @var \Taxcloud\Magento2\Model\CartItemResponseHandler
     */
    private $cartItemResponseHandler;

    /**
     * Product TIC Service
     *
     * @var \Taxcloud\Magento2\Model\ProductTicService
     */
    private $productTicService;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\CacheInterface $cacheType
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\Webapi\Soap\ClientFactory $soapClientFactory
     * @param \Magento\Framework\DataObjectFactory $objectFactory
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Taxcloud\Magento2\Logger\Logger $tclogger
     * @param SerializerInterface $serializer
     * @param \Taxcloud\Magento2\Model\CartItemResponseHandler $cartItemResponseHandler
     * @param \Taxcloud\Magento2\Model\ProductTicService $productTicService
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
        \Taxcloud\Magento2\Logger\Logger $tclogger,
        SerializerInterface $serializer,
        \Taxcloud\Magento2\Model\CartItemResponseHandler $cartItemResponseHandler,
        \Taxcloud\Magento2\Model\ProductTicService $productTicService
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_cacheType = $cacheType;
        $this->_eventManager = $eventManager;
        $this->_soapClientFactory = $soapClientFactory;
        $this->_objectFactory = $objectFactory;
        $this->_productFactory = $productFactory;
        $this->_regionFactory = $regionFactory;
        $this->serializer = $serializer;
        $this->cartItemResponseHandler = $cartItemResponseHandler;
        $this->productTicService = $productTicService;
        if ($scopeConfig->getValue('tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->_tclogger = $tclogger;
        } else {
            $this->_tclogger = new class {
                public function info()
                {
                }
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
     * Get TaxCloud Cache Lifetime
     * @return string
     */
    protected function _getCacheLifetime()
    {
        return intval($this->_scopeConfig->getValue('tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
    }

    /**
     * Check if fallback to Magento tax rates is enabled
     * @return bool
     */
    private function _isFallbackToMagentoEnabled()
    {
        return (bool) $this->_scopeConfig->getValue('tax/taxcloud_settings/fallback_to_magento', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Set customer address data from quote address
     * @param \Magento\Customer\Api\Data\AddressInterface $customerAddress
     * @param \Magento\Quote\Model\Quote\Address $quoteAddress
     * @return void
     */
    private function setFromAddress($customerAddress, $quoteAddress)
    {
        $customerAddress->setCountryId($quoteAddress->getCountryId());
        $customerAddress->setRegionId($quoteAddress->getRegionId());
        $customerAddress->setPostcode($quoteAddress->getPostcode());
        $customerAddress->setCity($quoteAddress->getCity());
        $customerAddress->setStreet($quoteAddress->getStreet());
    }

    /**
     * Get TaxCloud Shipping Origin
     * @return array
     */
    protected function _getOrigin()
    {
        $scope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

        $originPostcode = $this->_scopeConfig->getValue('shipping/origin/postcode', $scope);
        $parsedZip = PostalCodeParser::parse($originPostcode);
        
        // Validate the parsed ZIP code
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->_tclogger->info('Invalid origin ZIP code format: ' . $originPostcode);
            // For origin address, we need a valid ZIP code - return null to indicate invalid origin
            return null;
        }
        
        return array(
            'Address1' => $this->_scopeConfig->getValue('shipping/origin/street_line1', $scope),
            'Address2' => $this->_scopeConfig->getValue('shipping/origin/street_line2', $scope),
            'City' => $this->_scopeConfig->getValue('shipping/origin/city', $scope),
            'State' => $this->_regionFactory->create()->load($this->_scopeConfig->getValue('shipping/origin/region_id', $scope))->getCode(),
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        );
    }

    /**
     * Get SoapClient
     * @return \SoapClient
     */
    public function getClient()
    {
        if ($this->_client === null) {
            try {
                $wsdl = 'https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl';
                // $this->_client = $this->_soapClientFactory->create($wsdl);
                $this->_client = new \SoapClient($wsdl);
            } catch (Throwable $e) {
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
    public function lookupTaxes($itemsByType, $shippingAssignment, $quote)
    {
        $this->_tclogger->info('Calling lookupTaxes');

        $result = array(self::ITEM_TYPE_PRODUCT => array(), self::ITEM_TYPE_SHIPPING => 0);

        $customer = $quote->getCustomer();

        $address = $shippingAssignment->getShipping()->getAddress();
        if (!$address || !$address->getPostcode()) {
            $this->_tclogger->info('No address, returning 0');
            return $result;
        }
        $destinationPostcode = $address->getPostcode();
        $parsedZip = PostalCodeParser::parse($destinationPostcode);
        
        // Validate the parsed ZIP code
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->_tclogger->info('Invalid ZIP code format: ' . $destinationPostcode);
            return $result;
        }
        
        $destination = array(
            'Address1' => $address->getStreet()[0] ?? '',
            'Address2' => $address->getStreet()[1] ?? '',
            'City' => $address->getCity(),
            'State' => $this->_regionFactory->create()->load($address->getRegionId())->getCode(),
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        );


        if ($address->getCountryId() !== 'US') {
            $this->_tclogger->info('Not US, returning 0');
            return $result;
        }

        if ($address->getRegionId() == 0) {
            $this->_tclogger->info('No region, returning 0');
            return $result;
        }

        if (!$address->getCity()) {
            $this->_tclogger->info('No city, returning 0');
            return $result;
        }

        if (!$address->getPostcode()) {
            $this->_tclogger->info('No postcode, returning 0');
            return $result;
        }

        $keyedAddressItems = [];
        foreach ($shippingAssignment->getItems() as $item) {
            $keyedAddressItems[$item->getTaxCalculationItemId()] = $item;
        }

        $index = 0;
        $indexedItems = array();
        $cartItems = array();

        if (isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
            foreach ($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                $item = $keyedAddressItems[$code];
                if ($item->getProduct() && $item->getProduct()->getTaxClassId() === '0') {
                    // Skip products with tax_class_id of None, store owners should avoid doing this
                    continue;
                }
                $cartItems[] = array(
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'lookupTaxes'),
                    'Price' => $item->getPrice() - $item->getDiscountAmount() / $item->getQty(),
                    'Qty' => $item->getQty(),
                );
                $indexedItems[$index++] = $code;
            }
        }

        if (isset($itemsByType[self::ITEM_TYPE_SHIPPING])) {
            foreach ($itemsByType[self::ITEM_TYPE_SHIPPING] as $code => $itemTaxDetail) {
                // Shipping as a cart item - shipping needs to be taxed
                $cartItems[] = array(
                    'ItemID' => 'shipping',
                    'Index' => $index++,
                    'TIC' => $this->productTicService->getShippingTic(),
                    'Price' => $itemTaxDetail[self::KEY_ITEM]->getRowTotal(),
                    'Qty' => 1,
                );
            }
        }

        if (count($cartItems) === 0) {
            $this->_tclogger->info('No cart items, returning 0');
            return $result;
        }

        $certificateID = null;
        if ($customer) {
            $certificate = $customer->getCustomAttribute('taxcloud_cert');
            if ($certificate) {
                $certificateID = $certificate->getValue();
            }
        }

        $origin = $this->_getOrigin();
        if ($origin === null) {
            $this->_tclogger->info('Invalid origin address configuration - cannot proceed with tax calculation');
            return $result;
        }

        $params = array(
            'apiLoginID' => $this->_getApiId(),
            'apiKey' => $this->_getApiKey(),
            'customerID' => $customer->getId() ?? $this->_getGuestCustomerId(),
            'cartID' => $quote->getId(),
            'cartItems' => $cartItems,
            'origin' => $origin,
            'destination' => $destination,
            'deliveredBySeller' => false,
            'exemptCert' => array(
                'CertificateID' => $certificateID,
            ),
        );

        // hash, check cache
        $cacheKeyApi = 'taxcloud_rates_' . hash('sha256', json_encode($params));
        $cacheResult = null;
        if ($this->_cacheType->load($cacheKeyApi)) {
            $cacheResult = $this->serializer->unserialize($this->_cacheType->load($cacheKeyApi));
        }

        if ($this->_getCacheLifetime() && $cacheResult) {
            $this->_tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient();

        if (!$client) {
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
        } catch (Throwable $e) {
            // Retry
            try {
                $lookupResponse = $client->lookup($params);
            } catch (Throwable $e) {
                $this->_tclogger->info('Error encountered during lookupTaxes: ' . $e->getMessage());
                
                // Check if fallback to Magento is enabled
                if ($this->_isFallbackToMagentoEnabled()) {
                    $this->_tclogger->info('TaxCloud lookup failed, falling back to Magento tax rates');
                    return $this->getMagentoTaxRates($itemsByType, $shippingAssignment, $quote);
                }
                
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

        if ($lookupResult['ResponseType'] == 'OK' || $lookupResult['ResponseType'] == 'Informational') {
            $cartItemResponse = $lookupResult['CartItemsResponse']['CartItemResponse'];
            
            if (empty($cartItemResponse)) {
                $this->_tclogger->info('CartItemResponse is empty, skipping tax calculation');
                return $result;
            }
            $this->cartItemResponseHandler->processAndApplyCartItemResponses($cartItemResponse, $cartItems, $indexedItems, $result);

            $this->_tclogger->info('Caching lookupTaxes result for ' . $this->_getCacheLifetime());
            $this->_cacheType->save($this->serializer->serialize($result), $cacheKeyApi, array('taxcloud_rates'), $this->_getCacheLifetime());

            return $result;
        } else {
            $this->_tclogger->info('Error encountered during lookupTaxes: ');
            $this->_tclogger->info(print_r($lookupResult, true));
            
            // Check if fallback to Magento is enabled
            if ($this->_isFallbackToMagentoEnabled()) {
                $this->_tclogger->info('TaxCloud lookup returned error response, falling back to Magento tax rates');
                return $this->getMagentoTaxRates($itemsByType, $shippingAssignment, $quote);
            }
            
            return $result;
        }
    }

    /**
     * Get Magento's default tax rates for fallback when TaxCloud fails
     * @param $itemsByType
     * @param $shippingAssignment
     * @param $quote
     * @return array
     */
    private function getMagentoTaxRates($itemsByType, $shippingAssignment, $quote)
    {
        $this->_tclogger->info('Falling back to Magento tax rates');
        
        $result = array(self::ITEM_TYPE_PRODUCT => array(), self::ITEM_TYPE_SHIPPING => 0);
        
        $address = $shippingAssignment->getShipping()->getAddress();
        if (!$address) {
            return $result;
        }
        
        // Get the tax calculation service from the Tax model
        $taxCalculationService = $this->_objectFactory->create(\Magento\Tax\Api\TaxCalculationInterface::class);
        
        if (!$taxCalculationService) {
            $this->_tclogger->info('Could not get Magento tax calculation service');
            return $result;
        }
        
        try {
            // Create quote details for tax calculation
            $quoteDetailsFactory = $this->_objectFactory->create(\Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory::class);
            $quoteDetailsItemFactory = $this->_objectFactory->create(\Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory::class);
            $taxClassKeyFactory = $this->_objectFactory->create(\Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory::class);
            $customerAddressFactory = $this->_objectFactory->create(\Magento\Customer\Api\Data\AddressInterfaceFactory::class);
            $customerAddressRegionFactory = $this->_objectFactory->create(\Magento\Customer\Api\Data\RegionInterfaceFactory::class);
            
            if (!$quoteDetailsFactory || !$quoteDetailsItemFactory || !$taxClassKeyFactory || 
                !$customerAddressFactory || !$customerAddressRegionFactory) {
                $this->_tclogger->info('Could not create required factories for Magento tax calculation');
                return $result;
            }
            
            // Build customer address for tax calculation
            $customerAddress = $customerAddressFactory->create();
            $this->setFromAddress($customerAddress, $address);
            
            // Create quote details
            $quoteDetails = $quoteDetailsFactory->create();
            $quoteDetails->setBillingAddress($customerAddress);
            $quoteDetails->setShippingAddress($customerAddress);
            $quoteDetails->setCustomerTaxClassId($quote->getCustomerTaxClassId());
            $quoteDetails->setItems([]);
            
            $keyedAddressItems = [];
            foreach($shippingAssignment->getItems() as $item) {
                $keyedAddressItems[$item->getTaxCalculationItemId()] = $item;
            }
            
            $items = [];
            if(isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
                foreach($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                    $item = $keyedAddressItems[$code];
                    if($item->getProduct()->getTaxClassId() === '0') {
                        continue;
                    }
                    
                    $quoteDetailsItem = $quoteDetailsItemFactory->create();
                    $quoteDetailsItem->setCode($code);
                    $quoteDetailsItem->setType(self::ITEM_TYPE_PRODUCT);
                    $quoteDetailsItem->setTaxClassKey($taxClassKeyFactory->create()->setType(\Magento\Tax\Api\Data\TaxClassKeyInterface::TYPE_ID)->setValue($item->getProduct()->getTaxClassId()));
                    $quoteDetailsItem->setUnitPrice($item->getPrice());
                    $quoteDetailsItem->setQuantity($item->getQty());
                    $quoteDetailsItem->setDiscountAmount($item->getDiscountAmount());
                    $quoteDetailsItem->setTaxIncluded(false);
                    
                    $items[] = $quoteDetailsItem;
                }
            }
            
            if(isset($itemsByType[self::ITEM_TYPE_SHIPPING])) {
                foreach($itemsByType[self::ITEM_TYPE_SHIPPING] as $code => $itemTaxDetail) {
                    $quoteDetailsItem = $quoteDetailsItemFactory->create();
                    $quoteDetailsItem->setCode($code);
                    $quoteDetailsItem->setType(self::ITEM_TYPE_SHIPPING);
                    $quoteDetailsItem->setTaxClassKey($taxClassKeyFactory->create()->setType(\Magento\Tax\Api\Data\TaxClassKeyInterface::TYPE_ID)->setValue(0)); // Default tax class for shipping
                    $quoteDetailsItem->setUnitPrice($itemTaxDetail[self::KEY_ITEM]->getRowTotal());
                    $quoteDetailsItem->setQuantity(1);
                    $quoteDetailsItem->setDiscountAmount(0);
                    $quoteDetailsItem->setTaxIncluded(false);
                    
                    $items[] = $quoteDetailsItem;
                }
            }
            
            $quoteDetails->setItems($items);
            
            // Calculate tax using Magento's service
            $taxDetails = $taxCalculationService->calculateTax($quoteDetails, $quote->getStoreId());
            
            // Process results
            foreach($taxDetails->getItems() as $item) {
                $code = $item->getCode();
                $taxAmount = $item->getRowTax();
                
                if($item->getType() === self::ITEM_TYPE_SHIPPING) {
                    $result[self::ITEM_TYPE_SHIPPING] += $taxAmount;
                } else {
                    $result[self::ITEM_TYPE_PRODUCT][$code] = $taxAmount;
                }
            }
            
            $this->_tclogger->info('Successfully calculated Magento tax rates: ' . json_encode($result));
            return $result;
            
        } catch (\Throwable $e) {
            $this->_tclogger->info('Error calculating Magento tax rates: ' . $e->getMessage());
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

        if (!$client) {
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
        } catch (Throwable $e) {
            // Retry
            try {
                $authorizedResponse = $client->authorizedWithCapture($params);
            } catch (Throwable $e) {
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

        if ($authorizedResult['ResponseType'] != 'OK') {
            $respMsg = $authorizedResult['Messages']['ResponseMessage']['Message'];
            if (trim(substr($respMsg, 0, strlen($dup))) === $dup) {
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

        if (!$client) {
            $this->_tclogger->info('Error encountered during returnOrder: Cannot get SoapClient');
            return false;
        }

        $order = $creditmemo->getOrder();
        $items = $creditmemo->getAllItems();

        $index = 0;
        $cartItems = array();

        if ($items) {
            foreach ($items as $creditItem) {
                $item = $creditItem->getOrderItem();
                $cartItems[] = array(
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'returnOrder'),
                    'Price' => $creditItem->getPrice() - $creditItem->getDiscountAmount() / $creditItem->getQty(),
                    'Qty' => $creditItem->getQty(),
                );
                $index++;
            }
        }

        $shippingAmount = $creditmemo->getShippingAmount();

        if ($shippingAmount > 0) {
            $cartItems[] = array(
                'ItemID' => 'shipping',
                'Index' => $index,
                'TIC' => $this->productTicService->getShippingTic(),
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
            'returnCoDeliveryFeeWhenNoCartItems' => false
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

        // Ensure returnCoDeliveryFeeWhenNoCartItems is always present
        if (!isset($params['returnCoDeliveryFeeWhenNoCartItems'])) {
            $params['returnCoDeliveryFeeWhenNoCartItems'] = false;
        }

        $this->_tclogger->info('returnOrder PARAMS:');
        $this->_tclogger->info(print_r($params, true));

        // Ensure all required parameters are properly set for SOAP call
        $soapParams = array(
            'apiLoginID' => $params['apiLoginID'],
            'apiKey' => $params['apiKey'],
            'orderID' => $params['orderID'],
            'cartItems' => $params['cartItems'],
            'returnedDate' => $params['returnedDate'],
            'returnCoDeliveryFeeWhenNoCartItems' => $params['returnCoDeliveryFeeWhenNoCartItems']
        );

        $this->_tclogger->info('returnOrder SOAP PARAMS:');
        $this->_tclogger->info(print_r($soapParams, true));

        try {
            $returnResponse = $client->Returned($soapParams);
        } catch (Throwable $e) {
            $this->_tclogger->info('First attempt failed: ' . $e->getMessage());
            // Retry with explicit parameter mapping
            try {
                $returnResponse = $client->Returned($soapParams);
            } catch (Throwable $e) {
                $this->_tclogger->info('Error encountered during returnOrder: ' . $e->getMessage());
                $this->_tclogger->info('SOAP parameters that failed: ' . print_r($soapParams, true));
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

        if (!$returnResult || $returnResult['ResponseType'] != 'OK') {
            $errorMessage = 'Unknown error';
            if ($returnResult && isset($returnResult['Messages']['ResponseMessage']['Message'])) {
                $errorMessage = $returnResult['Messages']['ResponseMessage']['Message'];
            }
            $this->_tclogger->info('Error encountered during returnOrder: ' . $errorMessage);
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
        $cacheKeyApi = 'taxcloud_address_' . hash('sha256', json_encode($params));
        $cacheResult = null;
        if ($this->_cacheType->load($cacheKeyApi)) {
            $cacheResult = $this->serializer->unserialize($this->_cacheType->load($cacheKeyApi));
        }

        if ($this->_getCacheLifetime() && $cacheResult) {
            $this->_tclogger->info('Using Cache');
            return $cacheResult;
        }

        $client = $this->getClient();

        if (!$client) {
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
        } catch (Throwable $e) {
            // Retry
            try {
                $verifyResponse = $client->verifyAddress($params);
            } catch (Throwable $e) {
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

        if ($verifyResult['ErrNumber'] == 0) {
            $result = array(
                'Address1' => $verifyResult['Address1'] ?? '',
                'Address2' => $verifyResult['Address2'] ?? '',
                'City' => $verifyResult['City'],
                'State' => $verifyResult['State'],
                'Zip5' => $verifyResult['Zip5'] ?? '',
                'Zip4' => $verifyResult['Zip4'] ?? '',
            );

            $this->_tclogger->info('Caching verifyAddress result for ' . $this->_getCacheLifetime());
            $this->_cacheType->save($this->serializer->serialize($result), $cacheKeyApi, array('taxcloud_address'), $this->_getCacheLifetime());

            return $result;
        } else {
            $this->_tclogger->info('Error encountered during verifyAddress: ' . $verifyResult['ErrDescription']);
            return false;
        }
    }
}
