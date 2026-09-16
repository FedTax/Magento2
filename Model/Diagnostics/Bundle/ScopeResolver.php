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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns a request's scope (or its order) into the stores a bundle covers.
 */
class ScopeResolver
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    /**
     * @param BundleRequest       $request
     * @param OrderInterface|null $order For a per-order bundle: the scope is always the order's store
     * @return BundleScope
     * @throws NoSuchEntityException When the requested website or store does not exist
     */
    public function resolve(BundleRequest $request, ?OrderInterface $order = null): BundleScope
    {
        if ($order !== null) {
            return $this->forStore((int) $order->getStoreId());
        }

        switch ($request->getScopeType()) {
            case BundleRequest::SCOPE_STORE:
                return $this->forStore((int) $request->getScopeId());
            case BundleRequest::SCOPE_WEBSITE:
                return $this->forWebsite((int) $request->getScopeId());
            default:
                return $this->forDefault();
        }
    }

    /**
     * @param int $storeId
     * @return BundleScope
     * @throws NoSuchEntityException
     */
    private function forStore(int $storeId): BundleScope
    {
        $store = $this->storeManager->getStore($storeId);
        $website = $this->storeManager->getWebsite($store->getWebsiteId());

        return new BundleScope(
            BundleRequest::SCOPE_STORE,
            (int) $store->getId(),
            (string) $store->getCode(),
            [(int) $store->getId() => $store],
            [(int) $website->getId() => $website]
        );
    }

    /**
     * @param int $websiteId
     * @return BundleScope
     * @throws NoSuchEntityException
     */
    private function forWebsite(int $websiteId): BundleScope
    {
        $website = $this->storeManager->getWebsite($websiteId);
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getWebsiteId() === (int) $website->getId()) {
                $stores[(int) $store->getId()] = $store;
            }
        }

        return new BundleScope(
            BundleRequest::SCOPE_WEBSITE,
            (int) $website->getId(),
            (string) $website->getCode(),
            $stores,
            [(int) $website->getId() => $website]
        );
    }

    /**
     * @return BundleScope
     */
    private function forDefault(): BundleScope
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            $stores[(int) $store->getId()] = $store;
        }
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websites[(int) $website->getId()] = $website;
        }
        ksort($stores);
        ksort($websites);

        return new BundleScope(BundleRequest::SCOPE_DEFAULT, null, 'default', $stores, $websites);
    }
}
