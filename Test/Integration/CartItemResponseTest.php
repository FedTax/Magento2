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

namespace Taxcloud\Magento2\Test\Integration;

use Taxcloud\Magento2\Model\CartItemResponseHandler;

/**
 * Test for the "array offset on int" bug fix
 * 
 * The bug occurred when CartItemResponse was a single item object but was treated
 * as an array of items, causing foreach to iterate over keys instead of values.
 */
class CartItemResponseTest
{
    /**
     * Test the CartItemResponse processing logic - prevents "array offset on int" error
     */
    public static function testCartItemResponseProcessing()
    {
        echo "=== Testing CartItemResponse Processing (Bug Fix) ===\n\n";
        
        $handler = new CartItemResponseHandler();
        
        $testCases = [
            [
                'name' => 'Single item',
                'input' => ['CartItemIndex' => 0, 'TaxAmount' => 0],
                'expected_processed' => 1,
                'description' => 'The exact scenario from client bug report'
            ],
            [
                'name' => 'Multiple items',
                'input' => [
                    0 => ['CartItemIndex' => 0, 'TaxAmount' => 5.00],
                    1 => ['CartItemIndex' => 1, 'TaxAmount' => 2.50]
                ],
                'expected_processed' => 2,
                'description' => 'Normal case with multiple cart items'
            ],
            [
                'name' => 'Invalid integer input',
                'input' => 0,
                'expected_processed' => 0,
                'description' => 'Edge case that would cause the original bug'
            ],
            [
                'name' => 'Invalid string input',
                'input' => 'invalid',
                'expected_processed' => 0,
                'description' => 'Edge case that would cause the original bug'
            ],
            [
                'name' => 'Empty array input',
                'input' => [],
                'expected_processed' => 0,
                'description' => 'Edge case with empty response'
            ]
        ];
        
        $allPassed = true;
        
        foreach ($testCases as $testCase) {
            echo "Testing: {$testCase['name']}\n";
            echo "Description: {$testCase['description']}\n";
            echo "Input: " . print_r($testCase['input'], true) . "\n";
            
            $crashed = false;
            $processed = 0;
            
            try {
                $processedItems = $handler->processCartItemResponses($testCase['input']);
                $processed = count($processedItems);
            } catch (Throwable $e) {
                $crashed = true;
                echo "✗ Crashed with error: " . $e->getMessage() . "\n";
            }
            
            if ($crashed) {
                echo "✗ Test failed - should not have crashed\n";
                $allPassed = false;
            } elseif ($processed !== $testCase['expected_processed']) {
                echo "✗ Test failed - expected {$testCase['expected_processed']} processed, got $processed\n";
                $allPassed = false;
            } else {
                echo "✓ Test passed\n";
            }
            
            echo "\n";
        }
        
        return $allPassed;
    }
    
    /**
     * Run all tests
     */
    public static function run()
    {
        $processingTest = self::testCartItemResponseProcessing();
        
        echo "\n=== Test Results ===\n";
        echo "CartItemResponse processing test: " . ($processingTest ? "PASSED" : "FAILED") . "\n";
        
        if ($processingTest) {
            echo "\n✓ All tests passed!\n";
            return true;
        } else {
            echo "\n✗ Some tests failed.\n";
            return false;
        }
    }
}

// Run tests if this file is executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    CartItemResponseTest::run();
}
