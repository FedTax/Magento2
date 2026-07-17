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
use Taxcloud\Magento2\Model\RefundDistributor;

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
    public const ITEM_TYPE_SHIPPING = 'shipping';
    public const ITEM_TYPE_PRODUCT = 'product';
    public const KEY_ITEM = 'item';

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
     * @var RefundDistributor
     */
    private $refundDistributor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param TaxcloudConfig       $config
     * @param ScopeConfigInterface $scopeConfig
     * @param RegionFactory        $regionFactory
     * @param ProductTicService    $productTicService
     * @param RefundDistributor    $refundDistributor
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        TaxcloudConfig $config,
        ScopeConfigInterface $scopeConfig,
        RegionFactory $regionFactory,
        ProductTicService $productTicService,
        RefundDistributor $refundDistributor,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->regionFactory = $regionFactory;
        $this->productTicService = $productTicService;
        $this->refundDistributor = $refundDistributor;
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

        return [
            'Address1' => $this->scopeConfig->getValue('shipping/origin/street_line1', $scope),
            'Address2' => $this->scopeConfig->getValue('shipping/origin/street_line2', $scope),
            'City' => $this->scopeConfig->getValue('shipping/origin/city', $scope),
            'State' => $this->regionFactory->create()->load(
                $this->scopeConfig->getValue('shipping/origin/region_id', $scope)
            )->getCode(),
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        ];
    }

    /**
     * Build the destination address for a Lookup from a quote shipping address.
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param array $parsedZip Parsed ZIP (Zip5/Zip4) from PostalCodeParser
     * @return array
     */
    public function buildLookupDestination($address, array $parsedZip)
    {
        return [
            'Address1' => $address->getStreet()[0] ?? '',
            'Address2' => $address->getStreet()[1] ?? '',
            'City' => $address->getCity(),
            'State' => $this->regionFactory->create()->load($address->getRegionId())->getCode(),
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        ];
    }

    /**
     * Build the Lookup cart items from the quote's tax details, returning both
     * the cart items and the index=>code map used to apply the response.
     *
     * @param array $itemsByType
     * @param array $keyedAddressItems Quote items keyed by tax-calculation id
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return array{cartItems: array, indexedItems: array}
     */
    public function buildLookupCartItems($itemsByType, array $keyedAddressItems, $address)
    {
        $index = 0;
        $indexedItems = [];
        $cartItems = [];

        if (isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
            foreach ($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                $item = $keyedAddressItems[$code];
                if ($item->getProduct() && $item->getProduct()->getTaxClassId() === '0') {
                    // Skip products with tax_class_id of None, store owners should avoid doing this
                    continue;
                }
                $cartItems[] = [
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'lookupTaxes'),
                    'Price' => $item->getPrice() - $item->getDiscountAmount() / $item->getQty(),
                    'Qty' => $item->getQty(),
                ];
                $indexedItems[$index++] = $code;
            }
        }

        if (isset($itemsByType[self::ITEM_TYPE_SHIPPING])) {
            $addressShippingAmount = (float) $address->getShippingAmount();
            foreach ($itemsByType[self::ITEM_TYPE_SHIPPING] as $code => $itemTaxDetail) {
                // Shipping as a cart item - shipping needs to be taxed
                $shippingRowTotal = $itemTaxDetail[self::KEY_ITEM]->getRowTotal();
                $cartItems[] = [
                    'ItemID' => 'shipping',
                    'Index' => $index++,
                    'TIC' => $this->productTicService->getShippingTic(),
                    'Price' => ($shippingRowTotal ?: $addressShippingAmount),
                    'Qty' => 1,
                ];
            }
        }

        return ['cartItems' => $cartItems, 'indexedItems' => $indexedItems];
    }

    /**
     * Build the Lookup request params.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param \Magento\Quote\Model\Quote $quote
     * @param array $cartItems
     * @param array $origin
     * @param array $destination
     * @param string|null $certificateID
     * @return array
     */
    public function buildLookupParams(
        $customer,
        $quote,
        array $cartItems,
        array $origin,
        array $destination,
        $certificateID
    ) {
        return [
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'customerID' => $customer->getId() ?? $this->config->getGuestCustomerId(),
            'cartID' => $quote->getId(),
            'cartItems' => $cartItems,
            'origin' => $origin,
            'destination' => $destination,
            'deliveredBySeller' => false,
            'exemptCert' => [
                'CertificateID' => $certificateID,
            ],
        ];
    }

    /**
     * Build the Returned cart items for a credit memo.
     *
     * Handles the three empty-cart cases: a tax-only refund (flagged for exempt
     * re-create), an adjustment-only refund routed through the RefundDistributor
     * (which may skip entirely or replace the cart items), and a normal
     * item/shipping return.
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return array{cartItems: array, wasTaxOnlyRefund: bool, skip: bool}
     */
    public function buildReturnCartItems($creditmemo)
    {
        $order = $creditmemo->getOrder();
        $items = $creditmemo->getAllItems();

        $index = 0;
        $cartItems = [];

        if ($items) {
            foreach ($items as $creditItem) {
                $qty = $creditItem->getQty();
                if ($qty <= 0) {
                    continue;
                }
                $item = $creditItem->getOrderItem();
                $price = $creditItem->getPrice();
                $discountPerUnit = $qty > 0 ? $creditItem->getDiscountAmount() / $qty : 0;
                $cartItems[] = [
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'returnOrder'),
                    'Price' => $price - $discountPerUnit,
                    'Qty' => $qty,
                ];
                $index++;
            }
        }

        $shippingAmount = $creditmemo->getShippingAmount();

        if ($shippingAmount > 0) {
            $cartItems[] = [
                'ItemID' => 'shipping',
                'Index' => $index,
                'TIC' => $this->productTicService->getShippingTic(),
                'Price' => $shippingAmount,
                'Qty' => 1,
            ];
        }

        // Tax-only refund: no product/shipping returned, refund amount equals order tax.
        // Flow: return full order in TaxCloud, then re-create order as exempt.
        $wasTaxOnlyRefund = false;
        if (empty($cartItems)) {
            $orderTax = (float) $order->getBaseTaxAmount();
            $refundTotal = (float) $creditmemo->getBaseGrandTotal();
            $isTaxOnlyRefund = $orderTax > 0
                && abs($refundTotal - $orderTax) < 0.02;

            if ($isTaxOnlyRefund) {
                $this->logger->info('returnOrder: tax-only refund detected; will re-create as exempt after Returned');
                $wasTaxOnlyRefund = true;
            } else {
                // Adjustment-only credit memo (no items, no shipping, not tax-only).
                // Without this guard, an empty cartItems array would tell TaxCloud
                // to return the entire order. Instead, distribute the adjustment
                // proportionally across remaining (unrefunded) items + shipping.
                $distribution = $this->refundDistributor->distribute($creditmemo);
                $this->logger->info(
                    'returnOrder: adjustment-only refund; distributor action=' . $distribution['action']
                    . ' (' . $distribution['reason'] . ')'
                );
                if ($distribution['action'] === RefundDistributor::ACTION_SKIP) {
                    // Nothing meaningful to send to TaxCloud; treat as success.
                    return ['cartItems' => [], 'wasTaxOnlyRefund' => false, 'skip' => true];
                }
                // ACTION_FULL_RETURN leaves cartItems empty (TaxCloud returns the remainder).
                // ACTION_DISTRIBUTE replaces cartItems with the proportional distribution.
                $cartItems = $distribution['cartItems'];
            }
        }

        return ['cartItems' => $cartItems, 'wasTaxOnlyRefund' => $wasTaxOnlyRefund, 'skip' => false];
    }

    /**
     * Build cart items from an order for full-order return / exempt re-create.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    public function buildCartItemsFromOrder($order)
    {
        $cartItems = [];
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
                $cartItems[] = [
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'returnOrder'),
                    'Price' => $price - $discountPerUnit,
                    'Qty' => $qty,
                ];
                $index++;
            }
        }
        $shippingAmount = (float) $order->getBaseShippingAmount();
        if ($shippingAmount > 0) {
            $cartItems[] = [
                'ItemID' => 'shipping',
                'Index' => $index,
                'TIC' => $this->productTicService->getShippingTic(),
                'Price' => $shippingAmount,
                'Qty' => 1,
            ];
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
        return [
            'Address1' => $street1,
            'Address2' => $street2,
            'City' => $address->getCity() ?? '',
            'State' => $regionCode,
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        ];
    }

    /**
     * Build the OrderDetails request params.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    public function buildOrderDetailsParams($order)
    {
        return [
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'orderID' => $order->getIncrementId(),
        ];
    }

    /**
     * Build the VerifyAddress request params from a destination address array.
     *
     * @param array $address
     * @return array
     */
    public function buildVerifyAddressParams(array $address)
    {
        return [
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'address1' => $address['Address1'],
            'address2' => $address['Address2'],
            'city' => $address['City'],
            'state' => $address['State'],
            'zip5' => $address['Zip5'],
            'zip4' => $address['Zip4'],
        ];
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
        return [
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'customerID' => $order->getCustomerId() ?? $this->config->getGuestCustomerId(),
            'cartID' => $cartId ?? $order->getQuoteId(),
            'orderID' => $order->getIncrementId(),
            'dateAuthorized' => date('c'), // date('Y-m-d') . 'T00:00:00'
            'dateCaptured' => date('c'), // date('Y-m-d') . 'T00:00:00'
        ];
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
        return [
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'orderID' => $order->getIncrementId(),
            'cartItems' => $cartItems,
            'returnedDate' => date('c'), // date('Y-m-d') . 'T00:00:00'
            'returnCoDeliveryFeeWhenNoCartItems' => false
        ];
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
        return [
            'apiLoginID' => $this->config->getApiId(),
            'apiKey' => $this->config->getApiKey(),
            'customerID' => $order->getCustomerId() ?? $this->config->getGuestCustomerId(),
            'cartID' => $order->getIncrementId() . '-exempt',
            'cartItems' => $cartItems,
            'origin' => $origin,
            'destination' => $destination,
            'deliveredBySeller' => false,
            'isExempt' => true,
        ];
    }
}
