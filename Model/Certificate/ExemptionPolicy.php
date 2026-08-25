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

namespace Taxcloud\Magento2\Model\Certificate;

use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Who may do what with exemption certificates on a given store.
 *
 * One place, because three surfaces ask the same questions — the account area,
 * checkout, and the admin screens — and three interpretations of "is this
 * customer exempt" is how a permission gap gets built. It also keeps the
 * answers store-scoped without every caller remembering to pass a store.
 *
 * The questions are deliberately distinct:
 *
 *   - isEnabled()          does this store offer exemptions at all
 *   - isVisibleTo()        may this customer SEE the exemption interface
 *   - isTreatedAsExempt()  should a covering certificate apply on its own
 *   - mayCreate()          may this customer create a certificate
 *
 * Seeing and creating are separated on purpose. Nothing verifies a certificate
 * — one created with an invented tax id is accepted, confirmed against the live
 * API — so creating one is the act with consequences, and it is confined to
 * customers a merchant has already vouched for by putting them in an exempt
 * group. Selecting among certificates a merchant has already accepted is not
 * the same risk.
 */
class ExemptionPolicy
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param TaxcloudConfig $config
     */
    public function __construct(TaxcloudConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Whether this store offers exemption certificates at all.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isEnabled($store = null): bool
    {
        return $this->config->isEnabled($store) && $this->config->areExemptionsEnabled($store);
    }

    /**
     * Whether this customer may see the exemption interface.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer Null for a guest
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isVisibleTo($customer, $store = null): bool
    {
        if (!$this->isEnabled($store) || $customer === null || !$customer->getId()) {
            // Guests hold no certificates, so there is nothing to show them.
            return false;
        }

        if (!$this->config->isRestrictedToExemptGroups($store)) {
            return true;
        }

        return $this->isInExemptGroup($customer, $store);
    }

    /**
     * Whether a covering certificate should apply to this customer's orders
     * without anyone choosing it.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isTreatedAsExempt($customer, $store = null): bool
    {
        if (!$this->isEnabled($store) || $customer === null || !$customer->getId()) {
            return false;
        }

        return $this->isInExemptGroup($customer, $store);
    }

    /**
     * Whether this customer may create a certificate.
     *
     * Stricter than merely seeing the interface: creating a certificate is
     * asserting an exemption nobody checks, so it is confined to customers the
     * merchant has already vouched for. A store wanting it open to everyone can
     * nominate a group containing everyone — a deliberate act, not a default.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function mayCreate($customer, $store = null): bool
    {
        return $this->isTreatedAsExempt($customer, $store);
    }

    /**
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    private function isInExemptGroup($customer, $store): bool
    {
        $groups = $this->config->getExemptCustomerGroups($store);
        if ($groups === []) {
            return false;
        }

        $groupId = $customer->getGroupId();

        return $groupId !== null && in_array((int) $groupId, $groups, true);
    }
}
