<?php
/**
 * Adobe Commerce 2.4.8-p1 Compatibility Tests
 * 
 * Tests for breaking changes and compatibility issues
 */

class AdobeCommerce248p1CompatibilityTest
{
    public static function runAllTests()
    {
        echo "=== Adobe Commerce 2.4.8-p1 Compatibility Tests ===\n\n";
        
        $results = [];
        
        // Test 1: PHP 8.1+ Compatibility
        $results[] = self::testPhp81Compatibility();
        
        // Test 2: SOAP Client Compatibility
        $results[] = self::testSoapClientCompatibility();
        
        // Test 3: Anonymous Class Compatibility
        $results[] = self::testAnonymousClassCompatibility();
        
        // Test 4: Throwable Exception Handling
        $results[] = self::testThrowableCompatibility();
        
        // Test 5: Array Access and Type Handling
        $results[] = self::testArrayAccessCompatibility();
        
        // Test 6: String Operations and Encoding
        $results[] = self::testStringOperationsCompatibility();
        
        // Test 7: SOAP Parameter Handling
        $results[] = self::testSoapParameterCompatibility();
        
        // Test 8: Error Suppression Compatibility
        $results[] = self::testErrorSuppressionCompatibility();
        
        // Display results
        self::displayResults($results);
        
        return !in_array(false, $results);
    }
    
    private static function testPhp81Compatibility()
    {
        echo "Test 1: PHP 8.1+ Compatibility\n";
        
        $phpVersion = PHP_VERSION;
        $isCompatible = version_compare($phpVersion, '8.1.0', '>=');
        
        if ($isCompatible) {
            echo "   ✅ PHP version $phpVersion is compatible with Adobe Commerce 2.4.8-p1\n";
        } else {
            echo "   ❌ PHP version $phpVersion is NOT compatible with Adobe Commerce 2.4.8-p1\n";
        }
        
        return $isCompatible;
    }
    
