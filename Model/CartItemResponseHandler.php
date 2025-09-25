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
 * Handles processing of CartItemResponse data from TaxCloud API
 *
 */
class CartItemResponseHandler
{
    /**
     * Process cart item responses and return processed items
     *
     * @param mixed $cartItemResponse Raw CartItemResponse from TaxCloud API
     * @return array Array of processed cart items with CartItemIndex and TaxAmount
     */
    public function processCartItemResponses($cartItemResponse)
    {
        $processedItems = [];
        
        // Handle empty response
        if (empty($cartItemResponse)) {
            return $processedItems;
        }
        
        // Ensure we have an array of cart items
        if (!(is_array($cartItemResponse) && isset($cartItemResponse[0]) && is_array($cartItemResponse[0]))) {
            $cartItemResponse = array($cartItemResponse);
        }

        foreach ($cartItemResponse as $c) {
            if (!is_array($c) || !isset($c['CartItemIndex'], $c['TaxAmount'])) {
                // Skip invalid items
                continue;
            }
            
            $processedItems[] = [
                'CartItemIndex' => $c['CartItemIndex'],
                'TaxAmount' => $c['TaxAmount']
            ];
        }
        
        return $processedItems;
    }
    
    /**
     * Process cart item responses and apply them to tax result in one step
     *
     * @param mixed $cartItemResponse Raw CartItemResponse from TaxCloud API
     * @param array $cartItems Original cart items for reference
     * @param array $indexedItems Indexed items mapping
     * @param array &$result Tax result array to update
     */
    public function processAndApplyCartItemResponses($cartItemResponse, $cartItems, $indexedItems, &$result)
    {
        $processedItems = $this->processCartItemResponses($cartItemResponse);
        $this->applyProcessedItemsToResult($processedItems, $cartItems, $indexedItems, $result);
    }
    
    /**
     * Apply processed cart items to tax result
     *
     * @param array $processedItems Array of processed cart items
     * @param array $cartItems Original cart items for reference
     * @param array $indexedItems Indexed items mapping
     * @param array &$result Tax result array to update
     */
    public function applyProcessedItemsToResult($processedItems, $cartItems, $indexedItems, &$result)
    {
        foreach ($processedItems as $item) {
            $index = $item['CartItemIndex'];
            $taxAmount = $item['TaxAmount'];
            
            if ($cartItems[$index]['ItemID'] === 'shipping') {
                $result[Api::ITEM_TYPE_SHIPPING] += $taxAmount;
            } else {
                $code = $indexedItems[$index];
                $result[Api::ITEM_TYPE_PRODUCT][$code] = $taxAmount;
            }
        }
    }
}
