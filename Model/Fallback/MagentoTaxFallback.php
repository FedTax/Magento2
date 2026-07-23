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

namespace Taxcloud\Magento2\Model\Fallback;

use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\TaxCalculationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Computes tax using Magento's native tax engine as a fallback when a TaxCloud
 * lookup cannot be completed (and the store has opted into fallback).
 *
 * Returns the same shape the live lookup does — per-item product tax keyed by
 * item code plus a single shipping total — so the collector consumes it
 * identically. Fails soft: any error yields a zeroed result rather than
 * breaking totals collection.
 */
class MagentoTaxFallback
{
    public const ITEM_TYPE_SHIPPING = 'shipping';
    public const ITEM_TYPE_PRODUCT = 'product';
    public const KEY_ITEM = 'item';

    /**
     * @var AddressInterfaceFactory
     */
    private $customerAddressFactory;

    /**
     * @var QuoteDetailsInterfaceFactory
     */
    private $quoteDetailsFactory;

    /**
     * @var QuoteDetailsItemInterfaceFactory
     */
    private $quoteDetailsItemFactory;

    /**
     * @var TaxClassKeyInterfaceFactory
     */
    private $taxClassKeyFactory;

    /**
     * @var TaxCalculationInterface
     */
    private $taxCalculationService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param AddressInterfaceFactory          $customerAddressFactory
     * @param QuoteDetailsInterfaceFactory     $quoteDetailsFactory
     * @param QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory
     * @param TaxClassKeyInterfaceFactory      $taxClassKeyFactory
     * @param TaxCalculationInterface          $taxCalculationService
     * @param LoggerInterface|null             $logger
     */
    public function __construct(
        AddressInterfaceFactory $customerAddressFactory,
        QuoteDetailsInterfaceFactory $quoteDetailsFactory,
        QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory,
        TaxClassKeyInterfaceFactory $taxClassKeyFactory,
        TaxCalculationInterface $taxCalculationService,
        ?LoggerInterface $logger = null
    ) {
        $this->customerAddressFactory = $customerAddressFactory;
        $this->quoteDetailsFactory = $quoteDetailsFactory;
        $this->quoteDetailsItemFactory = $quoteDetailsItemFactory;
        $this->taxClassKeyFactory = $taxClassKeyFactory;
        $this->taxCalculationService = $taxCalculationService;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get Magento's default tax rates for fallback when TaxCloud fails.
     *
     * @param array $itemsByType
     * @param \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment
     * @param \Magento\Quote\Model\Quote $quote
     * @return array
     */
    public function calculate($itemsByType, $shippingAssignment, $quote)
    {
        $this->logger->info('Falling back to Magento tax rates');

        $result = [self::ITEM_TYPE_PRODUCT => [], self::ITEM_TYPE_SHIPPING => 0];

        $address = $shippingAssignment->getShipping()->getAddress();
        if (!$address) {
            return $result;
        }

        try {
            // Build customer address for tax calculation
            $customerAddress = $this->customerAddressFactory->create();
            $this->setFromAddress($customerAddress, $address);

            // Create quote details
            $quoteDetails = $this->quoteDetailsFactory->create();
            $quoteDetails->setBillingAddress($customerAddress);
            $quoteDetails->setShippingAddress($customerAddress);
            $quoteDetails->setCustomerTaxClassId($quote->getCustomerTaxClassId());
            $quoteDetails->setItems([]);

            $keyedAddressItems = [];
            foreach ($shippingAssignment->getItems() as $item) {
                // Skip composite child lines with no tax calculation id (null
                // array key is a PHP 8 deprecation, fatal in developer mode).
                $taxCalculationItemId = $item->getTaxCalculationItemId();
                if ($taxCalculationItemId === null) {
                    continue;
                }
                $keyedAddressItems[$taxCalculationItemId] = $item;
            }

            $items = [];
            if (isset($itemsByType[self::ITEM_TYPE_PRODUCT])) {
                foreach ($itemsByType[self::ITEM_TYPE_PRODUCT] as $code => $itemTaxDetail) {
                    $item = $keyedAddressItems[$code];
                    if ($item->getProduct()->getTaxClassId() === '0') {
                        continue;
                    }

                    $quoteDetailsItem = $this->quoteDetailsItemFactory->create();
                    $quoteDetailsItem->setCode($code);
                    $quoteDetailsItem->setType(self::ITEM_TYPE_PRODUCT);
                    $taxClassKey = $this->taxClassKeyFactory->create();
                    $taxClassKey->setType(TaxClassKeyInterface::TYPE_ID);
                    $taxClassKey->setValue($item->getProduct()->getTaxClassId());
                    $quoteDetailsItem->setTaxClassKey($taxClassKey);
                    $quoteDetailsItem->setUnitPrice($item->getPrice());
                    $quoteDetailsItem->setQuantity($item->getQty());
                    $quoteDetailsItem->setDiscountAmount($item->getDiscountAmount());
                    $quoteDetailsItem->setIsTaxIncluded(false);

                    $items[] = $quoteDetailsItem;
                }
            }

            if (isset($itemsByType[self::ITEM_TYPE_SHIPPING])) {
                foreach ($itemsByType[self::ITEM_TYPE_SHIPPING] as $code => $itemTaxDetail) {
                    $quoteDetailsItem = $this->quoteDetailsItemFactory->create();
                    $quoteDetailsItem->setCode($code);
                    $quoteDetailsItem->setType(self::ITEM_TYPE_SHIPPING);
                    $taxClassKey = $this->taxClassKeyFactory->create();
                    $taxClassKey->setType(TaxClassKeyInterface::TYPE_ID);
                    $taxClassKey->setValue(0); // Default tax class for shipping
                    $quoteDetailsItem->setTaxClassKey($taxClassKey);
                    $quoteDetailsItem->setUnitPrice($itemTaxDetail[self::KEY_ITEM]->getRowTotal());
                    $quoteDetailsItem->setQuantity(1);
                    $quoteDetailsItem->setDiscountAmount(0);
                    $quoteDetailsItem->setIsTaxIncluded(false);

                    $items[] = $quoteDetailsItem;
                }
            }

            $quoteDetails->setItems($items);

            // Calculate tax using Magento's service
            $taxDetails = $this->taxCalculationService->calculateTax($quoteDetails, $quote->getStoreId());

            // Process results
            foreach ($taxDetails->getItems() as $item) {
                $code = $item->getCode();
                $taxAmount = $item->getRowTax();

                if ($item->getType() === self::ITEM_TYPE_SHIPPING) {
                    $result[self::ITEM_TYPE_SHIPPING] += $taxAmount;
                } else {
                    $result[self::ITEM_TYPE_PRODUCT][$code] = $taxAmount;
                }
            }

            $this->logger->info('Successfully calculated Magento tax rates');
            $this->logger->debug('Magento fallback tax rates: ' . json_encode($result));
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Error calculating Magento tax rates: ' . $e->getMessage());
            return $result;
        }
    }

    /**
     * Copy address fields from a quote address onto a customer address.
     *
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
}
