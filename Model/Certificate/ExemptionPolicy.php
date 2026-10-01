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
 *   - mayManage()          may this customer CHANGE their exemptions
 *
 * Seeing and changing are separated on purpose. Nothing verifies a certificate
 * — one created with an invented tax id is accepted, confirmed against the live
 * API — so creating or attaching one is choosing to stop paying tax, and it is
 * confined to customers a merchant has vouched for by nominating their group.
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
     * Signed in, on a store where the feature is switched on. There is no
     * narrower notion of who exemptions are "for": a certificate belongs to a
     * customer, and an administrator decides which one applies by attaching it.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function isVisibleTo($customer, $store = null): bool
    {
        return $this->isEnabled($store) && $customer !== null && (bool) $customer->getId();
    }

    /**
     * Whether this customer may create, attach and detach their own certificates.
     *
     * Needs exemptions on, self-service on, and the customer's group among the
     * nominated ones — all for this store. The group is read off the customer
     * object handed in, which the storefront loads from the repository on each
     * request, so moving someone out of a nominated group takes effect on
     * their next click rather than when their session ends.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function mayManage($customer, $store = null): bool
    {
        if (!$this->isVisibleTo($customer, $store) || !$this->config->areCustomerCertificatesEnabled($store)) {
            return false;
        }

        $groupId = $customer->getGroupId();
        if ($groupId === null || $groupId === '') {
            return false;
        }

        return in_array((int) $groupId, $this->config->getCustomerCertificateGroups($store), true);
    }
}
