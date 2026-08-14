<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration\Model\Tax;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DataObject;
use PHPUnit\Framework\Attributes\DataProvider;
use Magento\Quote\Model\Quote;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * What TaxCloud is told about a cart, once per catalog type, against quote-item
 * trees that Magento built rather than a test wrote.
 *
 * The unit table in Test/Unit/Model/Gateway/CartLineShapesTest asserts the same
 * expectations against mocks. Mocks are the reason the bundle regression shipped:
 * they agree with whatever shape the test author believed in, and the author
 * believed a bundle child's qty was absolute. Only a real quote disagrees. So
 * these two files are deliberately redundant — the unit one is fast and total,
 * this one is slow and true.
 *
 * The oracle is a flat rate applied by the SOAP double, NOT TaxCloud. Real rates
 * change, and per-line cent rounding makes them a poor thing to assert (the same
 * 8.25% surfaces as "8.3%" on a $10 line and "8.267%" on a $30 one). What is
 * asserted here is what we ask for and what we do with the answer.
 */
class CatalogTypeLookupTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    /** Flat rate the SOAP double applies to every line it is sent. */
    private const RATE = 0.10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => $this->flatRateLookupResponder(self::RATE),
        ]));
    }

    /**
     * The cart lines each catalog type must produce.
     *
     * Expectations mirror what scripts/verify-test-products.php prints for the
     * same seeded product, which is in turn what Magento actually builds.
     *
     * @return array<string, array{sku: string, qty: int, expected: array<int, array{0: string, 1: float, 2: float}>}>
     */
    public static function catalogTypeProvider(): array
    {
        return [
            'simple' => [
                'sku' => 'test-product',
                'qty' => 1,
                'expected' => [['test-product', 1.0, 10.0]],
            ],
            'virtual' => [
                'sku' => 'test-virtual',
                'qty' => 1,
                'expected' => [['test-virtual', 1.0, 10.0]],
            ],
            'downloadable' => [
                'sku' => 'test-downloadable',
                'qty' => 1,
                'expected' => [['test-downloadable', 1.0, 10.0]],
            ],
            'configurable' => [
                'sku' => 'test-configurable',
                'qty' => 1,
                'expected' => [['test-variant-red', 1.0, 10.0]],
            ],
            // Explodes into independent lines; no grouped line of its own.
            'grouped' => [
                'sku' => 'test-grouped',
                'qty' => 1,
                'expected' => [['test-product', 1.0, 10.0], ['test-virtual', 1.0, 10.0]],
            ],
            // The regression, at qty 3: the selections store 1 and 2 per bundle,
            // so TaxCloud has to be told 3 and 6. Told 1 and 2, the shopper is
            // charged a third of what they owe.
            'bundle dynamic' => [
                'sku' => 'test-bundle-dynamic',
                'qty' => 3,
                'expected' => [['test-product', 3.0, 10.0], ['test-virtual', 6.0, 10.0]],
            ],
            // Parent-priced: one line, and the selections must not appear.
            'bundle fixed' => [
                'sku' => 'test-bundle-fixed',
                'qty' => 1,
                'expected' => [['test-bundle-fixed', 1.0, 50.0]],
            ],
            'giftcard' => [
                'sku' => 'test-giftcard',
                'qty' => 1,
                'expected' => [['test-giftcard', 1.0, 25.0]],
            ],
        ];
    }

    /**
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testLookupCartLinesForCatalogType(string $sku, int $qty, array $expected): void
    {
        $quote = $this->quoteWith($sku, $qty);
        $soap = $this->soapClient();

        $this->assertGreaterThanOrEqual(
            1,
            $soap->callCount('lookup'),
            "Collecting totals for $sku should have triggered a lookup."
        );

        $this->assertSame(
            $expected,
            $this->productLines($soap->firstCallArgs('lookup')['cartItems'] ?? []),
            "The lookup payload for $sku does not describe the cart Magento built. Compare with "
            . 'scripts/verify-test-products.php, which prints the same tree.'
        );
    }

    /**
     * The invariant that generalises past this table: whatever the type, the
     * basis we report has to be the basis Magento taxes. Under-report it and the
     * shopper is undercharged; over-report it and TaxCloud is told the store sold
     * more than it did. The original bundle defect did both at once.
     *
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testReportedBasisMatchesTheQuoteSubtotal(string $sku, int $qty, array $expected): void
    {
        $quote = $this->quoteWith($sku, $qty);

        $basis = 0.0;
        foreach ($this->productLines($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? []) as $line) {
            $basis += $line[1] * $line[2];
        }

        $this->assertEqualsWithDelta(
            (float) $this->taxedAddress($quote)->getSubtotal(),
            $basis,
            0.01,
            "The taxable basis sent to TaxCloud for $sku differs from the quote subtotal."
        );
    }

    /**
     * And the answer has to land: every line we sent gets its tax written back
     * onto the quote item that produced it, at the rate the double applied.
     *
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testLookupTaxIsAppliedToTheQuote(string $sku, int $qty, array $expected): void
    {
        $quote = $this->quoteWith($sku, $qty);

        $expectedTax = 0.0;
        foreach ($expected as [, $lineQty, $linePrice]) {
            $expectedTax += round($lineQty * $linePrice * self::RATE, 2);
        }

        $address = $this->taxedAddress($quote);

        $this->assertEqualsWithDelta(
            $expectedTax,
            (float) $address->getTaxAmount() - (float) $address->getShippingTaxAmount(),
            0.02,
            "Product tax on the $sku quote does not match the tax TaxCloud returned for its lines."
        );
    }

    /**
     * Virtual, downloadable and virtual gift cards ship nothing, so Magento
     * assigns a cart made only of them to the BILLING address and collects
     * totals there (Quote\Address::getAllItems). The tax therefore sources to
     * where the buyer is billed, and the shipping address stays empty — the
     * reason the two assertions above have to ask which address holds the items
     * rather than assuming it is the shipping one.
     *
     * Add one shippable line and the whole cart moves to the shipping address,
     * virtual items included. That is Magento's rule, not ours, and it is worth
     * pinning: it decides which state's rate a digital sale is taxed at.
     */
    public function testVirtualOnlyCartIsTaxedAgainstTheBillingAddress(): void
    {
        $quote = $this->quoteWith('test-virtual', 1);

        $this->assertTrue($quote->isVirtual(), 'A cart of one virtual product should be a virtual quote.');
        $this->assertEqualsWithDelta(
            0.0,
            (float) $quote->getShippingAddress()->getSubtotal(),
            0.01,
            'A virtual quote should leave the shipping address empty.'
        );
        $this->assertGreaterThan(
            0.0,
            (float) $quote->getBillingAddress()->getTaxAmount(),
            'A virtual quote should carry its tax on the billing address.'
        );
    }

    /**
     * The same virtual product in a cart that also ships something is taxed
     * against the shipping address instead — all items follow the shippable one.
     */
    public function testVirtualLineInAShippableCartFollowsTheShippingAddress(): void
    {
        $repository = $this->get(ProductRepositoryInterface::class);

        $quote = $this->newGuestQuote();
        $quote->addProduct($repository->get('test-virtual'), new DataObject(['qty' => 1]));
        $quote->addProduct($repository->get('test-product'), new DataObject(['qty' => 1]));
        $this->collectAndSaveQuote($quote);

        $this->assertFalse($quote->isVirtual(), 'A cart with a shippable line is not a virtual quote.');
        $this->assertSame(
            [['test-virtual', 1.0, 10.0], ['test-product', 1.0, 10.0]],
            $this->productLines($this->soapClient()->firstCallArgs('lookup')['cartItems'] ?? []),
            'Both lines should reach TaxCloud, sourced to the shipping address.'
        );
        $this->assertGreaterThan(
            0.0,
            (float) $quote->getShippingAddress()->getTaxAmount(),
            'A mixed cart carries its tax on the shipping address.'
        );
    }

    /**
     * Whichever address Magento put the items on.
     */
    private function taxedAddress(Quote $quote)
    {
        return $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
    }

    /**
     * Build, collect and return a guest quote holding one seeded product.
     */
    private function quoteWith(string $sku, int $qty): Quote
    {
        $product = $this->seededProduct($sku);

        $quote = $this->newGuestQuote();
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, $qty)));

        return $this->collectAndSaveQuote($quote);
    }



}
