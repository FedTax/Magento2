<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\RetailDeliveryFee\Total;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;
use Taxcloud\Magento2\Model\RetailDeliveryFee\Total\Quote\RetailDeliveryFee;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * The quote total collector: the fee lands in its own total bucket (which the
 * grand-total collector sums), never in tax, and clears on ineligibility.
 */
#[AllowMockObjectsWithoutExpectations]
class QuoteRetailDeliveryFeeTest extends TestCase
{
    private const STORE_ID = 7;

    /** @var FeeService|\PHPUnit\Framework\MockObject\MockObject */
    private $feeService;

    /** @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $priceCurrency;

    /** @var RetailDeliveryFee */
    private $collector;

    protected function setUp(): void
    {
        $this->feeService = $this->createMock(FeeService::class);
        $this->priceCurrency = $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);
        // Identity conversion by default; the conversion test overrides.
        $this->priceCurrency->method('convert')->willReturnArgument(0);
        $this->priceCurrency->method('round')->willReturnCallback(
            static function ($v) {
                return round((float) $v, 2);
            }
        );

        $this->collector = new RetailDeliveryFee($this->feeService, $this->priceCurrency);
    }

    public function testEligibleQuoteIsChargedOnceIntoOwnBucket()
    {
        $this->feeService->method('isEligible')->willReturn(true);
        $this->feeService->method('getAmount')->with(self::STORE_ID)->willReturn(0.31);

        [$quote, $assignment, $address] = $this->scenario();
        $total = new Dbl\TotalDouble();

        $this->collector->collect($quote, $assignment, $total);

        $this->assertSame(0.31, (float) $total->getTotalAmount(RetailDeliveryFee::CODE));
        $this->assertSame(0.31, (float) $total->getBaseTotalAmount(RetailDeliveryFee::CODE));
        $this->assertSame(0.31, (float) $address->getTaxcloudRdfAmount());
        $this->assertSame(0.31, (float) $address->getBaseTaxcloudRdfAmount());
        // Not folded into the tax total.
        $this->assertSame(0.0, (float) $total->getTotalAmount('tax'));
    }

    public function testIneligibleQuoteClearsAPreviouslyChargedFee()
    {
        $this->feeService->method('isEligible')->willReturn(false);

        [$quote, $assignment, $address] = $this->scenario();
        $address->setTaxcloudRdfAmount(0.31);
        $address->setBaseTaxcloudRdfAmount(0.31);
        $total = new Dbl\TotalDouble();
        $total->setTotalAmount(RetailDeliveryFee::CODE, 0.31);
        $total->setBaseTotalAmount(RetailDeliveryFee::CODE, 0.31);

        $this->collector->collect($quote, $assignment, $total);

        $this->assertSame(0.0, (float) $total->getTotalAmount(RetailDeliveryFee::CODE));
        $this->assertSame(0.0, (float) $total->getBaseTotalAmount(RetailDeliveryFee::CODE));
        $this->assertSame(0.0, (float) $address->getTaxcloudRdfAmount());
        $this->assertSame(0.0, (float) $address->getBaseTaxcloudRdfAmount());
    }

    public function testEmptyAssignmentNeverConsultsEligibility()
    {
        $this->feeService->expects($this->never())->method('isEligible');

        [$quote, $assignment] = $this->scenario([]);
        $total = new Dbl\TotalDouble();

        $this->collector->collect($quote, $assignment, $total);

        $this->assertSame(0.0, (float) $total->getTotalAmount(RetailDeliveryFee::CODE));
    }

    public function testEligibilityAndAmountResolveAgainstTheQuoteStore()
    {
        $this->feeService->expects($this->once())
            ->method('isEligible')
            ->with($this->anything(), self::STORE_ID)
            ->willReturn(true);
        $this->feeService->expects($this->once())
            ->method('getAmount')
            ->with(self::STORE_ID)
            ->willReturn(0.31);

        [$quote, $assignment] = $this->scenario();
        $this->collector->collect($quote, $assignment, new Dbl\TotalDouble());
    }

    public function testDisplayAmountIsConvertedBaseStaysConfigured()
    {
        $this->feeService->method('isEligible')->willReturn(true);
        $this->feeService->method('getAmount')->willReturn(0.31);

        $priceCurrency = $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);
        $priceCurrency->method('convert')->willReturn(0.42);
        $priceCurrency->method('round')->willReturnArgument(0);
        $collector = new RetailDeliveryFee($this->feeService, $priceCurrency);

        [$quote, $assignment] = $this->scenario();
        $total = new Dbl\TotalDouble();

        $collector->collect($quote, $assignment, $total);

        $this->assertSame(0.42, (float) $total->getTotalAmount(RetailDeliveryFee::CODE));
        $this->assertSame(0.31, (float) $total->getBaseTotalAmount(RetailDeliveryFee::CODE));
    }

    public function testFetchExposesSegmentOnlyWhenCharged()
    {
        $quote = $this->quote();

        $charged = new Dbl\TotalDouble();
        $charged->setTotalAmount(RetailDeliveryFee::CODE, 0.31);
        $segment = $this->collector->fetch($quote, $charged);
        $this->assertSame(RetailDeliveryFee::CODE, $segment['code']);
        $this->assertSame('Colorado Retail Delivery Fee', (string) $segment['title']);
        $this->assertSame(0.31, (float) $segment['value']);

        $empty = new Dbl\TotalDouble();
        $this->assertNull($this->collector->fetch($quote, $empty));
    }

    public function testFetchReadsTheAddressDataWhenTheRegistryIsEmpty()
    {
        // TotalsReader (which builds checkout total_segments) hands fetch() a
        // Total hydrated from the address DATA, not the collect-time registry.
        $quote = $this->quote();
        $hydrated = new Dbl\TotalDouble();
        $hydrated->setData('taxcloud_rdf_amount', 0.31);

        $segment = $this->collector->fetch($quote, $hydrated);

        $this->assertNotNull($segment, 'the row must render from address data alone');
        $this->assertSame(0.31, (float) $segment['value']);
    }

    /**
     * @param array|null $items
     * @return array [quote, shippingAssignment, address]
     */
    private function scenario(?array $items = null)
    {
        $quote = $this->quote();

        $address = new Dbl\QuoteAddressDouble();

        $shipping = $this->getMockBuilder(Dbl\QuoteAddressDouble::class)
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $assignment = $this->createMock(\Magento\Quote\Api\Data\ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);
        $assignment->method('getItems')->willReturn($items ?? [new Dbl\QuoteItemDouble()]);

        return [$quote, $assignment, $address];
    }

    /**
     * @return Dbl\QuoteDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function quote()
    {
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $quote = $this->getMockBuilder(Dbl\QuoteDouble::class)
            ->onlyMethods(['getStoreId', 'getStore'])
            ->getMock();
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getStore')->willReturn($store);

        return $quote;
    }
}
