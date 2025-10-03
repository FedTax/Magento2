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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\ProductFactory;
use Taxcloud\Magento2\Logger\Logger;

/**
 * Service for handling Product TIC (Taxability Information Code) logic
 * Handles cases where products have been deleted or don't have custom TIC attributes
 */
class ProductTicService
{
    /**
     * Default TIC fallback value when configuration is empty or null
     */
    const DEFAULT_TIC = '00000';

    /**
     * Default shipping TIC fallback value when configuration is empty or null
     */
    const DEFAULT_SHIPPING_TIC = '11010';
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductFactory $productFactory
     * @param Logger $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ProductFactory $productFactory,
        Logger $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->productFactory = $productFactory;
        $this->logger = $logger;
    }

    /**
     * Get product TIC (Taxability Information Code) with null safety
     * Handles cases where product has been deleted or doesn't have custom TIC
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @param string $context Context for logging (e.g., 'lookupTaxes', 'returnOrder')
     * @return string The TIC value
     */
    public function getProductTic($item, $context = '')
    {
        $product = $item->getProduct();
        
        // Handle case where product has been deleted
        if (!$product || !$product->getId()) {
            $this->logger->info(
                'Product not found for item ' . $item->getSku() . ' in ' . $context . ', using default TIC'
            );
            return $this->getDefaultTic();
        }
        
        $productModel = $this->productFactory->create()->load($product->getId());
        $tic = $productModel->getCustomAttribute('taxcloud_tic');
        
        return $tic ? $tic->getValue() : $this->getDefaultTic();
    }

    /**
     * Get the default TIC value from configuration
     * Falls back to DEFAULT_TIC if configuration is empty or null
     *
     * @return string
     */
    public function getDefaultTic()
    {
        $value = $this->scopeConfig->getValue(
            'tax/taxcloud_settings/default_tic',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        return ($value !== null && $value !== '') ? $value : self::DEFAULT_TIC;
    }

    /**
     * Check if a product exists and is valid
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @return bool
     */
    public function isProductValid($item)
    {
        $product = $item->getProduct();
        return $product && $product->getId();
    }

    /**
     * Get the shipping TIC value from configuration
     * Falls back to DEFAULT_SHIPPING_TIC if configuration is empty or null
     *
     * @return string
     */
    public function getShippingTic()
    {
        $value = $this->scopeConfig->getValue(
            'tax/taxcloud_settings/shipping_tic',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        return ($value !== null && $value !== '') ? $value : self::DEFAULT_SHIPPING_TIC;
    }
}
