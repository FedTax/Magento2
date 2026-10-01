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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\PostalCodeParser;

/**
 * Section D: direct PostalCodeParser coverage. The parser is exercised indirectly
 * via Api but never hit head-on — these tests pin both methods.
 */
#[AllowMockObjectsWithoutExpectations]
class PostalCodeParserTest extends TestCase
{
    /**
     * @dataProvider parseProvider
     */
    #[DataProvider('parseProvider')]
    public function testParse(?string $input, array $expected, string $message)
    {
        $this->assertSame($expected, PostalCodeParser::parse($input), $message);
    }

    public static function parseProvider(): array
    {
        return [
            'five-digit' => ['10001', ['Zip5' => '10001', 'Zip4' => null], 'plain Zip5'],
            'hyphenated zip+4' => ['10001-1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'hyphenated Zip5+Zip4'],
            'plus-sign zip+4' => ['10001+1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'plus-sign Zip5+Zip4'],
            'spaced zip+4' => ['10001 1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'space-separated Zip5+Zip4'],
            'with extra punctuation' => ['(100)01-1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'punctuation stripped before parsing'],
            'dot separator' => ['10001.1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'dot separator stripped'],
            'underscore separator' => ['10001_1234', ['Zip5' => '10001', 'Zip4' => '1234'], 'underscore separator stripped'],
            'trailing non-digit text' => ['10001-1234-extra', ['Zip5' => '10001', 'Zip4' => '1234'], 'trailing non-digit text ignored'],
            'nine consecutive digits' => ['100011234', ['Zip5' => '10001', 'Zip4' => '1234'], 'unseparated 9-digit run parses as Zip5+Zip4'],
            'ten consecutive digits truncated' => ['1000112345', ['Zip5' => '10001', 'Zip4' => '1234'], '10-digit run truncates trailing digit'],
            'null input' => [null, ['Zip5' => null, 'Zip4' => null], 'null input → null fields'],
            'empty string' => ['', ['Zip5' => null, 'Zip4' => null], 'empty string → null fields'],
        ];
    }

    /**
     * @dataProvider isValidProvider
     */
    #[DataProvider('isValidProvider')]
    public function testIsValid(array $parsed, bool $expected, string $message)
    {
        $this->assertSame($expected, PostalCodeParser::isValid($parsed), $message);
    }

    public static function isValidProvider(): array
    {
        return [
            'valid five-digit with null Zip4' => [['Zip5' => '10001', 'Zip4' => null], true, 'plain Zip5 valid'],
            'valid Zip5 + Zip4' => [['Zip5' => '10001', 'Zip4' => '1234'], true, 'Zip5+Zip4 valid'],
            'Zip5 too short' => [['Zip5' => '1001', 'Zip4' => null], false, 'four-digit Zip5 invalid'],
            'Zip5 too long' => [['Zip5' => '100011', 'Zip4' => null], false, 'six-digit Zip5 invalid'],
            'Zip5 with letters' => [['Zip5' => 'ABCDE', 'Zip4' => null], false, 'non-digit Zip5 invalid'],
            'Zip4 too short' => [['Zip5' => '10001', 'Zip4' => '12'], false, 'two-digit Zip4 invalid'],
            'Zip4 too long' => [['Zip5' => '10001', 'Zip4' => '12345'], false, 'five-digit Zip4 invalid'],
            'Zip4 with letters' => [['Zip5' => '10001', 'Zip4' => 'ABCD'], false, 'non-digit Zip4 invalid'],
            'missing Zip5 key' => [['Zip4' => '1234'], false, 'missing Zip5 key invalid'],
            'null Zip5' => [['Zip5' => null, 'Zip4' => null], false, 'null Zip5 invalid'],
        ];
    }

    /**
     * @dataProvider canadianProvider
     */
    #[DataProvider('canadianProvider')]
    public function testParseCanadian(?string $input, ?string $expected, string $message)
    {
        $this->assertSame($expected, PostalCodeParser::parseCanadian($input), $message);
    }

    public static function canadianProvider(): array
    {
        return [
            'canonical' => ['M5H 2N2', 'M5H 2N2', 'canonical form kept'],
            'lowercase, no space' => ['m5h2n2', 'M5H 2N2', 'normalized to uppercase with a space'],
            'surrounding whitespace' => ['  v5y 1v4 ', 'V5Y 1V4', 'stray whitespace trimmed'],
            'extra inner space' => ['H2Y  1C6', 'H2Y 1C6', 'inner whitespace collapsed'],
            'US ZIP' => ['12345', null, 'a US ZIP is not a Canadian postal code'],
            'too short' => ['M5H 2N', null, 'five characters invalid'],
            'too long' => ['M5H 2N22', null, 'seven characters invalid'],
            'forbidden letter' => ['D5H 2N2', null, 'D never appears'],
            'W never leads' => ['W5H 2N2', null, 'W is not a valid first letter'],
            'Z never leads' => ['Z5H 2N2', null, 'Z is not a valid first letter'],
            'hyphenated' => ['M5H-2N2', null, 'hyphen is not accepted'],
            'empty' => ['', null, 'empty invalid'],
            'null' => [null, null, 'null invalid'],
        ];
    }
}
