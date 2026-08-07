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

namespace Taxcloud\Magento2\Model\Gateway\Rest;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;

/**
 * Constructs v3 REST payloads (carts, orders, refunds, verify-address).
 *
 * Composes the transport-neutral parts of {@see RequestBuilder} — origin
 * config assembly, region resolution, credit-memo return-item logic including
 * the RefundDistributor — and reshapes their v1-keyed output into v3 JSON
 * shapes. Payloads never carry credentials: v3 authentication lives in
 * transport headers.
 *
 * Amounts are store-currency throughout, matching what the v1 Lookup filed
 * (v1 AuthorizedWithCapture referenced the Lookup's cart, so the amounts
 * TaxCloud recorded were the Lookup's store-currency ones).
 */
class RestRequestBuilder
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RequestBuilder
     */
    private $requestBuilder;

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
     * @param RequestBuilder       $requestBuilder
     * @param ProductTicService    $productTicService
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        TaxcloudConfig $config,
        RequestBuilder $requestBuilder,
        ProductTicService $productTicService,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->requestBuilder = $requestBuilder;
        $this->productTicService = $productTicService;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the lookup line items from the quote's tax details, returning both
     * the v3 lineItems and the index=>code map used to apply the response.
     *
     * @param array $itemsByType
     * @param array $keyedAddressItems Quote items keyed by tax-calculation id
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose TIC config applies
     * @return array{lineItems: array, indexedItems: array}
     */
    public function buildCartLineItems($itemsByType, array $keyedAddressItems, $address, $store = null)
    {
        $built = $this->requestBuilder->buildLookupCartItems($itemsByType, $keyedAddressItems, $address, $store);

        return [
            'lineItems' => array_map([$this, 'toV3LineItem'], $built['cartItems']),
            'indexedItems' => $built['indexedItems'],
        ];
    }

    /**
     * Build the POST /carts payload for a quote lookup.
     *
     * The cart identity is the quote id, so repeated lookups for the same
     * quote update one v3 cart (upsert) instead of accumulating abandoned
     * carts.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param \Magento\Quote\Model\Quote $quote
     * @param array $lineItems v3 line items from {@see buildCartLineItems()}
     * @param array $origin v1-shaped origin from RequestBuilder::buildOrigin()
     * @param array $destination v1-shaped destination
     * @param string|null $certificateID Validated exemption certificate, or null
     * @return array
     */
    public function buildCartPayload(
        $customer,
        $quote,
        array $lineItems,
        array $origin,
        array $destination,
        $certificateID
    ) {
        $store = $quote->getStoreId();

        $cart = [
            'cartId' => (string) $quote->getId(),
            'customerId' => (string) ($customer && $customer->getId() !== null
                ? $customer->getId()
                : $this->config->getGuestCustomerId($store)),
            'currency' => ['currencyCode' => $this->currencyCode($quote->getQuoteCurrencyCode())],
            'origin' => $this->toV3Address($origin),
            'destination' => $this->toV3Address($destination),
            'deliveredBySeller' => false,
            'lineItems' => $lineItems,
        ];

        if ($certificateID !== null && $certificateID !== '') {
            $cart['exemption'] = ['exemptionId' => (string) $certificateID];
        }

        return ['items' => [$cart]];
    }

    /**
     * Build the direct POST /orders payload for capturing a Magento order.
     *
     * Per-line tax is supplied from the order's stored amounts — the amounts
     * the customer was actually charged — rather than recalculated at capture
     * time; transactionDate is the order's placement time and completedDate is
     * now (the capture trigger already decided when this runs).
     *
     * Returns null when no valid origin or destination can be built: a v3
     * order requires both, and filing one with a fabricated address would be
     * worse than failing loudly.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string|null $orderIdOverride Override order id (exempt re-create); defaults to the increment id
     * @param bool $exempt Mark the order fully exempt (tax-only-refund re-create)
     * @return array|null
     */
    public function buildOrderPayload($order, $orderIdOverride = null, $exempt = false)
    {
        $store = $order->getStoreId();

        $origin = $this->requestBuilder->buildOrigin($store);
        if ($origin === null) {
            $this->logger->error('Invalid origin address configuration - cannot build v3 order');
            return null;
        }
        $destination = $this->requestBuilder->buildDestinationFromOrder($order);
        if ($destination === null) {
            $this->logger->error(
                'No valid US shipping address on order ' . $order->getIncrementId() . ' - cannot build v3 order'
            );
            return null;
        }

        $lineItems = [];
        $index = 0;
        foreach ($order->getAllVisibleItems() as $item) {
            // Capture fires on sales_order_place_after, BEFORE the order is
            // saved — at that point composite children have no parent_item_id
            // yet, so getAllVisibleItems() lets them through and a configurable
            // would file its zero-priced child as a duplicate line (observed
            // live 2026-08-07). The in-memory parent_item object IS set
            // pre-save; skip on either signal.
            if ($item->getParentItem() || $item->getParentItemId()) {
                continue;
            }
            $qty = (float) $item->getQtyOrdered();
            if ($qty <= 0) {
                continue;
            }
            $discountPerUnit = $qty > 0 ? ((float) $item->getDiscountAmount()) / $qty : 0.0;
            $lineItems[] = [
                'index' => $index++,
                'itemId' => (string) $item->getSku(),
                'tic' => (int) $this->productTicService->getProductTic($item, 'authorizeCapture', $store),
                'price' => (float) $item->getPrice() - $discountPerUnit,
                'quantity' => $qty,
                'tax' => [
                    'amount' => round($exempt ? 0.0 : (float) $item->getTaxAmount(), 2),
                    'rate' => $exempt ? 0.0 : ((float) $item->getTaxPercent()) / 100,
                ],
            ];
        }

        $shippingAmount = (float) $order->getShippingAmount();
        if ($shippingAmount > 0) {
            $shippingTax = $exempt ? 0.0 : (float) $order->getShippingTaxAmount();
            $lineItems[] = [
                'index' => $index,
                'itemId' => 'shipping',
                'tic' => (int) $this->productTicService->getShippingTic($store),
                'price' => $shippingAmount,
                'quantity' => 1,
                'tax' => [
                    'amount' => round($shippingTax, 2),
                    'rate' => $shippingAmount > 0 ? round($shippingTax / $shippingAmount, 5) : 0.0,
                ],
            ];
        }

        if ($lineItems === []) {
            $this->logger->error(
                'Order ' . $order->getIncrementId() . ' has no billable lines - cannot build v3 order'
            );
            return null;
        }

        $payload = [
            'orderId' => (string) ($orderIdOverride ?? $order->getIncrementId()),
            'customerId' => (string) ($order->getCustomerId() ?? $this->config->getGuestCustomerId($store)),
            'transactionDate' => $this->toRfc3339($order->getCreatedAt()),
            'completedDate' => $this->toRfc3339(null),
            'currency' => ['currencyCode' => $this->currencyCode($order->getOrderCurrencyCode())],
            'origin' => $this->toV3Address($origin),
            'destination' => $this->toV3Address($destination),
            'deliveredBySeller' => false,
            'lineItems' => $lineItems,
        ];

        if ($exempt) {
            $payload['exemption'] = ['isExempt' => true];
        }

        return $payload;
    }

    /**
     * Build the v3 refund items for a credit memo.
     *
     * Reuses the v1 return-cart logic (per-item quantities, tax-only
     * detection, RefundDistributor for adjustment-only memos) and re-expresses
     * it in v3 terms: itemId plus quantity only — v3 derives amounts from the
     * filed order, so a partial dollar amount becomes a fractional quantity of
     * the filed line. Shipping is the one line whose v1 entry carries an
     * amount rather than a quantity, so it converts as
     * refunded/filed-amount.
     *
     * Result flags mirror the v1 helper: skip (nothing meaningful to send —
     * report success without an API call), wasTaxOnlyRefund (full refund then
     * exempt re-create), and fullRefund (send no items: v3 refunds the whole
     * order, which also keeps TaxCloud-owned fee lines like the CO retail
     * delivery fee included).
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @return array{items: array, wasTaxOnlyRefund: bool, skip: bool, fullRefund: bool}
     */
    public function buildRefundItems($creditmemo)
    {
        $built = $this->requestBuilder->buildReturnCartItems($creditmemo);
        if ($built['skip']) {
            return ['items' => [], 'wasTaxOnlyRefund' => false, 'skip' => true, 'fullRefund' => false];
        }

        $order = $creditmemo->getOrder();

        // A full refund is signalled by the v1 builder returning no rows
        // (tax-only refunds, distributor full-return) — decided BEFORE the
        // conversion below, so dropped zero-charge rows can never be mistaken
        // for a refund-everything instruction.
        $fullRefund = $built['cartItems'] === [];

        // v3 refunds are itemId-keyed and reject duplicate references, but
        // composite credit memos carry the same SKU twice (the configurable
        // parent row priced, its child row zero-priced). Group the rows per
        // reference, amount-preservingly: quantity sent = total refunded
        // amount ÷ the filed unit price (the highest row price — the parent's;
        // the order's shipping amount for the shipping line).
        $groups = [];
        foreach ($built['cartItems'] as $cartItem) {
            $itemId = (string) $cartItem['ItemID'];
            if (!isset($groups[$itemId])) {
                $groups[$itemId] = ['amount' => 0.0, 'rowPrice' => 0.0];
            }
            $groups[$itemId]['amount'] += (float) $cartItem['Price'] * (float) $cartItem['Qty'];
            $groups[$itemId]['rowPrice'] = max($groups[$itemId]['rowPrice'], (float) $cartItem['Price']);
        }

        $items = [];
        foreach ($groups as $itemId => $group) {
            $filedUnit = $itemId === 'shipping'
                ? (float) $order->getShippingAmount()
                : $group['rowPrice'];
            if ($filedUnit <= 0) {
                // Nothing was charged for this reference — nothing to refund.
                continue;
            }
            $quantity = round($group['amount'] / $filedUnit, 4);
            if ($itemId === 'shipping') {
                $quantity = min(1.0, $quantity);
            }
            if ($quantity <= 0) {
                continue;
            }
            $items[] = ['itemId' => $itemId, 'quantity' => $quantity];
        }

        if (!$fullRefund && $items === []) {
            // Every row was zero-charge (e.g. a free item refunded alone):
            // nothing meaningful to send — succeed without an API call rather
            // than submitting an empty list v3 would read as a full refund.
            $this->logger->info('buildRefundItems: only zero-charge rows to refund; skipping TaxCloud call');
            return ['items' => [], 'wasTaxOnlyRefund' => false, 'skip' => true, 'fullRefund' => false];
        }

        return [
            'items' => $items,
            'wasTaxOnlyRefund' => $built['wasTaxOnlyRefund'],
            'skip' => false,
            'fullRefund' => $fullRefund,
        ];
    }

    /**
     * Build the POST /tax/verify-address payload from a v1-shaped address
     * array (Address1/Address2/City/State/Zip5/Zip4).
     *
     * @param array $address
     * @return array
     */
    public function buildVerifyAddressPayload(array $address)
    {
        return $this->toV3Address([
            'Address1' => $address['Address1'] ?? '',
            'Address2' => $address['Address2'] ?? '',
            'City' => $address['City'] ?? '',
            'State' => $address['State'] ?? '',
            'Zip5' => $address['Zip5'] ?? '',
            'Zip4' => $address['Zip4'] ?? '',
        ]);
    }

    /**
     * Convert a v1-shaped address array to the v3 shape.
     *
     * line2 is omitted when empty rather than sent as '' — the v3 API treats
     * the field as optional. Zip4 folds into the zip as ZIP+4 when present.
     *
     * @param array $address v1 keys: Address1/Address2/City/State/Zip5/Zip4
     * @return array v3 keys: line1/line2/city/state/zip
     */
    public function toV3Address(array $address)
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

    /**
     * Convert a v1 cart item (ItemID/Index/TIC/Price/Qty) to a v3 line item.
     *
     * @param array $cartItem
     * @return array
     */
    private function toV3LineItem(array $cartItem)
    {
        return [
            'index' => (int) $cartItem['Index'],
            'itemId' => (string) $cartItem['ItemID'],
            'tic' => (int) $cartItem['TIC'],
            'price' => (float) $cartItem['Price'],
            'quantity' => (float) $cartItem['Qty'],
        ];
    }

    /**
     * Normalize a currency code for the v3 currency object (USD/CAD; USD when
     * the entity carries none).
     *
     * @param string|null $code
     * @return string
     */
    private function currencyCode($code)
    {
        $code = strtoupper((string) $code);
        return $code === 'CAD' ? 'CAD' : 'USD';
    }

    /**
     * Format a Magento datetime (UTC 'Y-m-d H:i:s', or null for "now") as the
     * RFC3339 UTC string the v3 API expects.
     *
     * @param string|null $datetime
     * @return string
     */
    private function toRfc3339($datetime)
    {
        if ($datetime !== null && $datetime !== '') {
            $timestamp = strtotime($datetime . ' UTC');
            if ($timestamp !== false) {
                return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
