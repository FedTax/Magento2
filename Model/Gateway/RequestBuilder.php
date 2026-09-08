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
use Taxcloud\Magento2\Model\Address\TaxAddressResolver;
use Taxcloud\Magento2\Model\CompositeItemResolver;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\PostalCodeParser;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;

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
     * @var FeeService
     */
    private $feeService;

    /**
     * @var TaxAddressResolver
     */
    private $addressResolver;

    /**
     * @param TaxcloudConfig       $config
     * @param ScopeConfigInterface $scopeConfig
     * @param RegionFactory        $regionFactory
     * @param ProductTicService    $productTicService
     * @param RefundDistributor    $refundDistributor
     * @param FeeService           $feeService
     * @param LoggerInterface|null $logger
     * @param TaxAddressResolver|null $addressResolver Defaulted, not optional in
     *        spirit: di.xml binds it, and the default exists only so a store
     *        whose compiled DI is stale after an upgrade still resolves an
     *        address instead of fataling. The class is stateless and takes no
     *        constructor arguments, so the default is the same object DI builds
     *        — a `<preference>` for it would still be honoured through di.xml.
     */
    public function __construct(
        TaxcloudConfig $config,
        ScopeConfigInterface $scopeConfig,
        RegionFactory $regionFactory,
        ProductTicService $productTicService,
        RefundDistributor $refundDistributor,
        FeeService $feeService,
        ?LoggerInterface $logger = null,
        ?TaxAddressResolver $addressResolver = null
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->regionFactory = $regionFactory;
        $this->productTicService = $productTicService;
        $this->refundDistributor = $refundDistributor;
        $this->feeService = $feeService;
        $this->logger = $logger ?? new NullLogger();
        $this->addressResolver = $addressResolver ?? new TaxAddressResolver();
    }

    /**
     * Build the store's shipping origin address, or null when the origin ZIP
     * is missing/invalid (a lookup cannot proceed without a valid origin).
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose origin applies
     * @return array|null
     */
    public function buildOrigin($store = null)
    {
        $scope = ScopeInterface::SCOPE_STORE;

        $originPostcode = $this->scopeConfig->getValue('shipping/origin/postcode', $scope, $store);
        $parsedZip = PostalCodeParser::parse($originPostcode);

        // Validate the parsed ZIP code
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->logger->warning('Invalid origin ZIP code format: ' . $originPostcode);
            // For origin address, we need a valid ZIP code - return null to indicate invalid origin
            return null;
        }

        return [
            'Address1' => $this->scopeConfig->getValue('shipping/origin/street_line1', $scope, $store),
            'Address2' => $this->scopeConfig->getValue('shipping/origin/street_line2', $scope, $store),
            'City' => $this->scopeConfig->getValue('shipping/origin/city', $scope, $store),
            // Origin is configured as a bare region ID, with no address object
            // to read a code from.
            'State' => $this->regionCodeById(
                $this->scopeConfig->getValue('shipping/origin/region_id', $scope, $store)
            ),
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
            'State' => $this->resolveRegionCode($address),
            'Zip5' => $parsedZip['Zip5'],
            'Zip4' => $parsedZip['Zip4'],
        ];
    }

    /**
     * Resolve an address's two-letter state code for a TaxCloud request.
     *
     * An address may carry the code, the region ID, or both: an order loaded
     * from stored quote data can have an ID with no code, while an address built
     * in memory can have a code with no persisted ID. The code wins when present
     * — it is what the address itself claims, and it costs no query — with the
     * directory lookup filling in a missing one.
     *
     * @param \Magento\Framework\DataObject $address Quote or order address
     * @return string Two-letter code, or '' when neither source resolves one
     */
    private function resolveRegionCode($address)
    {
        $code = $address->getRegionCode();
        if (is_string($code) && $code !== '') {
            return $code;
        }

        return $this->regionCodeById($address->getRegionId());
    }

    /**
     * Look up a region's two-letter code by directory region ID.
     *
     * Returns '' rather than null for an unset or unknown ID: 'State' is a
     * string field in the TaxCloud request, and a null there would serialize as
     * an empty element the API rejects less clearly than an empty string.
     *
     * @param mixed $regionId
     * @return string
     */
    private function regionCodeById($regionId)
    {
        if (empty($regionId)) {
            return '';
        }

        $code = $this->regionFactory->create()->load($regionId)->getCode();

        return is_string($code) ? $code : '';
    }

    /**
     * Build the Lookup cart items from the quote's tax details, returning both
     * the cart items and the index=>code map used to apply the response.
     *
     * @param array $itemsByType
     * @param array $keyedAddressItems Quote items keyed by tax-calculation id
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose TIC config applies
     * @return array{cartItems: array, indexedItems: array}
     */
    public function buildLookupCartItems($itemsByType, array $keyedAddressItems, $address, $store = null)
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
                if (CompositeItemResolver::isQuoteParentPricedByChildren($item)) {
                    // Dynamic-price bundle: the children below carry this line's
                    // whole basis and are cart lines of their own. Sending the
                    // parent as well would report that basis to TaxCloud twice.
                    continue;
                }

                // Not getQty(): a bundle child stores its qty per parent, so the
                // effective qty is what the row total was built from.
                $qty = CompositeItemResolver::quoteQty($item);
                $discountPerUnit = $qty > 0 ? $item->getDiscountAmount() / $qty : 0;

                $cartItems[] = [
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'lookupTaxes', $store),
                    'Price' => $item->getPrice() - $discountPerUnit,
                    'Qty' => $qty,
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
                    'TIC' => $this->productTicService->getShippingTic($store),
                    'Price' => ($shippingRowTotal ?: $addressShippingAmount),
                    'Qty' => 1,
                ];
            }
        }

        // The Colorado Retail Delivery Fee, as its own zero-rated line.
        // TaxCloud does not price this fee: it recognizes the TIC, returns
        // the line untaxed, and files the amount from the captured cart — so
        // the price here is the configured fee, always full (never netted
        // against discounts; TaxCloud's own discount exclusion for this TIC
        // cannot see amounts we pre-net into prices). The REST transport
        // inherits this line through its delegation to this builder.
        if ($this->feeService->isEligible($address, $store)) {
            $cartItems[] = [
                'ItemID' => FeeService::ITEM_ID,
                'Index' => $index++,
                'TIC' => $this->feeService->getTic($store),
                'Price' => $this->feeService->getAmount($store),
                'Qty' => 1,
            ];
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
        $store = $quote->getStoreId();

        return [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
            'customerID' => $customer->getId() ?? $this->config->getGuestCustomerId($store),
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
        $store = $order->getStoreId();
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
                // A credit memo lists parents and children alike, so both halves
                // of a composite have to be filtered to leave exactly the lines
                // that carry the basis — the same ones the Lookup sent.
                //
                // Dynamic-price bundle parent: its children are credit-memo items
                // of their own and carry the basis. Unlike the quote side, their
                // qty is already absolute — do not multiply it here.
                if (CompositeItemResolver::isOrderParentPricedByChildren($item)) {
                    continue;
                }
                // Configurable or fixed-bundle child: the parent above carries
                // the price and this line is worth 0.
                if (CompositeItemResolver::isOrderChildWithoutBasis($item)) {
                    continue;
                }
                $price = $creditItem->getPrice();
                $discountPerUnit = $qty > 0 ? $creditItem->getDiscountAmount() / $qty : 0;
                $cartItems[] = [
                    'ItemID' => $item->getSku(),
                    'Index' => $index,
                    'TIC' => $this->productTicService->getProductTic($item, 'returnOrder', $store),
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
                'TIC' => $this->productTicService->getShippingTic($store),
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
        $store = $order->getStoreId();
        $cartItems = [];
        $index = 0;
        $orderItems = $order->getAllVisibleItems();
        if ($orderItems) {
            foreach ($orderItems as $visibleItem) {
                // A dynamic-price bundle is visible as its parent, but the basis
                // lives on its children — and those are the lines the Lookup for
                // this order sent, so a return has to name the same ones.
                foreach (CompositeItemResolver::orderBasisItems($visibleItem) as $item) {
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
                        'TIC' => $this->productTicService->getProductTic($item, 'returnOrder', $store),
                        'Price' => $price - $discountPerUnit,
                        'Qty' => $qty,
                    ];
                    $index++;
                }
            }
        }
        $shippingAmount = (float) $order->getBaseShippingAmount();
        if ($shippingAmount > 0) {
            $cartItems[] = [
                'ItemID' => 'shipping',
                'Index' => $index++,
                'TIC' => $this->productTicService->getShippingTic($store),
                'Price' => $shippingAmount,
                'Qty' => 1,
            ];
        }
        // The Colorado Retail Delivery Fee the order was charged, at the
        // stored (charged) amount — never a fresh config read, which may have
        // moved with Colorado's July 1 rate change. Present here so both
        // consumers of this cart stay faithful to the original sale: an
        // exempt re-create files the fee, a full-cancellation return
        // reverses it.
        $rdfAmount = (float) $order->getBaseTaxcloudRdfAmount();
        if ($rdfAmount > 0) {
            $cartItems[] = [
                'ItemID' => FeeService::ITEM_ID,
                'Index' => $index,
                'TIC' => $this->feeService->getTic($store),
                'Price' => $rdfAmount,
                'Qty' => 1,
            ];
        }
        return $cartItems;
    }

    /**
     * Append the Colorado Retail Delivery Fee line to a Returned cart when
     * the credit memo carries the fee (granted by the creditmemo total
     * collector on full returns only).
     *
     * Only touches a non-empty cart: an empty Returned cart is the "return
     * the remainder" form, where the fee travels via the
     * returnCoDeliveryFeeWhenNoCartItems flag instead of a line.
     *
     * @param array $cartItems v1 Returned cart items
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return array
     */
    public function appendReturnedRdfLine(array $cartItems, $creditmemo)
    {
        $amount = (float) $creditmemo->getBaseTaxcloudRdfAmount();
        if ($amount <= 0 || $cartItems === []) {
            return $cartItems;
        }

        $store = $creditmemo->getOrder()->getStoreId();
        $cartItems[] = [
            'ItemID' => FeeService::ITEM_ID,
            'Index' => count($cartItems),
            'TIC' => $this->feeService->getTic($store),
            'Price' => $amount,
            'Qty' => 1,
        ];

        return $cartItems;
    }

    /**
     * Build the destination array for an order, or null when no address on it
     * yields a usable US destination (missing / non-US / invalid ZIP).
     *
     * The address comes from TaxAddressResolver, so an order that ships nothing
     * — and therefore has no shipping address at all, which is every order
     * placed from a wholly virtual cart — files against its billing address
     * rather than failing to file. Returning null here makes the caller report
     * failure, which is the right outcome only when there is genuinely no
     * address to source to; it used to be the outcome for every digital order.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array|null
     */
    public function buildDestinationFromOrder($order)
    {
        $address = $this->addressResolver->forOrder($order);
        if (!$address || !$address->getPostcode() || $address->getCountryId() !== 'US') {
            $this->logUnresolvedDestination($order);
            return null;
        }
        $parsedZip = PostalCodeParser::parse($address->getPostcode());
        if (!PostalCodeParser::isValid($parsedZip)) {
            $this->logUnresolvedDestination($order);
            return null;
        }
        $this->logResolvedDestination($order);
        $street = $address->getStreet();
        $street1 = is_array($street) ? ($street[0] ?? '') : (string) $street;
        $street2 = is_array($street) && isset($street[1]) ? $street[1] : '';
        $regionCode = $this->resolveRegionCode($address);
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
     * Record which of an order's addresses the destination came from.
     *
     * A digital order correctly sourced to a billing address and a physical
     * order wrongly sourced to one produce the same payload; only this line
     * tells the two apart when someone reads the log afterwards.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function logResolvedDestination($order)
    {
        // The same test the resolver made: it returns the shipping address
        // whenever there is one, so a truthy shipping address IS the source.
        $type = $order->getShippingAddress() ? 'shipping' : 'billing';

        $this->logger->info(
            'Order ' . (string) $order->getIncrementId() . ' destination sourced to its '
            . $type . ' address'
        );
    }

    /**
     * Record that an order has no address that can be sourced to.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function logUnresolvedDestination($order)
    {
        $this->logger->error(
            'Order ' . (string) $order->getIncrementId()
            . ' has no shipping or billing address that yields a valid US destination'
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
        $store = $order->getStoreId();

        return [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
            'orderID' => $order->getIncrementId(),
        ];
    }

    /**
     * Build the VerifyAddress request params from a destination address array.
     *
     * @param array $address
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose credentials apply
     * @return array
     */
    public function buildVerifyAddressParams(array $address, $store = null)
    {
        return [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
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
     * $completedAt is the creation time of the document that triggered the
     * capture (order, invoice or shipment, per the store's capture trigger), as
     * a Magento datetime string in UTC. Filing under it rather than under the
     * call's wall clock keeps a capture retried at a later fulfillment document
     * in the period that document belongs to. Null falls back to now — a real
     * path at order placement, where the order is not yet persisted and has no
     * created_at.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string|null $cartId Override cart ID; defaults to the order's quote ID
     * @param string|null $completedAt Triggering document's created_at (UTC); null means now
     * @return array
     */
    public function buildAuthorizeCaptureParams($order, $cartId = null, $completedAt = null)
    {
        $store = $order->getStoreId();
        $captureDate = $this->toIso8601($completedAt);

        return [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
            'customerID' => $order->getCustomerId() ?? $this->config->getGuestCustomerId($store),
            'cartID' => $cartId ?? $order->getQuoteId(),
            'orderID' => $order->getIncrementId(),
            'dateAuthorized' => $captureDate,
            'dateCaptured' => $captureDate,
        ];
    }

    /**
     * Render a Magento datetime string (stored UTC) in the offset-bearing
     * ISO-8601 form this transport has always sent. Kept in that form rather
     * than normalized to UTC: both render the same instant, and changing the
     * rendering would alter every existing SOAP payload for no gain.
     *
     * @param string|null $datetime
     * @return string
     */
    private function toIso8601($datetime)
    {
        if ($datetime !== null && $datetime !== '') {
            $timestamp = strtotime($datetime . ' UTC');
            if ($timestamp !== false) {
                return date('c', $timestamp);
            }
        }

        return date('c');
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
        $store = $order->getStoreId();

        return [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
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
        $store = $order->getStoreId();

        return [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
            'customerID' => $order->getCustomerId() ?? $this->config->getGuestCustomerId($store),
            'cartID' => $order->getIncrementId() . '-exempt',
            'cartItems' => $cartItems,
            'origin' => $origin,
            'destination' => $destination,
            'deliveredBySeller' => false,
            'isExempt' => true,
        ];
    }
}
