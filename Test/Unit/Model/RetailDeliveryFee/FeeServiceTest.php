<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\RetailDeliveryFee;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;
use Taxcloud\Magento2\Test\Unit\Double\ProductDouble;
use Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble;
use Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble;
use Taxcloud\Magento2\Test\Unit\Double\RegionDouble;

/**
 * Eligibility and amount rules for the Colorado Retail Delivery Fee.
 *
 * Each gate is exercised individually against an otherwise fully eligible
 * address, because production regressions here are silent: an order that
 * should carry the fee simply doesn't (or vice versa), with no error anywhere.
 */
#[AllowMockObjectsWithoutExpectations]
class FeeServiceTest extends TestCase
{
    private const STORE_ID = 7;
    private const METHODS = ['flatrate_flatrate', 'ups_ground'];

    /** @var TaxcloudConfig|\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var \Magento\Directory\Model\RegionFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $regionFactory;

    /** @var FeeService */
    private $service;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('isCoRdfEnabled')->willReturn(true);
        $this->config->method('getCoRdfDeliveryMethods')->willReturn(self::METHODS);
        $this->config->method('getCoRdfAmount')->willReturn(0.31);
        $this->config->method('getCoRdfTic')->willReturn('11098');

        $this->regionFactory = $this->createMock(\Magento\Directory\Model\RegionFactory::class);

        $this->service = new FeeService($this->config, $this->regionFactory);
    }

    public function testEligibleAddressIsEligible()
    {
        $address = $this->address('US', 'CO', 'flatrate_flatrate', [$this->item(false, '2')]);

        $this->assertTrue($this->service->isEligible($address, self::STORE_ID));
    }

    public function testDisabledFeatureIsNeverEligible()
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->expects($this->once())
            ->method('isCoRdfEnabled')
            ->with(self::STORE_ID)
            ->willReturn(false);
        $service = new FeeService($config, $this->regionFactory);

        $address = $this->address('US', 'CO', 'flatrate_flatrate', [$this->item(false, '2')]);

        $this->assertFalse($service->isEligible($address, self::STORE_ID));
    }

    /**
     * @dataProvider destinationProvider
     */
    #[DataProvider('destinationProvider')]
    public function testDestinationGate(string $country, ?string $regionCode, bool $expected, string $message)
    {
        $address = $this->address($country, $regionCode, 'flatrate_flatrate', [$this->item(false, '2')]);

        $this->assertSame($expected, $this->service->isEligible($address, self::STORE_ID), $message);
    }

    public static function destinationProvider(): array
    {
        return [
            'colorado' => ['US', 'CO', true, 'US-CO is eligible'],
            'lowercase code' => ['US', 'co', true, 'region code comparison is case-insensitive'],
            'other state' => ['US', 'TX', false, 'non-Colorado destination is not charged'],
            'non-us' => ['CA', 'CO', false, 'a non-US "CO" region is not Colorado'],
            'no region' => ['US', null, false, 'unresolvable region is not charged'],
        ];
    }

    public function testRegionResolvedByIdWhenCodeMissing()
    {
        $region = $this->getMockBuilder(RegionDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getCode'])
            ->getMock();
        $region->method('load')->willReturnSelf();
        $region->method('getCode')->willReturn('CO');
        $this->regionFactory->method('create')->willReturn($region);

        $address = $this->address('US', null, 'flatrate_flatrate', [$this->item(false, '2')]);
        $address->method('getRegionId')->willReturn(13);

        $this->assertTrue($this->service->isEligible($address, self::STORE_ID));
    }

    /**
     * @dataProvider shippingMethodProvider
     */
    #[DataProvider('shippingMethodProvider')]
    public function testShippingMethodGate(?string $method, bool $expected, string $message)
    {
        $address = $this->address('US', 'CO', $method, [$this->item(false, '2')]);

        $this->assertSame($expected, $this->service->isEligible($address, self::STORE_ID), $message);
    }

    public static function shippingMethodProvider(): array
    {
        return [
            'mapped method' => ['ups_ground', true, 'a mapped method triggers the fee'],
            'unmapped method' => ['storepickup_storepickup', false, 'an unmapped method never triggers the fee'],
            'no method yet' => [null, false, 'no selected method means no fee'],
        ];
    }

    /**
     * @dataProvider itemsProvider
     */
    #[DataProvider('itemsProvider')]
    public function testTaxableTangibleItemGate(array $itemSpecs, bool $expected, string $message)
    {
        $items = [];
        foreach ($itemSpecs as $spec) {
            $items[] = $this->item($spec[0], $spec[1]);
        }
        $address = $this->address('US', 'CO', 'flatrate_flatrate', $items);

        $this->assertSame($expected, $this->service->isEligible($address, self::STORE_ID), $message);
    }

    public static function itemsProvider(): array
    {
        // [isVirtual, taxClassId]
        return [
            'taxable tangible' => [[[false, '2']], true, 'one taxable tangible item suffices'],
            'only virtual' => [[[true, '2']], false, 'virtual goods are not tangible property'],
            'only non-taxable' => [[[false, '0']], false, 'tax-class None items are not taxable property'],
            'mixed' => [[[true, '2'], [false, '0'], [false, '2']], true, 'one qualifying item among others suffices'],
            'empty' => [[], false, 'an empty address is never charged'],
        ];
    }

    public function testConfigGatesReceiveTheGivenStore()
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->expects($this->once())->method('isCoRdfEnabled')->with(self::STORE_ID)->willReturn(true);
        $config->expects($this->once())->method('getCoRdfDeliveryMethods')->with(self::STORE_ID)
            ->willReturn(self::METHODS);
        $service = new FeeService($config, $this->regionFactory);

        $address = $this->address('US', 'CO', 'flatrate_flatrate', [$this->item(false, '2')]);
        $service->isEligible($address, self::STORE_ID);
    }

    public function testAmountAndTicDelegateToConfigWithStore()
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->expects($this->once())->method('getCoRdfAmount')->with(self::STORE_ID)->willReturn(0.31);
        $config->expects($this->once())->method('getCoRdfTic')->with(self::STORE_ID)->willReturn('11098');
        $service = new FeeService($config, $this->regionFactory);

        $this->assertSame(0.31, $service->getAmount(self::STORE_ID));
        $this->assertSame('11098', $service->getTic(self::STORE_ID));
    }

    /**
     * @param string $country
     * @param string|null $regionCode
     * @param string|null $shippingMethod
     * @param array $items
     * @return QuoteAddressDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function address(string $country, ?string $regionCode, ?string $shippingMethod, array $items)
    {
        $address = $this->getMockBuilder(QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryId', 'getRegionCode', 'getRegionId', 'getShippingMethod', 'getAllItems'])
            ->getMock();
        $address->method('getCountryId')->willReturn($country);
        $address->method('getRegionCode')->willReturn($regionCode);
        $address->method('getShippingMethod')->willReturn($shippingMethod);
        $address->method('getAllItems')->willReturn($items);

        return $address;
    }

    /**
     * @param bool $isVirtual
     * @param string $taxClassId
     * @return QuoteItemDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function item(bool $isVirtual, string $taxClassId)
    {
        $product = $this->getMockBuilder(ProductDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn($taxClassId);

        $item = $this->getMockBuilder(QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsVirtual', 'getProduct'])
            ->getMock();
        $item->method('getIsVirtual')->willReturn($isVirtual);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}
