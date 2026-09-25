<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Address;

use Magento\Framework\DataObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Address\EstimateAddress;

/**
 * Pins what counts as an estimate address and exactly which destination
 * fields the placeholder may replace.
 */
class EstimateAddressTest extends TestCase
{
    /**
     * @dataProvider isPartialProvider
     */
    #[DataProvider('isPartialProvider')]
    public function testIsPartial($street, $city, bool $expected)
    {
        $address = new DataObject(['street' => $street, 'city' => $city]);

        $this->assertSame($expected, EstimateAddress::isPartial($address));
    }

    public static function isPartialProvider(): array
    {
        return [
            'complete' => [['1 Main St'], 'Denver', false],
            'complete, string street' => ['1 Main St', 'Denver', false],
            'no street, no city (cart estimator)' => [null, null, true],
            'city only' => [null, 'Denver', true],
            'street only' => [['1 Main St'], null, true],
            'empty street array' => [[], 'Denver', true],
            'whitespace street' => [['   '], 'Denver', true],
            'whitespace city' => [['1 Main St'], '  ', true],
            'second line only' => [['', 'Suite 5'], 'Denver', true],
        ];
    }

    public function testFillReplacesBothMissingFieldsAndNothingElse()
    {
        $destination = [
            'Address1' => '',
            'Address2' => '',
            'City' => null,
            'State' => 'CO',
            'Zip5' => '80020',
            'Zip4' => null,
        ];

        $this->assertSame([
            'Address1' => EstimateAddress::PLACEHOLDER,
            'Address2' => '',
            'City' => EstimateAddress::PLACEHOLDER,
            'State' => 'CO',
            'Zip5' => '80020',
            'Zip4' => null,
        ], EstimateAddress::fill($destination));
    }

    public function testFillKeepsAPresentStreet()
    {
        $filled = EstimateAddress::fill(['Address1' => '1 Main St', 'City' => '', 'State' => 'CO', 'Zip5' => '80020']);

        $this->assertSame('1 Main St', $filled['Address1']);
        $this->assertSame(EstimateAddress::PLACEHOLDER, $filled['City']);
    }

    public function testFillKeepsAPresentCity()
    {
        $filled = EstimateAddress::fill(['Address1' => ' ', 'City' => 'Denver', 'State' => 'CO', 'Zip5' => '80020']);

        $this->assertSame(EstimateAddress::PLACEHOLDER, $filled['Address1']);
        $this->assertSame('Denver', $filled['City']);
    }

    public function testFillLeavesCanadianKeysUntouched()
    {
        $filled = EstimateAddress::fill([
            'Address1' => '',
            'Address2' => '',
            'City' => '',
            'State' => 'ON',
            'Zip5' => '',
            'Zip4' => '',
            'Country' => 'CA',
            'PostalCode' => 'M5V 3L9',
        ]);

        $this->assertSame('ON', $filled['State']);
        $this->assertSame('', $filled['Zip5']);
        $this->assertSame('CA', $filled['Country']);
        $this->assertSame('M5V 3L9', $filled['PostalCode']);
    }

    /**
     * @dataProvider isEstimateProvider
     */
    #[DataProvider('isEstimateProvider')]
    public function testIsEstimate(array $destination, bool $expected)
    {
        $this->assertSame($expected, EstimateAddress::isEstimate($destination));
    }

    public static function isEstimateProvider(): array
    {
        $p = EstimateAddress::PLACEHOLDER;

        return [
            'v1 both placeholders' => [['Address1' => $p, 'City' => $p, 'State' => 'CO'], true],
            'v1 city placeholder' => [['Address1' => '1 Main St', 'City' => $p], true],
            'v1 street placeholder' => [['Address1' => $p, 'City' => 'Denver'], true],
            'v1 real address' => [['Address1' => '1 Main St', 'City' => 'Denver'], false],
            'v3 both placeholders' => [['line1' => $p, 'city' => $p, 'state' => 'CO'], true],
            'v3 city placeholder' => [['line1' => '1 Main St', 'city' => $p], true],
            'v3 real address' => [['line1' => '1 Main St', 'city' => 'Denver'], false],
            'empty array' => [[], false],
        ];
    }
}
