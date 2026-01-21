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
     * Magento Config Object
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig = null;

    /**
     * TaxCloud Api Object
     *
     * @var \Taxcloud\Magento2\Model\Api
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Taxcloud\Magento2\Logger\Logger
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
     * @param \Magento\Framework\Serialize\Serializer\Json $serializer
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Taxcloud\Magento2\Api $tcapi
     * @param \Taxcloud\Magento2\Logger\Logger $tclogger
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
        \Magento\Framework\Serialize\Serializer\Json $serializer = null,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Taxcloud\Magento2\Model\Api $tcapi,
        \Taxcloud\Magento2\Logger\Logger $tclogger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->tcapi = $tcapi;

        if ($scopeConfig->getValue('tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)) {
            $this->tclogger = $tclogger;
        } else {
            $this->tclogger = new class {
                public function info()
                {
                }
            };
        }

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

        if (!$this->scopeConfig->getValue(
            'tax/taxcloud_settings/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )) {
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
        // $this->tclogger->info(json_encode($taxAmounts, JSON_PRETTY_PRINT));

        $keyedAddressItems = [];
        foreach ($shippingAssignment->getItems() as $item) {
            $keyedAddressItems[$item->getTaxCalculationItemId()] = $item;
        }

        $productTaxTotal = 0.0;

        if (isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
            foreach ($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                $taxDetail = $itemTaxDetail[self::KEY_ITEM];
                $baseTaxDetail = $itemTaxDetail[self::KEY_BASE_ITEM];

                $quoteItem = $keyedAddressItems[$code];

                if ($quoteItem->getProduct()->getTaxClassId() === '0' || $quoteItem->getQty() === 0) {
                    $taxAmount = 0;
                    $taxAmountPer = 0;
                } else {
                    $taxAmount = $taxAmounts[self::ITEM_TYPE_PRODUCT][$code] ?? 0;
                    $taxAmountPer = $taxAmount / $quoteItem->getQty();
                }

                $productTaxTotal += (float) $taxAmount;

                // Calculate base tax amount (using same value as baseTaxDetail will be set to)
                $baseTaxAmount = $taxAmount;
                $baseTaxAmountPer = $baseTaxAmount / $quoteItem->getQty();

                // Persist tax onto quote item so tax does not get lost downstream
                // This ensures tax is available when quote is converted to order
                $quoteItem->setTaxAmount($taxAmount);
                $quoteItem->setBaseTaxAmount($baseTaxAmount);
                $quoteItem->setTaxPercent($taxDetail->getRowTotal() > 0 ? round(100 * $taxAmount / $taxDetail->getRowTotal(), 2) : 0);
                $quoteItem->setPriceInclTax($quoteItem->getPrice() + $taxAmountPer);
                $quoteItem->setBasePriceInclTax($quoteItem->getBasePrice() + $baseTaxAmountPer);
                $quoteItem->setRowTotalInclTax($quoteItem->getRowTotal() + $taxAmount);
                $quoteItem->setBaseRowTotalInclTax($quoteItem->getBaseRowTotal() + $baseTaxAmount);

                $taxDetail->setRowTax($taxAmount);
                $taxDetail->setPriceInclTax($taxDetail->getPrice() + $taxAmountPer);
                $taxDetail->setRowTotalInclTax($taxDetail->getRowTotal() + $taxAmount);
                $taxDetail->setAppliedTaxes([]);
                if ($taxDetail->getRowTotal() > 0) {
                    $taxDetail->setTaxPercent(round(100 * $taxDetail->getRowTax() / $taxDetail->getRowTotal(), 2));
                } else {
                    $taxDetail->setTaxPercent(0);
                }

                $baseTaxDetail->setRowTax($taxAmount);
                $baseTaxDetail->setPriceInclTax($baseTaxDetail->getPrice() + $taxAmountPer);
                $baseTaxDetail->setRowTotalInclTax($baseTaxDetail->getRowTotal() + $taxAmount);
                $baseTaxDetail->setAppliedTaxes([]);
                if ($baseTaxDetail->getRowTotal() > 0) {
                    $baseTaxDetail->setTaxPercent(
                        round(100 * $baseTaxDetail->getRowTax() / $baseTaxDetail->getRowTotal(), 2)
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
                    round(100 * $shippingTaxDetails->getRowTax() / $shippingTaxDetails->getRowTotal(), 2)
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
                    round(100 * $baseShippingTaxDetails->getRowTax() / $baseShippingTaxDetails->getRowTotal(), 2)
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
}
