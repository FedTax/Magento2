<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Fallback;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Tax\Api\Data\QuoteDetailsInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsInterface;
use Magento\Tax\Api\Data\TaxDetailsItemInterface;
use Magento\Tax\Api\TaxCalculationInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback;
use Taxcloud\Magento2\Test\Unit\Double\ItemDetailsDouble;
use Taxcloud\Magento2\Test\Unit\Double\ProductDouble;
use Taxcloud\Magento2\Test\Unit\Double\QuoteAddressDouble;
use Taxcloud\Magento2\Test\Unit\Double\QuoteItemDouble;

/**
 * Covers the Magento-native fallback extracted from Model\Api: the no-address
 * short-circuit, the fail-soft catch, and the happy path that maps Magento's
 * TaxDetails back into the gateway's product/shipping result shape.
 */
#[AllowMockObjectsWithoutExpectations]
class MagentoTaxFallbackTest extends TestCase
{
    private $customerAddressFactory;
    private $quoteDetailsFactory;
    private $quoteDetailsItemFactory;
    private $taxClassKeyFactory;
    private $taxCalculationService;

    protected function setUp(): void
    {
        $this->customerAddressFactory = $this->createMock(AddressInterfaceFactory::class);
        $this->quoteDetailsFactory = $this->createMock(QuoteDetailsInterfaceFactory::class);
        $this->quoteDetailsItemFactory = $this->createMock(QuoteDetailsItemInterfaceFactory::class);
        $this->taxClassKeyFactory = $this->createMock(TaxClassKeyInterfaceFactory::class);
        $this->taxCalculationService = $this->createMock(TaxCalculationInterface::class);
    }

    private function fallback(): MagentoTaxFallback
    {
        return new MagentoTaxFallback(
            $this->customerAddressFactory,
            $this->quoteDetailsFactory,
            $this->quoteDetailsItemFactory,
            $this->taxClassKeyFactory,
            $this->taxCalculationService,
            new NullLogger()
        );
    }

    private function shippingAssignmentWithAddress($address, array $items = []): ShippingAssignmentInterface
    {
        $shipping = $this->getMockBuilder(QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAddress'])
            ->getMock();
        $shipping->method('getAddress')->willReturn($address);

        $assignment = $this->createMock(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);
        $assignment->method('getItems')->willReturn($items);
        return $assignment;
    }

    private function quoteAddress(): QuoteAddressDouble
    {
        $address = $this->getMockBuilder(QuoteAddressDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryId', 'getRegionId', 'getPostcode', 'getCity', 'getStreet'])
            ->getMock();
        $address->method('getCountryId')->willReturn('US');
        $address->method('getRegionId')->willReturn(12);
        $address->method('getPostcode')->willReturn('30097');
        $address->method('getCity')->willReturn('Duluth');
        $address->method('getStreet')->willReturn(['405 Victorian Ln']);
        return $address;
    }

    public function testReturnsZeroedResultWhenNoAddress()
    {
        $assignment = $this->shippingAssignmentWithAddress(null);
        $quote = $this->createMock(Quote::class);

        $result = $this->fallback()->calculate([], $assignment, $quote);

        $this->assertSame(['product' => [], 'shipping' => 0], $result);
    }

    public function testReturnsZeroedResultWhenCalculationThrows()
    {
        $this->customerAddressFactory->method('create')->willReturn($this->createMock(AddressInterface::class));
        $this->quoteDetailsFactory->method('create')->willReturn($this->createMock(QuoteDetailsInterface::class));
        $this->taxCalculationService->method('calculateTax')
            ->willThrowException(new \RuntimeException('tax engine down'));

        $assignment = $this->shippingAssignmentWithAddress($this->quoteAddress(), []);
        $quote = $this->createMock(Quote::class);

        $result = $this->fallback()->calculate([], $assignment, $quote);

        $this->assertSame(['product' => [], 'shipping' => 0], $result);
    }

    public function testMapsMagentoTaxDetailsToProductAndShippingResult()
    {
        $this->customerAddressFactory->method('create')->willReturn($this->createMock(AddressInterface::class));
        $this->quoteDetailsFactory->method('create')->willReturn($this->createMock(QuoteDetailsInterface::class));
        $this->quoteDetailsItemFactory->method('create')
            ->willReturnCallback(fn () => $this->createMock(QuoteDetailsItemInterface::class));
        $this->taxClassKeyFactory->method('create')
            ->willReturnCallback(fn () => $this->createMock(TaxClassKeyInterface::class));

        // One product quote item, keyed by its tax-calculation id 'p1'.
        $product = $this->getMockBuilder(ProductDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getTaxClassId'])->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $quoteItem = $this->getMockBuilder(QuoteItemDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxCalculationItemId', 'getProduct', 'getPrice', 'getQty', 'getDiscountAmount'])
            ->getMock();
        $quoteItem->method('getTaxCalculationItemId')->willReturn('p1');
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getPrice')->willReturn(10.0);
        $quoteItem->method('getQty')->willReturn(1);
        $quoteItem->method('getDiscountAmount')->willReturn(0);

        $shipRowItem = $this->getMockBuilder(ItemDetailsDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getRowTotal'])->getMock();
        $shipRowItem->method('getRowTotal')->willReturn(5.0);

        $itemsByType = [
            'product' => ['p1' => ['item' => 'ignored']],
            'shipping' => ['shipping' => ['item' => $shipRowItem]],
        ];

        // Magento returns per-item tax details we map back.
        $productTax = $this->createMock(TaxDetailsItemInterface::class);
        $productTax->method('getCode')->willReturn('p1');
        $productTax->method('getRowTax')->willReturn(0.83);
        $productTax->method('getType')->willReturn('product');

        $shippingTax = $this->createMock(TaxDetailsItemInterface::class);
        $shippingTax->method('getCode')->willReturn('shipping');
        $shippingTax->method('getRowTax')->willReturn(0.41);
        $shippingTax->method('getType')->willReturn('shipping');

        $taxDetails = $this->createMock(TaxDetailsInterface::class);
        $taxDetails->method('getItems')->willReturn([$productTax, $shippingTax]);
        $this->taxCalculationService->method('calculateTax')->willReturn($taxDetails);

        $assignment = $this->shippingAssignmentWithAddress($this->quoteAddress(), [$quoteItem]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);

        $result = $this->fallback()->calculate($itemsByType, $assignment, $quote);

        $this->assertSame(0.83, $result['product']['p1']);
        $this->assertSame(0.41, $result['shipping']);
    }
}
