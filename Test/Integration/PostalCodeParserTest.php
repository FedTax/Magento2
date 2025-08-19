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

// Include the PostalCodeParser class directly
require_once __DIR__ . '/../../Model/PostalCodeParser.php';

use Taxcloud\Magento2\Model\PostalCodeParser;

/**
 * Test that validates the postal code parsing functionality
 * This test should PASS when the fix is applied
 */
class PostalCodeParserTest
{
    /**
     * Test parsing various ZIP code formats
     */
    public static function testParsing()
    {
        echo "=== Testing Postal Code Parsing ===\n\n";
        
        // Test cases: [input, expected_zip5, expected_zip4, description]
        $testCases = [
            ['55057', '55057', null, 'Basic 5-digit ZIP'],
            ['55057-1616', '55057', '1616', 'ZIP+4 with hyphen'],
            ['55057+1616', '55057', '1616', 'ZIP+4 with plus sign'],
            ['55057 1616', '55057', '1616', 'ZIP+4 with space'],
            ['55057.1616', '55057', '1616', 'ZIP+4 with dot'],
            ['55057_1616', '55057', '1616', 'ZIP+4 with underscore'],
            ['55057-1616-extra', '55057', '1616', 'ZIP+4 with extra text'],
            ['55057+1616+extra', '55057', '1616', 'ZIP+4 with plus and extra'],
            ['', null, null, 'Empty string'],
            [null, null, null, 'Null input'],
            ['12345', '12345', null, 'Another 5-digit ZIP'],
            ['123456789', '12345', '6789', '9 consecutive digits'],
            ['1234567890', '12345', '6789', '10 digits (truncated)'],
        ];

        $passed = 0;
        $failed = 0;

        foreach ($testCases as $testCase) {
            [$input, $expectedZip5, $expectedZip4, $description] = $testCase;
            
            $result = PostalCodeParser::parse($input);
            
            $zip5Match = $result['Zip5'] === $expectedZip5;
            $zip4Match = $result['Zip4'] === $expectedZip4;
            
            if ($zip5Match && $zip4Match) {
                echo "✅ PASS: $description\n";
                echo "   Input: '$input' → Zip5: '{$result['Zip5']}', Zip4: '{$result['Zip4']}'\n";
                $passed++;
            } else {
                echo "❌ FAIL: $description\n";
                echo "   Input: '$input'\n";
                echo "   Expected: Zip5='$expectedZip5', Zip4='$expectedZip4'\n";
                echo "   Got: Zip5='{$result['Zip5']}', Zip4='{$result['Zip4']}'\n";
                $failed++;
            }
            echo "\n";
        }

        echo "Parsing Results: $passed passed, $failed failed\n\n";
        return $failed === 0;
    }

    /**
     * Test validation of parsed ZIP codes
     */
    public static function testValidation()
    {
        echo "=== Testing ZIP Code Validation ===\n\n";
        
        // Valid cases
        $validTests = [
            [['Zip5' => '55057', 'Zip4' => null], 'Valid 5-digit ZIP'],
            [['Zip5' => '55057', 'Zip4' => '1616'], 'Valid ZIP+4'],
        ];

        // Invalid cases
        $invalidTests = [
            [['Zip5' => '5505', 'Zip4' => null], 'Too short'],
            [['Zip5' => '550570', 'Zip4' => null], 'Too long'],
            [['Zip5' => '5505a', 'Zip4' => null], 'Non-numeric'],
            [['Zip5' => '55057', 'Zip4' => '161'], 'Zip4 too short'],
            [['Zip5' => '55057', 'Zip4' => '16160'], 'Zip4 too long'],
            [['Zip5' => '55057', 'Zip4' => '161a'], 'Zip4 non-numeric'],
            [['Zip5' => null, 'Zip4' => null], 'Missing Zip5'],
            [[], 'Empty array'],
        ];

        $passed = 0;
        $failed = 0;

        // Test valid cases
        foreach ($validTests as $testCase) {
            [$input, $description] = $testCase;
            $result = PostalCodeParser::isValid($input);
            
            if ($result === true) {
                echo "✅ PASS: $description (should be valid)\n";
                $passed++;
            } else {
                echo "❌ FAIL: $description (should be valid but was rejected)\n";
                $failed++;
            }
        }

        // Test invalid cases
        foreach ($invalidTests as $testCase) {
            [$input, $description] = $testCase;
            $result = PostalCodeParser::isValid($input);
            
            if ($result === false) {
                echo "✅ PASS: $description (correctly rejected)\n";
                $passed++;
            } else {
                echo "❌ FAIL: $description (should be invalid but was accepted)\n";
                $failed++;
            }
        }

        echo "\nValidation Results: $passed passed, $failed failed\n\n";
        return $failed === 0;
    }

    /**
     * Test the specific failure case that was reported
     */
    public static function testFailureCase()
    {
        echo "=== Testing Original Failure Case ===\n\n";
        
        // This is the exact case that was failing: 55057+1616
        $problematicInput = '55057+1616';
        
        echo "Original problematic input: '$problematicInput'\n";
        
        // Test with old parsing method (simulate the bug)
        $oldResult = self::oldParseMethod($problematicInput);
        echo "Old parsing method result: Zip5='{$oldResult['Zip5']}', Zip4='{$oldResult['Zip4']}'\n";
        
        // Test with new parsing method
        $newResult = PostalCodeParser::parse($problematicInput);
        echo "New parsing method result: Zip5='{$newResult['Zip5']}', Zip4='{$newResult['Zip4']}'\n";
        
        // Check if the fix works
        $oldIsValid = PostalCodeParser::isValid($oldResult);
        $newIsValid = PostalCodeParser::isValid($newResult);
        
        echo "Old result valid for TaxCloud API: " . ($oldIsValid ? 'YES' : 'NO') . "\n";
        echo "New result valid for TaxCloud API: " . ($newIsValid ? 'YES' : 'NO') . "\n";
        
        if (!$oldIsValid && $newIsValid) {
            echo "✅ SUCCESS: The fix resolves the original failure case!\n";
            return true;
        } else {
            echo "❌ FAILURE: The fix does not resolve the original failure case.\n";
            return false;
        }
    }

    /**
     * Simulate the old parsing method that was causing the bug
     */
    private static function oldParseMethod($postcode)
    {
        if (empty($postcode)) {
            return ['Zip5' => null, 'Zip4' => null];
        }

        // This is the old method that only split on hyphen
        $parts = explode('-', $postcode);
        
        return [
            'Zip5' => $parts[0] ?? null,
            'Zip4' => $parts[1] ?? null
        ];
    }

    /**
     * Run all tests
     */
    public static function run()
    {
        $parsingTest = self::testParsing();
        $validationTest = self::testValidation();
        $failureTest = self::testFailureCase();
        
        echo "=== Final Test Results ===\n";
        echo "Parsing tests: " . ($parsingTest ? "PASSED" : "FAILED") . "\n";
        echo "Validation tests: " . ($validationTest ? "PASSED" : "FAILED") . "\n";
        echo "Failure case test: " . ($failureTest ? "PASSED" : "FAILED") . "\n";
        
        if ($parsingTest && $validationTest && $failureTest) {
            echo "\n🎉 All tests passed! The postal code parser is working correctly.\n";
            return true;
        } else {
            echo "\n❌ Some tests failed. Please review the implementation.\n";
            return false;
        }
    }
}

// Run tests if this file is executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    PostalCodeParserTest::run();
}
