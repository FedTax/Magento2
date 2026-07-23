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
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling Product TIC (Taxability Information Code) logic
 * Handles cases where products have been deleted or don't have custom TIC attributes
 */
class ProductTicService
{
    /**
     * Default TIC fallback value when configuration is empty or null
     */
    public const DEFAULT_TIC = '00000';

    /**
     * Default shipping TIC fallback value when configuration is empty or null
     */
    public const DEFAULT_SHIPPING_TIC = '11010';

    /**
     * Product type whose TIC must come from the purchased child simple, not the
     * parent. Kept as a literal so this module doesn't take a hard dependency on
     * Magento_ConfigurableProduct.
     */
    public const TYPE_CONFIGURABLE = 'configurable';
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * TaxCloud logger. di.xml binds this to the config-gated GatewayLogger so
     * these records honor the store's logging mode like every other class.
     *
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->productRepository = $productRepository;
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
        $product = $this->resolveTaxableProduct($item);

        // Handle case where product has been deleted
        if (!$product || !$product->getId()) {
            $this->logger->warning(
                'Product not found for item ' . $item->getSku() . ' in ' . $context . ', using default TIC'
            );
            return $this->getDefaultTic();
        }
        
        try {
            $productModel = $this->productRepository->getById($product->getId());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->warning(
                'Product ID ' . $product->getId() . ' not found in repository for ' . $context . ', using default TIC'
            );
            return $this->getDefaultTic();
        }
        $tic = $productModel->getCustomAttribute('taxcloud_tic');
        
        return $tic ? $tic->getValue() : $this->getDefaultTic();
    }

    /**
     * Resolve the product whose TIC should be used for a line item.
     *
     * For configurable products the line item carries the configurable parent
     * (Magento's tax collector maps the parent, not the chosen simple), but the
     * thing actually shipped — and therefore the TIC TaxCloud needs — is the
     * selected simple variant. Redirect to that child. All other product types
     * (simple, bundle, grouped) keep the item's own product.
     *
     * @param \Magento\Sales\Model\Order\Item|\Magento\Quote\Model\Quote\Item $item
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    private function resolveTaxableProduct($item)
    {
        $product = $item->getProduct();
        if (!$product || $product->getTypeId() !== self::TYPE_CONFIGURABLE) {
            return $product;
        }

        $childProduct = $this->getChildProduct($item);

        return $childProduct ?: $product;
    }

    /**
     * Pull the purchased child simple's product off a configurable line item.
     * Quote items expose children via getChildren(); order items via
     * getChildrenItems(). Returns null if no usable child product is found.
     *
     * @param \Magento\Sales\Model\Order\Item|\Magento\Quote\Model\Quote\Item $item
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    private function getChildProduct($item)
    {
        $children = [];
        if (method_exists($item, 'getChildren') && $item->getChildren()) {
            $children = $item->getChildren();
        } elseif (method_exists($item, 'getChildrenItems') && $item->getChildrenItems()) {
            $children = $item->getChildrenItems();
        }

        foreach ($children as $child) {
            $childProduct = $child->getProduct();
            if ($childProduct && $childProduct->getId()) {
                return $childProduct;
            }
        }

        return null;
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
