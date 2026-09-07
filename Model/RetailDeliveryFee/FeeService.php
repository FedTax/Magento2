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

namespace Taxcloud\Magento2\Model\RetailDeliveryFee;

use Magento\Directory\Model\RegionFactory;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Single authority for the Colorado Retail Delivery Fee: whether an address
 * owes it, and for how much.
 *
 * Every consumer — the quote total collector, both transports' lookup and
 * capture builders, and the refund path — asks this service instead of
 * re-deriving the rules, so eligibility and amount cannot drift between the
 * charge, the TaxCloud cart, and the filed order.
 *
 * The amount is the module's authority, not TaxCloud's: TaxCloud zero-rates
 * the fee line and remits whatever amount is sent, without calculating or
 * validating it (verified on both transports and confirmed by TaxCloud).
 * Customer exemption certificates do not exempt the fee, so eligibility
 * deliberately ignores them.
 */
class FeeService
{
    /**
     * Sentinel cart-line ItemID for the fee. Reserved: the response handler
     * routes this id to nowhere (the line's tax is always zero), and refunds
     * reference it to reverse the fee.
     */
    public const ITEM_ID = 'co-rdf';

    /**
     * The fee's display label is the literal 'Colorado Retail Delivery Fee'
     * at every render site (phpcs requires literals inside __()). Fixed by
     * design: Colorado requires the fee to be separately stated under a
     * recognizable name, so there is deliberately no label setting.
     */

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RegionFactory
     */
    private $regionFactory;

    /**
     * @param TaxcloudConfig $config
     * @param RegionFactory $regionFactory
     */
    public function __construct(TaxcloudConfig $config, RegionFactory $regionFactory)
    {
        $this->config = $config;
        $this->regionFactory = $regionFactory;
    }

    /**
     * Whether this shipping address owes the Colorado Retail Delivery Fee.
     *
     * All four statutory/config gates, re-evaluated on every call so the fee
     * follows cart, destination and shipping-method changes: feature enabled
     * for the given store; destination is a US Colorado address; the selected
     * shipping method is one the merchant mapped as motor-vehicle delivery;
     * and the address carries at least one taxable tangible item.
     *
     * @param \Magento\Quote\Model\Quote\Address $address Shipping address
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     *        Store of the quote being processed — pass it explicitly; the
     *        ambient store is wrong in admin/API/cron contexts
     * @return bool
     */
    public function isEligible($address, $store = null): bool
    {
        if (!$this->config->isCoRdfEnabled($store)) {
            return false;
        }

        if ($address->getCountryId() !== 'US' || $this->resolveRegionCode($address) !== 'CO') {
            return false;
        }

        $shippingMethod = (string) $address->getShippingMethod();
        if ($shippingMethod === ''
            || !in_array($shippingMethod, $this->config->getCoRdfDeliveryMethods($store), true)
        ) {
            return false;
        }

        return $this->hasTaxableTangibleItem($address);
    }

    /**
     * The fee amount to charge per eligible delivery.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return float
     */
    public function getAmount($store = null): float
    {
        return $this->config->getCoRdfAmount($store);
    }

    /**
     * The TIC identifying the fee line to TaxCloud.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    public function getTic($store = null): string
    {
        return $this->config->getCoRdfTic($store);
    }

    /**
     * Whether the address carries at least one taxable tangible item.
     *
     * Tangible = not virtual/downloadable; taxable = tax class other than
     * None ('0'), the same rule the lookup builder uses to decide which
     * lines reach TaxCloud.
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return bool
     */
    private function hasTaxableTangibleItem($address): bool
    {
        foreach ($address->getAllItems() as $item) {
            if ($item->getIsVirtual()) {
                continue;
            }
            $product = $item->getProduct();
            if ($product && (string) $product->getTaxClassId() === '0') {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * Two-letter region code: the address's own code when present, else a
     * directory lookup by region id (same precedence as the request builder).
     *
     * @param \Magento\Framework\DataObject $address
     * @return string
     */
    private function resolveRegionCode($address): string
    {
        $code = $address->getRegionCode();
        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        $regionId = $address->getRegionId();
        if (empty($regionId)) {
            return '';
        }

        $code = $this->regionFactory->create()->load($regionId)->getCode();

        return is_string($code) ? strtoupper($code) : '';
    }
}
