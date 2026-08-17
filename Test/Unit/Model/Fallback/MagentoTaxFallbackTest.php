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

    /**
     * A product line with no matching address item used to fatal on a null item
     * and drop the whole quote — including every priceable line — to 0.00. The
     * unmatched line is skipped instead.
     */
    public function testSkipsProductLineWithNoMatchingAddressItemInsteadOfZeroingTheQuote()
    {
        $this->customerAddressFactory->method('create')->willReturn($this->createMock(AddressInterface::class));
        $this->quoteDetailsFactory->method('create')->willReturn($this->createMock(QuoteDetailsInterface::class));
        $this->quoteDetailsItemFactory->method('create')
            ->willReturnCallback(fn () => $this->createMock(QuoteDetailsItemInterface::class));
        $this->taxClassKeyFactory->method('create')
            ->willReturnCallback(fn () => $this->createMock(TaxClassKeyInterface::class));

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

        // 'orphan' has no address item behind it.
        $itemsByType = [
            'product' => ['p1' => ['item' => 'ignored'], 'orphan' => ['item' => 'ignored']],
        ];

        $productTax = $this->createMock(TaxDetailsItemInterface::class);
        $productTax->method('getCode')->willReturn('p1');
        $productTax->method('getRowTax')->willReturn(0.83);
        $productTax->method('getType')->willReturn('product');

        $taxDetails = $this->createMock(TaxDetailsInterface::class);
        $taxDetails->method('getItems')->willReturn([$productTax]);
        $this->taxCalculationService->method('calculateTax')->willReturn($taxDetails);

        $assignment = $this->shippingAssignmentWithAddress($this->quoteAddress(), [$quoteItem]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);

        $result = $this->fallback()->calculate($itemsByType, $assignment, $quote);

        $this->assertSame(0.83, $result['product']['p1'], 'the priceable line still gets its tax');
        $this->assertArrayNotHasKey('orphan', $result['product']);
    }

    /**
     * The fallback builds a flat detail list with no parent codes, so Magento's
     * own TaxCalculation cannot apply its parent-quantity rule for us: a bundle
     * child has to arrive already parent-multiplied, and the wrapper — whose
     * price is just the sum of those children — must not arrive at all.
     */
    public function testDynamicBundleIsPricedByItsChildrenAtTheParentMultipliedQty()
    {
        $this->customerAddressFactory->method('create')->willReturn($this->createMock(AddressInterface::class));
        $this->quoteDetailsFactory->method('create')->willReturn($this->createMock(QuoteDetailsInterface::class));
        $this->taxClassKeyFactory->method('create')
            ->willReturnCallback(fn () => $this->createMock(TaxClassKeyInterface::class));

        $mapped = [];
        $this->quoteDetailsItemFactory->method('create')->willReturnCallback(
            function () use (&$mapped) {
                $detail = $this->createMock(QuoteDetailsItemInterface::class);
                $at = count($mapped);
                $mapped[$at] = ['code' => null, 'qty' => null];
                $detail->method('setCode')->willReturnCallback(function ($code) use (&$mapped, $at) {
                    $mapped[$at]['code'] = $code;
                });
                $detail->method('setQuantity')->willReturnCallback(function ($qty) use (&$mapped, $at) {
                    $mapped[$at]['qty'] = $qty;
                });

                return $detail;
            }
        );

        [$parent, $child] = $this->dynamicBundleQuoteItems();

        $itemsByType = [
            'product' => [
                'bundle-1' => ['item' => 'ignored'],
                'child-1' => ['item' => 'ignored'],
            ],
        ];

        $taxDetails = $this->createMock(TaxDetailsInterface::class);
        $taxDetails->method('getItems')->willReturn([]);
        $this->taxCalculationService->method('calculateTax')->willReturn($taxDetails);

        $assignment = $this->shippingAssignmentWithAddress($this->quoteAddress(), [$parent, $child]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);

        $this->fallback()->calculate($itemsByType, $assignment, $quote);

        $this->assertSame([['code' => 'child-1', 'qty' => 2.0]], $mapped);
    }

    /**
     * A qty-2 dynamic bundle holding one $10 selection, as the quote stores it.
     * Returns [parent, child].
     */
    private function dynamicBundleQuoteItems()
    {
        $product = $this->getMockBuilder(ProductDouble::class)
            ->disableOriginalConstructor()->onlyMethods(['getTaxClassId'])->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        $build = function ($code, $qty, $parent, $children, $childrenCalculated) use ($product) {
            $item = $this->getMockBuilder(QuoteItemDouble::class)
                ->disableOriginalConstructor()
                ->onlyMethods([
                    'getTaxCalculationItemId', 'getProduct', 'getPrice', 'getQty', 'getDiscountAmount',
                    'getParentItem', 'getChildren', 'isChildrenCalculated',
                ])
                ->getMock();
            $item->method('getTaxCalculationItemId')->willReturn($code);
            $item->method('getProduct')->willReturn($product);
            $item->method('getPrice')->willReturn(10.0);
            $item->method('getQty')->willReturn($qty);
            $item->method('getDiscountAmount')->willReturn(0);
            $item->method('getParentItem')->willReturn($parent);
            $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
            if (is_callable($children)) {
                $item->method('getChildren')->willReturnCallback($children);
            } else {
                $item->method('getChildren')->willReturn($children);
            }

            return $item;
        };

        $children = [];
        $parent = $build('bundle-1', 2, null, function () use (&$children) {
            return $children;
        }, true);
        $child = $build('child-1', 1, $parent, [], false);
        $children[] = $child;

        return [$parent, $child];
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
