<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\PostalCodeParser;

/**
 * Section D: direct PostalCodeParser coverage. The parser is exercised indirectly
 * via Api but never hit head-on — these tests pin both methods.
 */
class PostalCodeParserTest extends TestCase
{
    /**
     * @dataProvider parseProvider
     */
    public function testParse(?string $input, array $expected, string $message)
    {
        $this->assertSame($expected, PostalCodeParser::parse($input), $message);
    }

    public function parseProvider(): array
    {
        return [
            'five-digit' => ['10001', ['Zip5' => '10001', 'Zip4' => null], 'plain Zip5'],
            'hyphenated zip+4' => ['10001-1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'hyphenated Zip5+Zip4'],
            'plus-sign zip+4' => ['10001+1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'plus-sign Zip5+Zip4'],
            'spaced zip+4' => ['10001 1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'space-separated Zip5+Zip4'],
            'with extra punctuation' => ['(100)01-1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'punctuation stripped before parsing'],
            'null input' => [null, ['Zip5' => null, 'Zip4' => null], 'null input → null fields'],
            'empty string' => ['', ['Zip5' => null, 'Zip4' => null], 'empty string → null fields'],
        ];
    }

    /**
     * @dataProvider isValidProvider
     */
    public function testIsValid(array $parsed, bool $expected, string $message)
    {
        $this->assertSame($expected, PostalCodeParser::isValid($parsed), $message);
    }

    public function isValidProvider(): array
    {
        return [
            'valid five-digit with null Zip4' => [['Zip5' => '10001', 'Zip4' => null], true, 'plain Zip5 valid'],
            'valid Zip5 + Zip4' => [['Zip5' => '10001', 'Zip4' => '1234'], true, 'Zip5+Zip4 valid'],
            'Zip5 too short' => [['Zip5' => '1001', 'Zip4' => null], false, 'four-digit Zip5 invalid'],
            'Zip5 with letters' => [['Zip5' => 'ABCDE', 'Zip4' => null], false, 'non-digit Zip5 invalid'],
            'Zip4 too short' => [['Zip5' => '10001', 'Zip4' => '12'], false, 'two-digit Zip4 invalid'],
            'Zip4 with letters' => [['Zip5' => '10001', 'Zip4' => 'ABCD'], false, 'non-digit Zip4 invalid'],
            'missing Zip5 key' => [['Zip4' => '1234'], false, 'missing Zip5 key invalid'],
            'null Zip5' => [['Zip5' => null, 'Zip4' => null], false, 'null Zip5 invalid'],
        ];
    }
}
