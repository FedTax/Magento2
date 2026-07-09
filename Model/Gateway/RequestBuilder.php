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

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\PostalCodeParser;
use Taxcloud\Magento2\Model\ProductTicService;

/**
 * Constructs the request payloads sent to TaxCloud.
 *
 * Every method here is a pure(ish) assembler: given domain objects (an order,
 * a credit memo's cart items, an address) it returns the array the transport
 * ships. Keeping this out of the orchestrator is what lets a future transport
 * reshape payloads without disturbing the call flow.
 */
class RequestBuilder
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var RegionFactory
     */
    private $regionFactory;

    /**
     * @var ProductTicService
     */
    private $productTicService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param TaxcloudConfig       $config
     * @param ScopeConfigInterface $scopeConfig
     * @param RegionFactory        $regionFactory
     * @param ProductTicService    $productTicService
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        TaxcloudConfig $config,
        ScopeConfigInterface $scopeConfig,
        RegionFactory $regionFactory,
        ProductTicService $productTicService,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->regionFactory = $regionFactory;
        $this->productTicService = $productTicService;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the store's shipping origin address, or null when the origin ZIP
     * is missing/invalid (a lookup cannot proceed without a valid origin).
     *
     * @return array|null
     */
    public function buildOrigin()
    {
        $scope = ScopeInterface::SCOPE_STORE;

        $originPostcode = $this->scopeConfig->getValue('shipping/origin/postcode', $scope);
        $parsedZip = PostalCodeParser::parse($originPostcode);

        // Validate the parsed ZIP code
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->logger->info('Invalid origin ZIP code format: ' . $originPostcode);
            // For origin address, we need a valid ZIP code - return null to indicate invalid origin
            return null;
        }

        return array(
            'Address1' => $this->scopeConfig->getValue('shipping/origin/street_line1', $scope),
            'Address2' => $this->scopeConfig->getValue('shipping/origin/street_line2', $scope),
            'City' => $this->scopeConfig->getValue('shipping/origin/city', $scope),
            'State' => $this->regionFactory->create()->load(
                $this->scopeConfig->getValue('shipping/origin/region_id', $scope)
            )->getCode(),
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        );
    }

    /**
     * Build cart items from an order for full-order return / exempt re-create.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    public function buildCartItemsFromOrder($order)
    {
        $cartItems = array();
        $index = 0;
        $orderItems = $order->getAllVisibleItems();
        if ($orderItems) {
            foreach ($orderItems as $item) {
                $qty = (float) $item->getQtyOrdered();
                if ($qty <= 0) {
                    continue;
                }
                $price = (float) $item->getPrice();
                $discountAmount = (float) $item->getDiscountAmount();
                $discountPerUnit = $qty > 0 ? $discountAmount / $qty : 0;
                $cartItems[] = array(
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'returnOrder'),
                    'Price' => $price - $discountPerUnit,
                    'Qty' => $qty,
                );
                $index++;
            }
        }
        $shippingAmount = (float) $order->getBaseShippingAmount();
        if ($shippingAmount > 0) {
            $cartItems[] = array(
                'ItemID' => 'shipping',
                'Index' => $index,
                'TIC' => $this->productTicService->getShippingTic(),
                'Price' => $shippingAmount,
                'Qty' => 1,
            );
        }
        return $cartItems;
    }

    /**
     * Build the destination array from an order's shipping address for Lookup,
     * or null when the address is missing / non-US / has an invalid ZIP.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array|null
     */
    public function buildDestinationFromOrder($order)
    {
        $address = $order->getShippingAddress();
        if (!$address || !$address->getPostcode() || $address->getCountryId() !== 'US') {
            return null;
        }
        $parsedZip = PostalCodeParser::parse($address->getPostcode());
        if (!PostalCodeParser::isValid($parsedZip)) {
            return null;
        }
        $street = $address->getStreet();
        $street1 = is_array($street) ? ($street[0] ?? '') : (string) $street;
        $street2 = is_array($street) && isset($street[1]) ? $street[1] : '';
        $regionCode = $address->getRegionCode() ?? '';
        return array(
            'Address1' => $street1,
            'Address2' => $street2,
            'City' => $address->getCity() ?? '',
            'State' => $regionCode ?? '',
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        );
    }

    /**
     * Build the OrderDetails request params.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    public function buildOrderDetailsParams($order)
    {
        return array(
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'orderID' => $order->getIncrementId(),
        );
    }

    /**
     * Build the VerifyAddress request params from a destination address array.
     *
     * @param array $address
     * @return array
     */
    public function buildVerifyAddressParams(array $address)
    {
        return array(
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'address1' => $address['Address1'],
            'address2' => $address['Address2'],
            'city' => $address['City'],
            'state' => $address['State'],
            'zip5' => $address['Zip5'],
            'zip4' => $address['Zip4'],
        );
    }

    /**
     * Build the AuthorizedWithCapture request params for an order.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string|null $cartId Override cart ID; defaults to the order's quote ID
     * @return array
     */
    public function buildAuthorizeCaptureParams($order, $cartId = null)
    {
        return array(
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'customerID' => $order->getCustomerId() ?? $this->config->getGuestCustomerId(),
            'cartID' => $cartId ?? $order->getQuoteId(),
            'orderID' => $order->getIncrementId(),
            'dateAuthorized' => date('c'), // date('Y-m-d') . 'T00:00:00'
            'dateCaptured' => date('c'), // date('Y-m-d') . 'T00:00:00'
        );
    }

    /**
     * Build the Returned request params for an order and its cart items.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $cartItems
     * @return array
     */
    public function buildReturnParams($order, array $cartItems)
    {
        return array(
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'orderID' => $order->getIncrementId(),
            'cartItems' => $cartItems,
            'returnedDate' => date('c'), // date('Y-m-d') . 'T00:00:00'
            'returnCoDeliveryFeeWhenNoCartItems' => false
        );
    }

    /**
     * Build the exempt Lookup request params used to re-create an order as
     * exempt (isExempt = true) after a tax-only refund.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $cartItems
     * @param array $destination
     * @param array $origin
     * @return array
     */
    public function buildExemptLookupParams($order, array $cartItems, array $destination, array $origin)
    {
        return array(
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'customerID' => $order->getCustomerId() ?? $this->config->getGuestCustomerId(),
            'cartID' => $order->getIncrementId() . '-exempt',
            'cartItems' => $cartItems,
            'origin' => $origin,
            'destination' => $destination,
            'deliveredBySeller' => false,
            'isExempt' => true,
        );
    }
}