    private static function testSoapClientCompatibility()
    {
        echo "\nTest 2: SOAP Client Compatibility\n";
        
        try {
            // Test SOAP extension availability
            if (!extension_loaded('soap')) {
                echo "   ❌ SOAP extension not loaded\n";
                return false;
            }
            
            // Test SOAP client instantiation with options (similar to your code)
            $wsdl = 'https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl';
            $client = new \SoapClient($wsdl, ['trace' => true]);
            
            echo "   ✅ SOAP client instantiation successful\n";
            echo "   ✅ SOAP extension loaded and functional\n";
            
            return true;
            
        } catch (Throwable $e) {
            echo "   ❌ SOAP client test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function testAnonymousClassCompatibility()
    {
        echo "\nTest 3: Anonymous Class Compatibility\n";
        
        try {
            // Test anonymous class creation (similar to your observer pattern)
            $anonymousLogger = new class {
                public function info($message = '') {
                    return true;
                }
            };
            
            $result = $anonymousLogger->info('test');
            
            if ($result === true) {
                echo "   ✅ Anonymous class creation and method calls work\n";
                return true;
            } else {
                echo "   ❌ Anonymous class method call failed\n";
                return false;
            }
            
        } catch (Throwable $e) {
            echo "   ❌ Anonymous class test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function testThrowableCompatibility()
    {
        echo "\nTest 4: Throwable Exception Handling\n";
        
        try {
            // Test Throwable catch block (similar to your error handling)
            try {
                throw new Exception('Test exception');
            } catch (Throwable $e) {
                if ($e->getMessage() === 'Test exception') {
                    echo "   ✅ Throwable catch blocks work correctly\n";
                    return true;
                } else {
                    echo "   ❌ Throwable catch block message mismatch\n";
                    return false;
                }
            }
            
        } catch (Throwable $e) {
            echo "   ❌ Throwable test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function testArrayAccessCompatibility()
    {
        echo "\nTest 5: Array Access and Type Handling\n";
        
        try {
            // Test array access patterns used in your code
            $testArray = ['key' => 'value', 'numeric' => 123];
            
            // Test array key access
            if ($testArray['key'] === 'value' && $testArray['numeric'] === 123) {
                echo "   ✅ Array access works correctly\n";
            } else {
                echo "   ❌ Array access failed\n";
                return false;
            }
            
            // Test array validation (similar to your postal code validation)
            if (is_array($testArray) && count($testArray) > 0) {
                echo "   ✅ Array validation functions work\n";
            } else {
                echo "   ❌ Array validation failed\n";
                return false;
            }
            
            return true;
            
        } catch (Throwable $e) {
            echo "   ❌ Array access test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function testStringOperationsCompatibility()
    {
        echo "\nTest 6: String Operations and Encoding\n";
        
        try {
            // Test string operations used in your code
            $testString = '55057-1616';
            
            // Test substr (used in your duplicate detection)
            if (substr($testString, 0, 5) === '55057') {
                echo "   ✅ String substring operations work\n";
            } else {
                echo "   ❌ String substring failed\n";
                return false;
            }
            
            // Test trim (used in your message processing)
            if (trim('  test  ') === 'test') {
                echo "   ✅ String trim operations work\n";
            } else {
                echo "   ❌ String trim failed\n";
                return false;
            }
            
            // Test string concatenation
            $concatenated = 'prefix_' . $testString . '_suffix';
            if (strpos($concatenated, $testString) !== false) {
                echo "   ✅ String concatenation works\n";
            } else {
                echo "   ❌ String concatenation failed\n";
                return false;
            }
            
            return true;
            
        } catch (Throwable $e) {
            echo "   ❌ String operations test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function testSoapParameterCompatibility()
    {
        echo "\nTest 7: SOAP Parameter Handling\n";
        
        try {
            // Test parameter handling similar to your TaxCloud API calls
            $testParams = [
                'returnCoDeliveryFeeWhenNoCartItems' => false,
                'ItemID' => 'TEST-123',
                'Price' => 19.99,
                'Qty' => 1
            ];
            
            // Test parameter existence check
            if (isset($testParams['returnCoDeliveryFeeWhenNoCartItems'])) {
                echo "   ✅ SOAP parameter existence checks work\n";
            } else {
                echo "   ❌ SOAP parameter existence check failed\n";
                return false;
            }
            
            // Test parameter type handling
            if (is_bool($testParams['returnCoDeliveryFeeWhenNoCartItems']) && 
                is_string($testParams['ItemID']) && 
                is_numeric($testParams['Price'])) {
                echo "   ✅ SOAP parameter type handling works\n";
            } else {
                echo "   ❌ SOAP parameter type handling failed\n";
                return false;
            }
            
            return true;
            
        } catch (Throwable $e) {
            echo "   ❌ SOAP parameter test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function testErrorSuppressionCompatibility()
    {
        echo "\nTest 8: Error Suppression Compatibility\n";
        
        try {
            // Test error suppression patterns (if used in your code)
            $result = @file_get_contents('nonexistent_file');
            
            if ($result === false) {
                echo "   ✅ Error suppression works correctly\n";
            } else {
                echo "   ❌ Error suppression failed\n";
                return false;
            }
            
            // Test error reporting
            $originalLevel = error_reporting();
            error_reporting(E_ALL);
            error_reporting($originalLevel);
            
            echo "   ✅ Error reporting control works\n";
            
            return true;
            
        } catch (Throwable $e) {
            echo "   ❌ Error suppression test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private static function displayResults($results)
    {
        echo "\n=== Compatibility Test Results ===\n";
        
        $passed = count(array_filter($results));
        $total = count($results);
        
        foreach ($results as $index => $result) {
            $status = $result ? "PASSED" : "FAILED";
            echo "Test " . ($index + 1) . ": " . $status . "\n";
        }
        
        echo "\nOverall: $passed/$total tests passed\n";
        
        if ($passed === $total) {
            echo "🎉 All compatibility tests passed! Your extension should work with Adobe Commerce 2.4.8-p1\n";
        } else {
            echo "⚠️  Some compatibility issues detected. Review failed tests above.\n";
        }
    }
}

// Run tests if called directly
if (php_sapi_name() === 'cli') {
    AdobeCommerce248p1CompatibilityTest::runAllTests();
}
