<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration\Model\Tax;

use Magento\Framework\DataObject;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use PHPUnit\Framework\Attributes\DataProvider;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * Where an ORDER is sourced to, once its cart has become one.
 *
 * CatalogTypeLookupTest covers the quote: a cart that ships nothing is priced
 * against the billing address, a cart that ships something against the shipping
 * address. This file covers what happens after placement, where the two
 * diverge — because a Magento order built from a wholly virtual quote has no
 * shipping address at all. QuoteManagement::submitQuote converts one only for a
 * non-virtual quote, so Order::getShippingAddress() returns false outright.
 *
 * Reading that address without a fallback produced no destination, and every
 * order-side operation that needs one treats "no destination" as "cannot
 * proceed". On a REST-selected store that meant a download-only order was
 * taxed, paid for, and never filed: the customer's money was collected and no
 * transaction existed to file it under.
 *
 * Every cart here bills to Texas and ships to Colorado where it ships at all,
 * so an assertion about which address was used can actually fail. The seeded
 * verifyAddress double rewrites every destination to a fixed Austin address, so
 * these tests install one that echoes instead — otherwise the whole file would
 * pass no matter which address the module chose.
 */
class VirtualOrderSourcingTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    private const RATE = 0.10;

    /** The shared fixture's ZIP, which is its BILLING address. */
    private const BILLING_ZIP = '78701';

    /** A different state entirely, so a mis-sourced order is visible. */
    private const SHIPPING_ZIP = '80202';

    /** @var array<string, mixed> Ship-to Denver CO, over a Texas billing address. */
    private const SHIPPING_OVERRIDE = [
        'street'    => '1701 Broadway',
        'city'      => 'Denver',
        'region_id' => 13,
        'region'    => 'Colorado',
        'postcode'  => self::SHIPPING_ZIP,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => $this->flatRateLookupResponder(self::RATE),
            'verifyAddress' => $this->echoingVerifyAddressResponder(),
        ]));
    }

    /**
     * The defect, at the point it bit: a download-only order could not produce a
     * v3 order payload, so capture on a REST-selected store filed nothing.
     *
     * Asserted against the real RestRequestBuilder over a really-placed order.
     * The v3 transport itself is not exercised — order placement runs on the
     * SOAP mock, per the seeded api_type — but the payload is where the failure
     * was, and it is built from order data either way.
     *
     * @dataProvider digitalSkuProvider
     */
    #[DataProvider('digitalSkuProvider')]
    public function testADownloadOnlyOrderCanBeFiled(string $sku): void
    {
        $order = $this->placeOrderFor($sku);

        $this->assertFalse(
            (bool) $order->getShippingAddress(),
            "A $sku-only order should have no shipping address at all — if it has one, this test "
            . 'is no longer exercising the case it was written for.'
        );

        $destination = $this->get(RequestBuilder::class)->buildDestinationFromOrder($order);
        $this->assertNotNull($destination, 'A digital-only order must resolve a destination.');
        $this->assertSame(self::BILLING_ZIP, $destination['Zip5'], 'Sourced to the billing address.');

        $payload = $this->get(RestRequestBuilder::class)->buildOrderPayload($order);
        $this->assertNotNull(
            $payload,
            'A digital-only order must produce a v3 order payload. Returning null here is the '
            . 'defect: the sale is charged to the customer and never filed with TaxCloud.'
        );
        $this->assertSame(self::BILLING_ZIP, $payload['destination']['zip']);
        $this->assertSame($order->getIncrementId(), $payload['orderId']);
    }

    /**
     * And the same order really was captured — the v1 path files by cart id and
     * so never needed a destination, which is exactly why this went unseen.
     *
     * @dataProvider digitalSkuProvider
     */
    #[DataProvider('digitalSkuProvider')]
    public function testADownloadOnlyOrderIsCaptured(string $sku): void
    {
        $order = $this->placeOrderFor($sku);

        $this->assertSame(
            1,
            $this->soapClient()->callCount('authorizedWithCapture'),
            "Placing a $sku-only order should capture it exactly once."
        );
        $this->assertSame(
            '1',
            (string) $this->reloadOrder($order)->getData('taxcloud_captured'),
            'A captured order must be flagged as captured, or a cancellation has nothing to reverse.'
        );
    }

    /**
     * The property that makes the fallback correct rather than merely non-null:
     * a sale is filed against the address it was quoted against. Quote one
     * address and file another and the tax the customer paid is not the tax the
     * filed order accounts for.
     *
     * Compared as an address, not as a payload — verifyAddress normalises the
     * lookup copy and not the order copy, and that predates this change.
     *
     * @dataProvider cartShapeProvider
     */
    #[DataProvider('cartShapeProvider')]
    public function testAnOrderIsFiledAgainstTheAddressItWasQuotedAgainst(
        array $skus,
        string $expectedZip,
        string $message
    ): void {
        $order = $this->placeOrderWith($skus);

        $quoted = $this->soapClient()->firstCallArgs('lookup')['destination'] ?? null;
        $this->assertNotNull($quoted, 'The cart should have been priced.');

        $filed = $this->get(RequestBuilder::class)->buildDestinationFromOrder($order);
        $this->assertNotNull($filed, 'The order should resolve a destination.');

        $this->assertSame($expectedZip, $quoted['Zip5'], $message . ' (at lookup)');
        $this->assertSame($expectedZip, $filed['Zip5'], $message . ' (at capture)');

        $this->assertSame(
            $this->comparableAddress($quoted),
            $this->comparableAddress($filed),
            'The address the sale was quoted against and the address it is filed against must be '
            . 'the same address.'
        );
    }

    /**
     * The tax-only refund path: TaxCloud is told to return the whole order, then
     * to re-create it as exempt. That re-create needs a destination of its own,
     * so before the fallback a download-only order could be refunded but never
     * re-created — TaxCloud kept no exempt record of a sale that had happened.
     */
    public function testATaxOnlyRefundOfADownloadOnlyOrderIsRecreatedAsExempt(): void
    {
        $order = $this->placeOrderFor('test-virtual');
        $this->payInvoice($order);

        $soap = $this->soapClient();
        $soap->resetCalls();

        $this->refundTaxOnly($order);

        $this->assertSame(1, $soap->callCount('Returned'), 'The order should be returned in full.');

        $exemptLookups = $soap->callsTo('lookup');
        $this->assertCount(
            1,
            $exemptLookups,
            'A tax-only refund re-creates the order as exempt, which begins with its own lookup. '
            . 'Zero lookups means the re-create was abandoned for want of a destination.'
        );
        $this->assertSame(
            self::BILLING_ZIP,
            $exemptLookups[0]['destination']['Zip5'] ?? null,
            'The exempt re-create must be sourced where the sale was.'
        );

        $exemptCaptures = $soap->callsTo('authorizedWithCapture');
        $this->assertCount(1, $exemptCaptures, 'The exempt re-create must then be captured.');
        $this->assertSame(
            $order->getIncrementId() . '-exempt',
            $exemptCaptures[0]['cartID'] ?? null,
            'The exempt re-create files the same order under a distinct cart id, which is what '
            . 'keeps it from colliding with the original sale.'
        );
        $this->assertSame(
            $order->getIncrementId(),
            $exemptCaptures[0]['orderID'] ?? null,
            'It is still the same order.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function digitalSkuProvider(): array
    {
        return [
            'virtual' => ['test-virtual'],
            'downloadable' => ['test-downloadable'],
        ];
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string, 2: string}>
     */
    public static function cartShapeProvider(): array
    {
        return [
            'download-only' => [
                ['test-virtual'],
                self::BILLING_ZIP,
                'A cart that ships nothing is sourced to the billing address',
            ],
            'mixed' => [
                ['test-virtual', 'test-product'],
                self::SHIPPING_ZIP,
                'A cart with anything shippable is sourced to the shipping address',
            ],
            'physical' => [
                ['test-product'],
                self::SHIPPING_ZIP,
                'A shipped cart is sourced to the shipping address',
            ],
        ];
    }

    /**
     * The fields worth comparing between a lookup destination and an order
     * destination. Case is not one of them: verifyAddress uppercases the lookup
     * copy on its way out and never sees the order copy. Nor is Zip4, which the
     * same normalisation fills in.
     *
     * @param array<string, mixed> $destination
     * @return array<int, string>
     */
    private function comparableAddress(array $destination): array
    {
        return [
            strtoupper(trim((string) $destination['Address1'])),
            strtoupper(trim((string) $destination['City'])),
            (string) $destination['State'],
            (string) $destination['Zip5'],
        ];
    }

    private function placeOrderFor(string $sku): Order
    {
        return $this->placeOrderWith([$sku]);
    }

    /**
     * @param array<int, string> $skus
     */
    private function placeOrderWith(array $skus): Order
    {
        $quote = $this->newGuestQuote([], 'default', self::SHIPPING_OVERRIDE);

        foreach ($skus as $sku) {
            $product = $this->seededProduct($sku);
            $quote->addProduct($product, new DataObject($this->buyRequestFor($product, 1)));
        }

        $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * A credit memo that returns no goods and no shipping, for exactly the tax
     * the order charged — what a merchant records when a customer turns out to
     * have been exempt all along.
     */
    private function refundTaxOnly(Order $order): void
    {
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            $qtys[(int) $item->getId()] = 0;
        }

        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, [
            'qtys' => $qtys,
            'shipping_amount' => 0,
            'adjustment_positive' => (float) $order->getBaseTaxAmount(),
            'adjustment_negative' => 0,
        ]);

        $this->get(CreditmemoService::class)->refund($creditmemo, true);
    }
}
