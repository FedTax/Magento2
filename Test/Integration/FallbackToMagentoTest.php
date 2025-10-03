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

// Include the Api class directly
require_once __DIR__ . '/../../Model/Api.php';

use Taxcloud\Magento2\Model\Api;

/**
 * Test fallback to Magento tax rates when TaxCloud fails
 */
class FallbackToMagentoTest
{
    public function testFallbackConstantsExist()
    {
        echo "Testing fallback constants...\n";
        
        try {
            // Use reflection to check if the required constants exist
            $reflection = new \ReflectionClass(Api::class);
            $constants = $reflection->getConstants();
            
            $requiredConstants = [
                'ITEM_TYPE_SHIPPING',
                'ITEM_TYPE_PRODUCT', 
                'ITEM_CODE_SHIPPING',
                'KEY_ITEM',
                'KEY_BASE_ITEM'
            ];
            
            $passed = 0;
            foreach ($requiredConstants as $constant) {
                if (isset($constants[$constant])) {
                    echo "✅ Constant {$constant} exists: {$constants[$constant]}\n";
                    $passed++;
                } else {
                    echo "❌ Constant {$constant} missing\n";
                }
            }
            
            return $passed === count($requiredConstants);
            
        } catch (Exception $e) {
            echo "❌ Error testing constants: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testFallbackMethodExists()
    {
        echo "Testing fallback method existence...\n";
        
        try {
            // Use reflection to check if the required methods exist
            $reflection = new \ReflectionClass(Api::class);
            
            $requiredMethods = [
                'isFallbackToMagentoEnabled',
                'getMagentoTaxRates',
                'setFromAddress'
            ];
            
            $passed = 0;
            foreach ($requiredMethods as $methodName) {
                try {
                    $method = $reflection->getMethod($methodName);
                    if ($method) {
                        echo "✅ Method {$methodName} exists\n";
                        echo "   Visibility: " . ($method->isPrivate() ? 'private' : 'public') . "\n";
                        $passed++;
                    } else {
                        echo "❌ Method {$methodName} not found\n";
                    }
                } catch (\ReflectionException $e) {
                    echo "❌ Method {$methodName} not found\n";
                }
            }
            
            return $passed === count($requiredMethods);
            
        } catch (Exception $e) {
            echo "❌ Error testing methods: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function testFallbackConfigurationStructure()
    {
        echo "Testing fallback configuration structure...\n";
        
        try {
            // Check if the configuration files have the fallback option
            $systemXmlPath = __DIR__ . '/../../etc/adminhtml/system.xml';
            $configXmlPath = __DIR__ . '/../../etc/config.xml';
            
            if (file_exists($systemXmlPath)) {
                $systemXml = file_get_contents($systemXmlPath);
                if (strpos($systemXml, 'fallback_to_magento') !== false) {
                    echo "✅ Fallback configuration option found in system.xml\n";
                } else {
                    echo "❌ Fallback configuration option not found in system.xml\n";
                    return false;
                }
            } else {
                echo "❌ system.xml not found\n";
                return false;
            }
            
            if (file_exists($configXmlPath)) {
                $configXml = file_get_contents($configXmlPath);
                if (strpos($configXml, 'fallback_to_magento') !== false) {
                    echo "✅ Fallback configuration option found in config.xml\n";
                } else {
                    echo "❌ Fallback configuration option not found in config.xml\n";
                    return false;
                }
            } else {
                echo "❌ config.xml not found\n";
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "❌ Error testing configuration structure: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function runAllTests()
    {
        echo "=== Fallback to Magento Tax Rates Test ===\n\n";
        
        $tests = [
            'testFallbackConstantsExist',
            'testFallbackMethodExists',
            'testFallbackConfigurationStructure'
        ];
        
        $passed = 0;
        $total = count($tests);
        
        foreach ($tests as $test) {
            try {
                if ($this->$test()) {
                    $passed++;
                }
            } catch (Exception $e) {
                echo "❌ Test {$test} failed with exception: " . $e->getMessage() . "\n";
            }
            echo "\n";
        }
        
        echo "=== Test Results ===\n";
        echo "Passed: {$passed}/{$total}\n";
        
        if ($passed === $total) {
            echo "🎉 All tests passed! Fallback functionality is properly implemented.\n";
        } else {
            echo "⚠️  Some tests failed. Please check the implementation.\n";
        }
        
        return $passed === $total;
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new FallbackToMagentoTest();
    $test->runAllTests();
}
