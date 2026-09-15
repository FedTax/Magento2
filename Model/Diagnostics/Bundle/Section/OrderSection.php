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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Section;

use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\ProductTicService;

/**
 * order.json: everything about one order that bears on its tax.
 *
 * Most reports are about one order ("tax was wrong on #100000123"). Beyond
 * totals and addresses this records, per line, the TIC together with where it
 * comes from — product attribute, category, or the store default — because TIC
 * resolution is a top source of "wrong tax" and is otherwise invisible.
 *
 * All store-scoped values resolve against the order's store. Runs after the
 * logs section so it can report what the correlated log lines say about where
 * the tax came from.
 */
class OrderSection implements SectionInterface
{
    public const FILE = 'order.json';

    /**
     * @var ProductTicService
     */
    private $ticService;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param ProductTicService $ticService
     * @param TaxcloudConfig    $config
     */
    public function __construct(ProductTicService $ticService, TaxcloudConfig $config)
    {
        $this->ticService = $ticService;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'order';
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(BundleContext $context): bool
    {
        return $context->getOrder() !== null;
    }

    /**
     * @inheritDoc
     */
    public function collect(BundleContext $context, BundleArchive $archive): array
    {
        /** @var Order $order */
        $order = $context->getOrder();
        $storeId = (int) $order->getStoreId();
        $store = $order->getStore();

        $deliveryMethods = $this->config->getCoRdfDeliveryMethods($storeId);
        $shippingMethod = (string) $order->getShippingMethod();
        $rdfAmount = (float) $order->getData('taxcloud_rdf_amount');

        $data = [
            'increment_id' => $order->getIncrementId(),
            'entity_id' => (int) $order->getEntityId(),
            'quote_id' => $order->getQuoteId() !== null ? (int) $order->getQuoteId() : null,
            'store' => [
                'id' => $storeId,
                'code' => $store ? (string) $store->getCode() : null,
            ],
            'created_at' => $order->getCreatedAt(),
            'status' => $order->getStatus(),
            'state' => $order->getState(),
            'customer' => [
                'is_guest' => (bool) $order->getCustomerIsGuest(),
                'customer_id' => $order->getCustomerId() !== null ? (int) $order->getCustomerId() : null,
                'group_id' => $order->getCustomerGroupId() !== null ? (int) $order->getCustomerGroupId() : null,
                'firstname' => $order->getCustomerFirstname(),
                'lastname' => $order->getCustomerLastname(),
                'email' => $order->getCustomerEmail(),
            ],
            'currency' => [
                'order' => $order->getOrderCurrencyCode(),
                'base' => $order->getBaseCurrencyCode(),
            ],
            'totals' => $this->totals($order),
            'items' => $this->items($order, $storeId),
            'billing_address' => $this->address($order->getBillingAddress()),
            'shipping_address' => $this->address($order->getShippingAddress()),
            'shipping_method' => $shippingMethod !== '' ? $shippingMethod : null,
            'shipping_description' => $order->getShippingDescription(),
            'taxcloud' => [
                'taxcloud_captured' => $order->getData('taxcloud_captured'),
                'taxcloud_certificate_id' => $order->getData('taxcloud_certificate_id'),
                'taxcloud_certificate_snapshot' => $this->snapshot($order->getData('taxcloud_certificate_snapshot')),
                'taxcloud_rdf_amount' => $order->getData('taxcloud_rdf_amount'),
                'base_taxcloud_rdf_amount' => $order->getData('base_taxcloud_rdf_amount'),
                'colorado_rdf' => [
                    'applied' => $rdfAmount > 0,
                    'collection_enabled_for_store' => $this->config->isCoRdfEnabled($storeId),
                    'shipping_method_in_motor_vehicle_list' => $shippingMethod !== ''
                        && in_array($shippingMethod, $deliveryMethods, true),
                    'configured_motor_vehicle_methods' => $deliveryMethods,
                ],
                'tax_source' => $this->taxSource($context),
            ],
            'invoices' => $this->documents($order->getInvoiceCollection()),
            'credit_memos' => $this->documents($order->getCreditmemosCollection()),
            'shipments' => $this->shipments($order),
        ];

        if ($context->isRedactingPii()) {
            $data = $context->getPiiRedactor()->maskArray($data);
        }

        $archive->addJson(self::FILE, $data);

        return $data;
    }

    /**
     * @param Order $order
     * @return array
     */
    private function totals(Order $order): array
    {
        return [
            'subtotal' => (float) $order->getSubtotal(),
            'base_subtotal' => (float) $order->getBaseSubtotal(),
            'discount' => (float) $order->getDiscountAmount(),
            'base_discount' => (float) $order->getBaseDiscountAmount(),
            'shipping' => (float) $order->getShippingAmount(),
            'base_shipping' => (float) $order->getBaseShippingAmount(),
            'shipping_tax' => (float) $order->getShippingTaxAmount(),
            'base_shipping_tax' => (float) $order->getBaseShippingTaxAmount(),
            'tax' => (float) $order->getTaxAmount(),
            'base_tax' => (float) $order->getBaseTaxAmount(),
            'grand_total' => (float) $order->getGrandTotal(),
            'base_grand_total' => (float) $order->getBaseGrandTotal(),
            'total_invoiced' => (float) $order->getTotalInvoiced(),
            'total_refunded' => (float) $order->getTotalRefunded(),
        ];
    }

    /**
     * @param Order $order
     * @param int   $storeId
     * @return array
     */
    private function items(Order $order, int $storeId): array
    {
        $items = [];
        foreach ($order->getAllItems() as $item) {
            try {
                $tic = $this->ticService->resolveTic($item, 'diagnostics', $storeId);
            } catch (\Throwable $e) {
                $tic = ['tic' => null, 'source' => 'unresolvable: ' . $e->getMessage()];
            }

            $items[] = [
                'item_id' => (int) $item->getItemId(),
                'parent_item_id' => $item->getParentItemId() !== null ? (int) $item->getParentItemId() : null,
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'product_type' => $item->getProductType(),
                'is_virtual' => (bool) $item->getIsVirtual(),
                'qty_ordered' => (float) $item->getQtyOrdered(),
                'qty_invoiced' => (float) $item->getQtyInvoiced(),
                'qty_refunded' => (float) $item->getQtyRefunded(),
                'price' => (float) $item->getPrice(),
                'base_price' => (float) $item->getBasePrice(),
                'row_total' => (float) $item->getRowTotal(),
                'base_row_total' => (float) $item->getBaseRowTotal(),
                'discount_amount' => (float) $item->getDiscountAmount(),
                'tax_amount' => (float) $item->getTaxAmount(),
                'base_tax_amount' => (float) $item->getBaseTaxAmount(),
                'tax_percent' => (float) $item->getTaxPercent(),
                'tic' => $tic['tic'],
                'tic_source' => $tic['source'],
            ];
        }

        return [
            'note' => 'tic/tic_source are resolved from the catalog as it is now. A TIC changed since the order was '
                . 'placed will differ from the one sent at checkout; Advanced-mode log payloads show the TIC sent.',
            'lines' => $items,
        ];
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderAddressInterface|null $address
     * @return array|null
     */
    private function address($address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'region_code' => $address->getRegionCode(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
            'email' => $address->getEmail(),
        ];
    }

    /**
     * @param mixed $snapshot
     * @return mixed
     */
    private function snapshot($snapshot)
    {
        if (!is_string($snapshot) || $snapshot === '') {
            return $snapshot;
        }
        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? $decoded : $snapshot;
    }

    /**
     * @param iterable $collection
     * @return array
     */
    private function documents($collection): array
    {
        $documents = [];
        foreach ($collection as $document) {
            $documents[] = [
                'increment_id' => $document->getIncrementId(),
                'created_at' => $document->getCreatedAt(),
                'state' => $document->getState() !== null ? (int) $document->getState() : null,
                'subtotal' => (float) $document->getSubtotal(),
                'shipping' => (float) $document->getShippingAmount(),
                'tax' => (float) $document->getTaxAmount(),
                'grand_total' => (float) $document->getGrandTotal(),
                'base_grand_total' => (float) $document->getBaseGrandTotal(),
                'adjustment' => $document->getData('adjustment') !== null
                    ? (float) $document->getData('adjustment')
                    : null,
                'taxcloud_rdf_amount' => $document->getData('taxcloud_rdf_amount'),
                'base_taxcloud_rdf_amount' => $document->getData('base_taxcloud_rdf_amount'),
            ];
        }

        return $documents;
    }

    /**
     * @param Order $order
     * @return array
     */
    private function shipments(Order $order): array
    {
        $shipments = [];
        foreach ($order->getShipmentsCollection() ?: [] as $shipment) {
            $shipments[] = [
                'increment_id' => $shipment->getIncrementId(),
                'created_at' => $shipment->getCreatedAt(),
                'total_qty' => (float) $shipment->getTotalQty(),
            ];
        }

        return $shipments;
    }

    /**
     * Where this order's tax came from, as far as the correlated log shows.
     *
     * @param BundleContext $context
     * @return array
     */
    private function taxSource(BundleContext $context): array
    {
        $correlation = $context->getSectionData('logs')['order_correlation'] ?? null;
        if ($correlation === null) {
            return ['determination' => 'unknown', 'reason' => 'log section unavailable'];
        }

        $evidence = $correlation['tax_source_evidence'] ?? [];
        if (($correlation['correlated_records'] ?? 0) === 0) {
            $determination = 'unknown';
            $reason = 'no log lines correlated with this order';
        } elseif (($evidence['magento_fallback_records'] ?? 0) > 0) {
            $determination = 'magento_fallback';
            $reason = 'the log records a TaxCloud lookup failure followed by the Magento tax-rate fallback';
        } elseif (($evidence['taxcloud_lookup_records'] ?? 0) > 0) {
            $determination = 'taxcloud';
            $reason = 'the log records a successful TaxCloud lookup for this cart';
        } else {
            $determination = 'unknown';
            $reason = 'correlated log lines do not record the lookup outcome';
        }

        return ['determination' => $determination, 'reason' => $reason, 'evidence' => $evidence];
    }
}
