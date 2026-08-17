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
use PHPUnit\Framework\Attributes\DataProvider;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Test\Integration\SeededCatalogTrait;

/**
 * An order of each catalog type from checkout to refund.
 *
 * Every stage of the lifecycle builds its payload from a different source — the
 * quote, then the order's visible items, then the credit memo's items — and each
 * source stores a composite differently. Coverage of one stage says nothing about
 * the others, which is how an order came to be authorized for $70 of goods,
 * charged for $50 worth of tax basis, and refundable for something else again.
 *
 * Beyond the composites, virtual and downloadable are here because they are the
 * types that produce a VIRTUAL order: no shipping address at all, so capture and
 * refund read totals from the billing address instead. That is a different route
 * through the same code, and the shape most likely to be missed.
 *
 * Gift card is absent: its module ships only with Adobe Commerce, and a row here
 * would skip on every Open Source run rather than cover anything.
 */
class CatalogTypeLifecycleTest extends IntegrationTestCase
{
    use SeededCatalogTrait;

    private const RATE = 0.10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock($this->soapResponsesWith([
            'lookup' => $this->flatRateLookupResponder(self::RATE),
        ]));
    }

    /**
     * @return array<string, array{sku: string, qty: int, expected: array<int, array{0: string, 1: float, 2: float}>}>
     */
    public static function catalogTypeProvider(): array
    {
        return [
            'bundle dynamic' => [
                'sku' => 'test-bundle-dynamic',
                'qty' => 2,
                'expected' => [['test-product', 2.0, 10.0], ['test-virtual', 4.0, 10.0]],
            ],
            'bundle fixed' => [
                'sku' => 'test-bundle-fixed',
                'qty' => 2,
                'expected' => [['test-bundle-fixed', 2.0, 50.0]],
            ],
            'configurable' => [
                'sku' => 'test-configurable',
                'qty' => 2,
                'expected' => [['test-variant-red', 2.0, 10.0]],
            ],
            'grouped' => [
                'sku' => 'test-grouped',
                'qty' => 2,
                'expected' => [['test-product', 2.0, 10.0], ['test-virtual', 2.0, 10.0]],
            ],
            // Neither of these ships, so each makes a VIRTUAL order: no shipping
            // address, totals collected against billing, and a different route
            // through capture and refund than everything above.
            'virtual' => [
                'sku' => 'test-virtual',
                'qty' => 2,
                'expected' => [['test-virtual', 2.0, 10.0]],
            ],
            'downloadable' => [
                'sku' => 'test-downloadable',
                'qty' => 2,
                'expected' => [['test-downloadable', 2.0, 10.0]],
            ],
        ];
    }

    /**
     * Capturing books the Lookup by cart id, so whatever the Lookup said is what
     * the store is on the hook for. This asserts the Lookup said the right thing
     * and that capture really did fire against that order.
     *
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testCaptureBooksTheLinesTheLookupReported(string $sku, int $qty, array $expected): void
    {
        $order = $this->placeOrderFor($sku, $qty);
        $soap = $this->soapClient();

        $this->assertSame(
            $expected,
            $this->productLines($soap->firstCallArgs('lookup')['cartItems'] ?? []),
            "The lookup for $sku does not describe the order."
        );

        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            "Placing a $sku order should capture it exactly once."
        );
        $this->assertSame(
            $order->getIncrementId(),
            $soap->firstCallArgs('authorizedWithCapture')['orderID'] ?? null,
            'The captured order id should be this order.'
        );
    }

    /**
     * Cancelling an uncaptured order reverses it from the order's own items
     * rather than a credit memo — a third code path over the same composite.
     *
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testCancelReversesTheLinesTheLookupReported(string $sku, int $qty, array $expected): void
    {
        $order = $this->placeOrderFor($sku, $qty);
        $soap = $this->soapClient();
        $soap->resetCalls();

        $this->cancelOrder($order);

        $this->assertSame(
            1,
            $soap->callCount('Returned'),
            "Cancelling a captured $sku order should reverse it exactly once."
        );
        $this->assertSame(
            $expected,
            $this->productLines($soap->firstCallArgs('Returned')['cartItems'] ?? []),
            "Cancelling $sku reverses different goods than were reported at checkout."
        );
    }

    /**
     * A full refund, from the credit memo path.
     *
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testFullRefundReturnsTheWholeOrder(string $sku, int $qty, array $expected): void
    {
        $order = $this->placeOrderFor($sku, $qty);
        $this->payInvoice($order);

        $soap = $this->soapClient();
        $soap->resetCalls();

        $this->refundOrder($order);

        $this->assertSame(
            $expected,
            $this->productLines($soap->firstCallArgs('Returned')['cartItems'] ?? []),
            "The full refund of $sku returns different goods than the order reported."
        );
    }

    /**
     * Refunding half the order must return half the goods — and for a composite
     * that means halving the quantity Magento derived for each selection, not the
     * one stored against it.
     *
     * @dataProvider catalogTypeProvider
     */
    #[DataProvider('catalogTypeProvider')]
    public function testPartialRefundReturnsProportionalQuantities(string $sku, int $qty, array $expected): void
    {
        if ($qty < 2) {
            $this->markTestSkipped('Needs an order quantity that can be halved.');
        }

        $order = $this->placeOrderFor($sku, $qty);
        $this->payInvoice($order);

        $soap = $this->soapClient();
        $soap->resetCalls();

        $this->refundHalfOf($order);

        $returned = $this->productLines($soap->firstCallArgs('Returned')['cartItems'] ?? []);

        $halved = array_map(
            static fn ($line) => [$line[0], $line[1] / 2, $line[2]],
            $expected
        );

        $this->assertSame(
            $halved,
            $returned,
            "A half refund of $sku returns the wrong quantities. Each line should come back at "
            . 'half the units the order reported.'
        );
    }

    /**
     * Place an order holding one seeded product.
     */
    private function placeOrderFor(string $sku, int $qty): Order
    {
        $product = $this->seededProduct($sku);

        $quote = $this->newGuestQuote();
        $quote->addProduct($product, new DataObject($this->buyRequestFor($product, $qty)));
        $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());

        return $this->get(OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * Credit memo for half of every refundable line.
     *
     * "Refundable" means not dummy, which is the same test the admin credit memo
     * form applies when deciding which rows get a quantity box — and it moves
     * between parent and child depending on the composite. A dynamic bundle's
     * priced lines are its selections, so listing the wrapper instead makes
     * CreditmemoFactory::getQtyToRefund() return 0 for every selection and the
     * refund comes back empty.
     */
    private function refundHalfOf(Order $order): void
    {
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->isDummy()) {
                continue;
            }
            $qtys[(int) $item->getId()] = (float) $item->getQtyOrdered() / 2;
        }

        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, ['qtys' => $qtys]);
        $this->get(CreditmemoService::class)->refund($creditmemo, true);
    }
}
