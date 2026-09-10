<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Address;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Address\TaxAddressResolver;

/**
 * The one rule that decides which state a sale is taxed in.
 *
 * Both halves are asserted against the Magento behaviour they mirror, because
 * neither is obvious from the call site: an order's shipping address can be
 * absent outright (Magento never converts one for a virtual quote), while a
 * quote's never is (Quote::_getAddressByType lazily attaches an empty one), so
 * the same `?:` idiom is load-bearing in one place and dead in the other.
 */
#[AllowMockObjectsWithoutExpectations]
class TaxAddressResolverTest extends TestCase
{
    /**
     * @var TaxAddressResolver
     */
    private TaxAddressResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TaxAddressResolver();
    }

    public function testOrderWithAShippingAddressUsesIt()
    {
        $shipping = $this->createMock(OrderAddress::class);
        $billing = $this->createMock(OrderAddress::class);

        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn($shipping);
        $order->method('getBillingAddress')->willReturn($billing);

        $this->assertSame(
            $shipping,
            $this->resolver->forOrder($order),
            'A delivery address, where one is known, is never displaced by the billing address.'
        );
    }

    /**
     * The defect this change exists for: Magento returns false, not an empty
     * address, for an order placed from a wholly virtual cart.
     */
    public function testOrderWithoutAShippingAddressFallsBackToBilling()
    {
        $billing = $this->createMock(OrderAddress::class);

        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn(false);
        $order->method('getBillingAddress')->willReturn($billing);

        $this->assertSame(
            $billing,
            $this->resolver->forOrder($order),
            'A digital-only order has no shipping address; it must still be sourced somewhere.'
        );
    }

    public function testOrderWithNeitherAddressResolvesToNull()
    {
        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn(false);
        $order->method('getBillingAddress')->willReturn(false);

        $this->assertNull(
            $this->resolver->forOrder($order),
            'With no address at all the caller must fail loudly, not invent one.'
        );
    }

    public function testNullOrderResolvesToNull()
    {
        $this->assertNull($this->resolver->forOrder(null));
    }

    /**
     * A virtual quote's items live on the billing address
     * (Quote\Address::getAllItems), so that is the address its totals — and
     * therefore its destination — were collected against.
     */
    public function testVirtualQuoteResolvesToItsBillingAddress()
    {
        $shipping = $this->createMock(QuoteAddress::class);
        $billing = $this->createMock(QuoteAddress::class);

        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('getBillingAddress')->willReturn($billing);

        $this->assertSame(
            $billing,
            $this->resolver->forQuote($quote),
            'A quote always has a (possibly empty) shipping address, so the choice cannot be a `?:`.'
        );
    }

    public function testNonVirtualQuoteResolvesToItsShippingAddress()
    {
        $shipping = $this->createMock(QuoteAddress::class);
        $billing = $this->createMock(QuoteAddress::class);

        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('getBillingAddress')->willReturn($billing);

        $this->assertSame(
            $shipping,
            $this->resolver->forQuote($quote),
            'A cart with anything shippable in it sources every line to the shipping address.'
        );
    }

    public function testNullQuoteResolvesToNull()
    {
        $this->assertNull($this->resolver->forQuote(null));
    }
}
