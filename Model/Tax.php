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
 * Tax totals calculation model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Tax extends \Magento\Tax\Model\Sales\Total\Quote\Tax
{
    /**
     * Quote data key marking that this collector ran for the quote.
     *
     * Read by \Taxcloud\Magento2\Observer\Sales\VerifyTaxCollector at order
     * placement. Deliberately not a db_schema column: it only has to survive
     * from collectTotals() to submit within the same request.
     */
    public const COLLECTED_FLAG = 'taxcloud_tax_collected';

    /**
     * TaxCloud store-scoped configuration reader
     *
     * @var \Taxcloud\Magento2\Model\Config\TaxcloudConfig
     */
    protected $taxcloudConfig;

    /**
     * TaxCloud tax-lookup gateway
     *
     * @var \Taxcloud\Magento2\Api\LookupGatewayInterface
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $tclogger;

    /**
     * Class constructor
     *
     * @param \Magento\Tax\Model\Config $taxConfig
     * @param \Magento\Tax\Api\TaxCalculationInterface $taxCalculationService
     * @param \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory
     * @param \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory
     * @param \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory
     * @param \Magento\Customer\Api\Data\AddressInterfaceFactory $customerAddressFactory
     * @param \Magento\Customer\Api\Data\RegionInterfaceFactory $customerAddressRegionFactory
     * @param \Magento\Tax\Helper\Data $taxData
     * @param \Taxcloud\Magento2\Model\Config\TaxcloudConfig $taxcloudConfig
     * @param \Taxcloud\Magento2\Api\LookupGatewayInterface $tcapi
     * @param \Psr\Log\LoggerInterface $tclogger Config-gated proxy, bound in di.xml
     * @param \Magento\Framework\Serialize\Serializer\Json $serializer
     */
    public function __construct(
        \Magento\Tax\Model\Config $taxConfig,
        \Magento\Tax\Api\TaxCalculationInterface $taxCalculationService,
        \Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory $quoteDetailsDataObjectFactory,
        \Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory $quoteDetailsItemDataObjectFactory,
        \Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory $taxClassKeyDataObjectFactory,
        \Magento\Customer\Api\Data\AddressInterfaceFactory $customerAddressFactory,
        \Magento\Customer\Api\Data\RegionInterfaceFactory $customerAddressRegionFactory,
        \Magento\Tax\Helper\Data $taxData,
        \Taxcloud\Magento2\Model\Config\TaxcloudConfig $taxcloudConfig,
        \Taxcloud\Magento2\Api\LookupGatewayInterface $tcapi,
        \Psr\Log\LoggerInterface $tclogger,
        ?\Magento\Framework\Serialize\Serializer\Json $serializer = null
    ) {
        $this->taxcloudConfig = $taxcloudConfig;
        $this->tcapi = $tcapi;

        $this->tclogger = $tclogger;

        parent::__construct(
            $taxConfig,
            $taxCalculationService,
            $quoteDetailsDataObjectFactory,
            $quoteDetailsItemDataObjectFactory,
            $taxClassKeyDataObjectFactory,
            $customerAddressFactory,
            $customerAddressRegionFactory,
            $taxData,
            $serializer
        );
    }

    /**
     * Collect tax totals for quote address
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment
     * @param \Magento\Quote\Model\Quote\Address\Total $total
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function collect(
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
        \Magento\Quote\Model\Quote\Address\Total $total
    ) {
        // Diagnostics marker, set before the enabled check on purpose: it
        // records that OUR class got the tax collector slot, which is a
        // different question from whether the store has TaxCloud switched on.
        // Its absence at order placement is how
        // \Taxcloud\Magento2\Observer\Sales\VerifyTaxCollector detects that
        // another module displaced us — a silent failure otherwise. Transient
        // quote data with no db_schema column, so it is never persisted.
        $quote->setData(self::COLLECTED_FLAG, true);

        // Resolve against the QUOTE's store, not the ambient request store:
        // in admin/API contexts (admin order creation, webhooks) the ambient
        // store is the default store view, not the store this cart belongs to.
        if (!$this->taxcloudConfig->isEnabled($quote->getStoreId())) {
            return parent::collect($quote, $shippingAssignment, $total);
        }

        $this->clearValues($total);
        if (!$shippingAssignment->getItems()) {
            return $this;
        }

        $baseTaxDetails = $this->getQuoteTaxDetails($shippingAssignment, $total, true);
        $taxDetails = $this->getQuoteTaxDetails($shippingAssignment, $total, false);

        //Populate address and items with tax calculation results
        $itemsByType = $this->organizeItemTaxDetailsByType($taxDetails, $baseTaxDetails);

        // Fetch tax amount from TaxCloud
        $taxAmounts = $this->tcapi->lookupTaxes($itemsByType, $shippingAssignment, $quote);

        // Typed as core types them (CommonTaxCollector::processProductItems):
        // a shipping assignment's items are quote items, and the composite
        // accessors below live on AbstractItem rather than CartItemInterface.
        /** @var \Magento\Quote\Model\Quote\Item\AbstractItem[] $keyedAddressItems */
        $keyedAddressItems = [];
        /** @var \Magento\Quote\Model\Quote\Item\AbstractItem $item */
        foreach ($shippingAssignment->getItems() as $item) {
            // Configurable/composite lines expose a child item with no tax
            // calculation id; using null as an array key is a PHP 8 deprecation
            // (fatal in developer mode). Tax details are keyed by the parent's
            // id, so children are safe to skip here.
            $taxCalculationItemId = $item->getTaxCalculationItemId();
            if ($taxCalculationItemId === null) {
                continue;
            }
            $keyedAddressItems[$taxCalculationItemId] = $item;
        }

        $productTaxTotal = 0.0;

        if (isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
            foreach ($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                $taxDetail = $itemTaxDetail[self::KEY_ITEM];
                $baseTaxDetail = $itemTaxDetail[self::KEY_BASE_ITEM];

                $quoteItem = $keyedAddressItems[$code];

                // A bundle child's qty is stored per parent while its row total
                // is already parent-multiplied; CompositeItemResolver reconciles
                // the two the same way the Lookup request does.
                $effectiveQty = CompositeItemResolver::quoteQty($quoteItem);
                $isPricedByChildren = CompositeItemResolver::isQuoteParentPricedByChildren($quoteItem);

                if ($quoteItem->getProduct()->getTaxClassId() === '0' || $effectiveQty <= 0) {
                    $taxAmount = 0;
                    $taxAmountPer = 0;
                } elseif ($isPricedByChildren) {
                    // Never sent to TaxCloud, so it has no amount of its own: show
                    // the sum of its children, which is the tax this line bears.
                    $taxAmount = $this->sumChildTaxAmounts($quoteItem, $taxAmounts);
                    $taxAmountPer = $taxAmount / $effectiveQty;
                } else {
                    $taxAmount = $taxAmounts[self::ITEM_TYPE_PRODUCT][$code] ?? 0;
                    $taxAmountPer = $taxAmount / $effectiveQty;
                }

                if (!$isPricedByChildren) {
                    // The parent's amount is an echo of its children's, and core
                    // keeps it out of the address total for the same reason.
                    $productTaxTotal += (float) $taxAmount;
                }

                // Snapshot pre-tax values on first collect; reuse on subsequent passes.
                // Without this, any 3rd-party collector that mutates getPrice()/getRowTotal()
                // between collect() invocations causes incl-tax to compound (see order #2000543282).
                // Snapshot invalidates on qty change so genuine cart edits are picked up.
                $currentQty = $effectiveQty;
                if ($quoteItem->getData('taxcloud_pretax_price') === null
                    || (float) $quoteItem->getData('taxcloud_pretax_qty') !== $currentQty
                ) {
                    $quoteItem->setData('taxcloud_pretax_price', (float) $quoteItem->getPrice());
                    $quoteItem->setData('taxcloud_pretax_base_price', (float) $quoteItem->getBasePrice());
                    $quoteItem->setData('taxcloud_pretax_row_total', (float) $quoteItem->getRowTotal());
                    $quoteItem->setData('taxcloud_pretax_base_row_total', (float) $quoteItem->getBaseRowTotal());
                    $quoteItem->setData('taxcloud_pretax_qty', $currentQty);
                }
                $snapPrice        = (float) $quoteItem->getData('taxcloud_pretax_price');
                $snapBasePrice    = (float) $quoteItem->getData('taxcloud_pretax_base_price');
                $snapRowTotal     = (float) $quoteItem->getData('taxcloud_pretax_row_total');
                $snapBaseRowTotal = (float) $quoteItem->getData('taxcloud_pretax_base_row_total');

                // Persist tax onto quote item so tax does not get lost downstream
                // This ensures tax is available when quote is converted to order
                $quoteItem->setTaxAmount($taxAmount);
                $quoteItem->setBaseTaxAmount($taxAmount);
                $detailRowTotal = $taxDetail->getRowTotal();
                $quoteItem->setTaxPercent($detailRowTotal > 0 ? round(100 * $taxAmount / $detailRowTotal, 3) : 0);
                $quoteItem->setPriceInclTax($snapPrice + $taxAmountPer);
                $quoteItem->setBasePriceInclTax($snapBasePrice + $taxAmountPer);
                $quoteItem->setRowTotalInclTax($snapRowTotal + $taxAmount);
                $quoteItem->setBaseRowTotalInclTax($snapBaseRowTotal + $taxAmount);

                $taxDetail->setRowTax($taxAmount);
                $taxDetail->setPriceInclTax($taxDetail->getPrice() + $taxAmountPer);
                $taxDetail->setRowTotalInclTax($taxDetail->getRowTotal() + $taxAmount);
                $taxDetail->setAppliedTaxes([]);
                if ($taxDetail->getRowTotal() > 0) {
                    $taxDetail->setTaxPercent(round(100 * $taxDetail->getRowTax() / $taxDetail->getRowTotal(), 3));
                } else {
                    $taxDetail->setTaxPercent(0);
                }

                $baseTaxDetail->setRowTax($taxAmount);
                $baseTaxDetail->setPriceInclTax($baseTaxDetail->getPrice() + $taxAmountPer);
                $baseTaxDetail->setRowTotalInclTax($baseTaxDetail->getRowTotal() + $taxAmount);
                $baseTaxDetail->setAppliedTaxes([]);
                if ($baseTaxDetail->getRowTotal() > 0) {
                    $baseTaxDetail->setTaxPercent(
                        round(100 * $baseTaxDetail->getRowTax() / $baseTaxDetail->getRowTotal(), 3)
                    );
                } else {
                    $baseTaxDetail->setTaxPercent(0);
                }
            }

            $this->processProductItems($shippingAssignment, $itemsByType[self::ITEM_TYPE_PRODUCT], $total);
        }

        if (isset($itemsByType[self::ITEM_TYPE_SHIPPING])) {
            $shippingTaxDetails = $itemsByType[self::ITEM_TYPE_SHIPPING]
                [self::ITEM_CODE_SHIPPING][self::KEY_ITEM];
            $baseShippingTaxDetails = $itemsByType[self::ITEM_TYPE_SHIPPING]
                [self::ITEM_CODE_SHIPPING][self::KEY_BASE_ITEM];

            $taxAmount = $taxAmounts[self::ITEM_TYPE_SHIPPING];
            $taxAmountPer = $taxAmount / 1;

            $shippingTaxDetails->setRowTax($taxAmount);
            $shippingTaxDetails->setPriceInclTax($shippingTaxDetails->getPrice() + $taxAmountPer);
            $shippingTaxDetails->setRowTotalInclTax($shippingTaxDetails->getRowTotal() + $taxAmount);
            $shippingTaxDetails->setAppliedTaxes([]);
            if ($shippingTaxDetails->getRowTotal() > 0) {
                $shippingTaxDetails->setTaxPercent(
                    round(100 * $shippingTaxDetails->getRowTax() / $shippingTaxDetails->getRowTotal(), 3)
                );
            } else {
                $shippingTaxDetails->setTaxPercent(0);
            }

            $baseShippingTaxDetails->setRowTax($taxAmount);
            $baseShippingTaxDetails->setPriceInclTax($baseShippingTaxDetails->getPrice() + $taxAmountPer);
            $baseShippingTaxDetails->setRowTotalInclTax($baseShippingTaxDetails->getRowTotal() + $taxAmount);
            $baseShippingTaxDetails->setAppliedTaxes([]);
            if ($baseShippingTaxDetails->getRowTotal() > 0) {
                $baseShippingTaxDetails->setTaxPercent(
                    round(100 * $baseShippingTaxDetails->getRowTax() / $baseShippingTaxDetails->getRowTotal(), 3)
                );
            } else {
                $baseShippingTaxDetails->setTaxPercent(0);
            }

            $this->processShippingTaxInfo($shippingAssignment, $total, $shippingTaxDetails, $baseShippingTaxDetails);
        }

        //Process taxable items that are not product or shipping
        $this->processExtraTaxables($total, $itemsByType);

        //Save applied taxes for each item and the quote in aggregation
        $this->processAppliedTaxes($total, $shippingAssignment, $itemsByType);

        // Defensive safeguard: if Magento only kept shipping tax in totals, add product tax
        // This handles cases where processAppliedTaxes() or other Magento processes
        // might have dropped product tax from the order totals
        $shippingTaxTotal = (float) ($taxAmounts[self::ITEM_TYPE_SHIPPING] ?? 0);
        $currentTaxTotal = (float) $total->getTaxAmount();
        
        // Check if product tax exists but wasn't included in totals
        // Compare current total to shipping tax (with small tolerance for rounding)
        if ($productTaxTotal > 0.0001 && abs($currentTaxTotal - $shippingTaxTotal) < 0.0001) {
            $this->tclogger->info(
                sprintf('Product tax missing from totals. Adding %.2f to total tax.', $productTaxTotal)
            );
            $total->setTaxAmount($currentTaxTotal + $productTaxTotal);
            $total->setBaseTaxAmount((float) $total->getBaseTaxAmount() + $productTaxTotal);
            $total->addTotalAmount('tax', $productTaxTotal);
            $total->addBaseTotalAmount('tax', $productTaxTotal);
        }

        if ($this->includeExtraTax()) {
            $total->addTotalAmount('extra_tax', $total->getExtraTaxAmount());
            $total->addBaseTotalAmount('extra_tax', $total->getBaseExtraTaxAmount());
        }

        return $this;
    }

    /**
     * Total the looked-up tax of a quote item's children.
     *
     * The tax a dynamic-price bundle's parent line displays: it is not sent to
     * TaxCloud itself, so this is the only amount it can honestly carry.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $quoteItem
     * @param array $taxAmounts Lookup amounts keyed by tax-calculation item id
     * @return float
     */
    private function sumChildTaxAmounts($quoteItem, array $taxAmounts)
    {
        $sum = 0.0;

        foreach ($quoteItem->getChildren() as $child) {
            $childCode = $child->getTaxCalculationItemId();
            if ($childCode === null) {
                continue;
            }
            $sum += (float) ($taxAmounts[self::ITEM_TYPE_PRODUCT][$childCode] ?? 0);
        }

        return $sum;
    }
}
