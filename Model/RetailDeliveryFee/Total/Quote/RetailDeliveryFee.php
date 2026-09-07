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

declare(strict_types=1);

namespace Taxcloud\Magento2\Model\RetailDeliveryFee\Total\Quote;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;

/**
 * Charges the Colorado Retail Delivery Fee as its own quote total.
 *
 * A dedicated total — not part of tax — because the fee is a per-delivery
 * charge TaxCloud files separately from sales tax; folding it into the tax
 * total would misstate both. Registered between the tax collector (450) and
 * grand total (550), so the grand-total collector picks the amount up from
 * the total-amounts it sums.
 *
 * Amounts reset to zero on every pass before eligibility is consulted, so a
 * quote that loses eligibility (destination left Colorado, method changed,
 * last taxable tangible item removed) sheds the fee on the next collect.
 */
class RetailDeliveryFee extends AbstractTotal
{
    /**
     * Total code, also the checkout total_segments key.
     */
    public const CODE = 'taxcloud_rdf';

    /**
     * @var FeeService
     */
    private $feeService;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @param FeeService $feeService
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(FeeService $feeService, PriceCurrencyInterface $priceCurrency)
    {
        $this->feeService = $feeService;
        $this->priceCurrency = $priceCurrency;
        $this->setCode(self::CODE);
    }

    /**
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return $this
     */
    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        parent::collect($quote, $shippingAssignment, $total);

        // Typed as core types it (CommonTaxCollector::mapAddress): a shipping
        // assignment's address is the concrete quote address.
        /** @var \Magento\Quote\Model\Quote\Address $address */
        $address = $shippingAssignment->getShipping()->getAddress();

        $total->setTotalAmount(self::CODE, 0);
        $total->setBaseTotalAmount(self::CODE, 0);
        $address->setTaxcloudRdfAmount(0);
        $address->setBaseTaxcloudRdfAmount(0);

        if (!$shippingAssignment->getItems()) {
            return $this;
        }

        // The quote's store, never the ambient one: admin order creation and
        // API checkouts run under the default store view.
        $storeId = $quote->getStoreId();

        if (!$this->feeService->isEligible($address, $storeId)) {
            return $this;
        }

        // The configured amount is in base currency (USD — the fee is a US
        // state charge); the displayed amount converts like any other total.
        $baseAmount = $this->feeService->getAmount($storeId);
        $amount = $this->priceCurrency->round(
            $this->priceCurrency->convert($baseAmount, $quote->getStore())
        );

        $total->setTotalAmount(self::CODE, $amount);
        $total->setBaseTotalAmount(self::CODE, $baseAmount);
        $address->setTaxcloudRdfAmount($amount);
        $address->setBaseTaxcloudRdfAmount($baseAmount);

        return $this;
    }

    /**
     * Expose the fee as a totals segment for cart/checkout rendering.
     *
     * Returns null when no fee is charged so no segment is rendered at all,
     * rather than a $0.00 line.
     *
     * @param Quote $quote
     * @param Total $total
     * @return array|null
     */
    public function fetch(Quote $quote, Total $total)
    {
        // Two callers, two shapes. During totals COLLECTION the Total carries
        // the total-amounts registry this collector wrote. During totals
        // READING — TotalsReader, which is what builds the checkout/cart
        // total_segments — a fresh Total is hydrated from the address DATA,
        // where the amount lives in the taxcloud_rdf_amount column and the
        // registry is empty. Read both, or the row renders in neither.
        $amount = (float) $total->getTotalAmount(self::CODE);
        if ($amount <= 0) {
            $amount = (float) $total->getTaxcloudRdfAmount();
        }
        if ($amount <= 0) {
            return null;
        }

        // The fixed, translatable fee label. Colorado requires the fee to be
        // separately stated under a recognizable name, so there is no label
        // setting.
        return [
            'code' => self::CODE,
            'title' => __('Colorado Retail Delivery Fee'),
            'value' => $amount,
        ];
    }
}
