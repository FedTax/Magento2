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

namespace Taxcloud\Magento2\Model\Canada;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * Turns the scope being edited in Stores → Configuration into the store whose
 * settings apply there: the store view itself, a website's default store view
 * (so website-scope values resolve), or null for the default scope. Never the
 * ambient store.
 *
 * Same rules the connection test applies to the same URL parameters.
 */
class ConfigScopeStore
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
     * @param int|string|null $websiteParam website id of the edited scope
     * @param int|string|null $storeParam store id of the edited scope
     * @return int|string|\Magento\Store\Api\Data\StoreInterface|null
     */
    public function resolve($websiteParam, $storeParam)
    {
        if ($storeParam !== null && $storeParam !== '') {
            return $storeParam;
        }
        if ($websiteParam !== null && $websiteParam !== '') {
            try {
                $website = $this->storeManager->getWebsite($websiteParam);
            } catch (\Throwable $e) {
                return null;
            }
            if ($website instanceof Website) {
                $defaultStore = $website->getDefaultStore();
                if ($defaultStore && $defaultStore->getId()) {
                    return $defaultStore;
                }
            }
        }

        return null;
    }
}
