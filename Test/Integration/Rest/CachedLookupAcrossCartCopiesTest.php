<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration\Rest;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\OrderRepositoryInterface;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Ticket 66649: a cart charged tax on shipping only, although TaxCloud had
 * priced the products.
 *
 * Magento labels cart lines `sequence-N` from a counter that is never reset
 * within a request, and labels a line once per loaded item object. When one
 * request prices the same cart through two loaded copies, the second copy's
 * lines get later labels than the first copy's — while both send TaxCloud the
 * identical request, so the second is answered from the lookup cache.
 *
 * Core does exactly this when a shopper with a guest cart logs in to an
 * account that already has a cart (Checkout\Model\Session::loadCustomerQuote):
 * the merged cart is priced and saved, fetched again as a new object, priced
 * and saved again. The second pricing is the one that stays on the cart.
 *
 * That is a fact about real Magento (when it labels, and how its collectors
 * keep count), which is why this is an integration test: the quote, the
 * collectors and the cache are real, and only the v3 transport is recorded.
 */
class CachedLookupAcrossCartCopiesTest extends IntegrationTestCase
{
    private const RATE = 0.10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRestMock($this->restRespondersWith([
            'POST /carts' => $this->flatRateCartResponder(self::RATE),
        ]));
        $this->setScopedConfig('tax/taxcloud_settings/api_type', 'rest');
    }

    public function testACartPricedThroughASecondLoadedCopyKeepsItsProductTax(): void
    {
        $quoteId = (int) $this->buildQuoteWithTestProduct()->getId();

        // First copy: priced live, and the answer is cached.
        $first = $this->loadQuoteAfresh($quoteId);
        $first->collectTotals();
        $this->get(CartRepositoryInterface::class)->save($first);

        $line = $this->onlyLine($first);
        $expectedProductTax = round((float) $line->getRowTotal() * self::RATE, 2);
        $expectedShippingTax = round((float) $first->getShippingAddress()->getShippingAmount() * self::RATE, 2);
        $this->assertGreaterThan(0, $expectedProductTax, 'Precondition: the line must be taxable.');
        $this->assertGreaterThan(0, $expectedShippingTax, 'Precondition: shipping must be taxable.');
        $this->assertEqualsWithDelta(
            $expectedProductTax,
            (float) $line->getTaxAmount(),
            0.001,
            'Precondition: the live lookup taxed the line.'
        );
        $liveLookups = $this->restMock()->callCount('POST', '/carts');

        // Second copy, same process: new item objects, so new labels.
        $second = $this->loadQuoteAfresh($quoteId);
        $second->collectTotals();
        $this->get(CartRepositoryInterface::class)->save($second);

        $this->assertNotSame(
            $line->getTaxCalculationItemId(),
            $this->onlyLine($second)->getTaxCalculationItemId(),
            'Precondition: Magento must have labelled the second copy\'s line differently.'
        );
        $this->assertSame(
            $liveLookups,
            $this->restMock()->callCount('POST', '/carts'),
            'Precondition: the second pricing must be answered from the lookup cache, not a new call.'
        );
        $this->assertEqualsWithDelta(
            $expectedProductTax,
            (float) $this->onlyLine($second)->getTaxAmount(),
            0.001,
            'The cached lookup lost the product tax when the cart was priced through a second copy.'
        );
        $this->assertEqualsWithDelta(
            $expectedProductTax + $expectedShippingTax,
            (float) $second->getShippingAddress()->getTaxAmount(),
            0.001,
            'The cart must carry tax on the product as well as on shipping.'
        );

        // What the cart carries is what the order is charged.
        $orderId = $this->get(CartManagementInterface::class)->placeOrder($quoteId);
        $order = $this->get(OrderRepositoryInterface::class)->get($orderId);

        $this->assertEqualsWithDelta(
            $expectedProductTax + $expectedShippingTax,
            (float) $order->getTaxAmount(),
            0.001,
            'The order must carry tax on the product as well as on shipping.'
        );
        $this->assertEqualsWithDelta($expectedShippingTax, (float) $order->getShippingTaxAmount(), 0.001);
    }

    /**
     * The quote as loadCustomerQuote() re-fetches it: new item objects, which
     * Magento has not labelled yet. A new repository, because the shared one
     * may hand back the instance it already holds.
     */
    private function loadQuoteAfresh(int $quoteId): Quote
    {
        /** @var Quote $quote */
        $quote = $this->objectManager()->create(QuoteRepository::class)->get($quoteId);

        return $quote;
    }

    /**
     * @return \Magento\Quote\Model\Quote\Item
     */
    private function onlyLine(Quote $quote)
    {
        $items = $quote->getAllVisibleItems();
        $this->assertCount(1, $items, 'The cart must hold exactly one line.');

        return reset($items);
    }
}
