<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Test\Unit\Double\QuoteDouble;

/**
 * v3 payload construction: v1→v3 shape conversion, credential-free payloads,
 * store-currency amounts, supplied per-line tax on orders, and the
 * quantity-only refund expression (fractional for shipping and adjustment
 * distributions).
 */
#[AllowMockObjectsWithoutExpectations]
class RestRequestBuilderTest extends TestCase
{
    /**
     * @var TaxcloudConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var RequestBuilder&\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestBuilder;

    /**
     * @var ProductTicService&\PHPUnit\Framework\MockObject\MockObject
     */
    private $ticService;

    private function builder(): RestRequestBuilder
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->requestBuilder = $this->createMock(RequestBuilder::class);
        $this->ticService = $this->createMock(ProductTicService::class);

        return new RestRequestBuilder($this->config, $this->requestBuilder, $this->ticService);
    }

    private function quote(string $id = '77', string $currency = 'USD'): QuoteDouble
    {
        $quote = $this->getMockBuilder(QuoteDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getQuoteCurrencyCode'])
            ->getMock();
        $quote->method('getId')->willReturn($id);
        $quote->method('getStoreId')->willReturn(3);
        $quote->method('getQuoteCurrencyCode')->willReturn($currency);

        return $quote;
    }

    private const V1_ORIGIN = [
        'Address1' => '162 East Ave',
        'Address2' => '',
        'City' => 'Norwalk',
        'State' => 'CT',
        'Zip5' => '06851',
        'Zip4' => '',
    ];

    private const V1_DESTINATION = [
        'Address1' => '100 Main St',
        'Address2' => 'Suite 5',
        'City' => 'Bronx',
        'State' => 'NY',
        'Zip5' => '10451',
        'Zip4' => '1234',
    ];

    public function testCartLineItemsConvertV1Shapes()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildLookupCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '00000', 'Price' => 12.5, 'Qty' => 2],
                ['ItemID' => 'shipping', 'Index' => 1, 'TIC' => '11010', 'Price' => 5.99, 'Qty' => 1],
            ],
            'indexedItems' => [0 => 'code-a'],
        ]);

        $built = $builder->buildCartLineItems([], [], null, 3);

        $this->assertSame([
            ['index' => 0, 'itemId' => 'sku-1', 'tic' => 0, 'price' => 12.5, 'quantity' => 2.0],
            ['index' => 1, 'itemId' => 'shipping', 'tic' => 11010, 'price' => 5.99, 'quantity' => 1.0],
        ], $built['lineItems']);
        $this->assertSame([0 => 'code-a'], $built['indexedItems']);
    }

    public function testCartPayloadAssemblesV3CartKeyedByQuoteId()
    {
        $builder = $this->builder();

        $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $lineItems = [['index' => 0, 'itemId' => 'sku-1', 'tic' => 0, 'price' => 12.5, 'quantity' => 2.0]];
        $payload = $builder->buildCartPayload(
            $customer,
            $this->quote(),
            $lineItems,
            self::V1_ORIGIN,
            self::V1_DESTINATION,
            null
        );

        $cart = $payload['items'][0];
        $this->assertSame('77', $cart['cartId']);
        $this->assertSame('42', $cart['customerId']);
        $this->assertSame(['currencyCode' => 'USD'], $cart['currency']);
        $this->assertFalse($cart['deliveredBySeller']);
        $this->assertSame($lineItems, $cart['lineItems']);
        $this->assertArrayNotHasKey('exemption', $cart);

        // v1 → v3 address conversion: line2 omitted when empty, ZIP+4 folded.
        $this->assertSame(
            ['line1' => '162 East Ave', 'city' => 'Norwalk', 'state' => 'CT', 'zip' => '06851'],
            $cart['origin']
        );
        $this->assertSame(
            [
                'line1' => '100 Main St',
                'city' => 'Bronx',
                'state' => 'NY',
                'zip' => '10451-1234',
                'line2' => 'Suite 5',
            ],
            $cart['destination']
        );

        // Payloads never carry credentials — v3 auth is transport headers.
        $json = json_encode($payload);
        $this->assertStringNotContainsString('apiLoginID', $json);
        $this->assertStringNotContainsString('apiKey', $json);
    }

    public function testCartPayloadFallsBackToGuestCustomerId()
    {
        $builder = $this->builder();
        $this->config->method('getGuestCustomerId')->with(3)->willReturn('guest-3');

        $payload = $builder->buildCartPayload(
            null,
            $this->quote(),
            [],
            self::V1_ORIGIN,
            self::V1_DESTINATION,
            null
        );

        $this->assertSame('guest-3', $payload['items'][0]['customerId']);
    }

    public function testCartPayloadAttachesValidatedExemption()
    {
        $builder = $this->builder();

        $payload = $builder->buildCartPayload(
            null,
            $this->quote(),
            [],
            self::V1_ORIGIN,
            self::V1_DESTINATION,
            'cert-123'
        );

        $this->assertSame(['exemptionId' => 'cert-123'], $payload['items'][0]['exemption']);
    }

    public function testCartPayloadNormalizesCurrency()
    {
        $builder = $this->builder();

        $cad = $builder->buildCartPayload(
            null,
            $this->quote('77', 'cad'),
            [],
            self::V1_ORIGIN,
            self::V1_DESTINATION,
            null
        );
        $this->assertSame('CAD', $cad['items'][0]['currency']['currencyCode']);

        $eur = $builder->buildCartPayload(
            null,
            $this->quote('77', 'EUR'),
            [],
            self::V1_ORIGIN,
            self::V1_DESTINATION,
            null
        );
        $this->assertSame('USD', $eur['items'][0]['currency']['currencyCode'], 'unsupported currencies file as USD');
    }

    /**
     * A dynamic-price bundle files its SELECTIONS, not the wrapper.
     *
     * The cart was filed under the selections' item ids (the v1 builder emits
     * them and buildCartLineItems just reshapes its output), and a v3 refund
     * references an order's item ids. File the wrapper here and the three
     * payloads describe three different orders: the refund would name goods the
     * order never reported, which v3 rejects rather than guesses at.
     */
    public function testOrderPayloadFilesBundleSelectionsNotTheWrapper(): void
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(self::V1_DESTINATION);
        $this->ticService->method('getProductTic')->willReturn('20010');
        $this->ticService->method('getShippingTic')->willReturn('11010');

        $childA = $this->orderItem('test-product', 2.0, 10.0, 0.0, 1.65, 8.25);
        $childB = $this->orderItem('test-virtual', 4.0, 10.0, 0.0, 3.30, 8.25);

        // The wrapper: priced at the sum of its selections, tax an echo of theirs.
        $bundle = $this->orderItem('test-bundle-dynamic', 2.0, 20.0, 0.0, 4.95, 8.25);
        $bundle->method('isChildrenCalculated')->willReturn(true);
        $bundle->method('getChildrenItems')->willReturn([$childA, $childB]);

        $payload = $builder->buildOrderPayload($this->order([$bundle]));

        $this->assertSame(
            ['test-product', 'test-virtual'],
            array_column($payload['lineItems'], 'itemId'),
            'The order must be filed under the selection item ids the cart used.'
        );
        $this->assertSame([2.0, 4.0], array_column($payload['lineItems'], 'quantity'));

        // The wrapper's tax is its children's, so counting it too would file
        // roughly double the tax actually charged.
        $this->assertEqualsWithDelta(
            4.95,
            array_sum(array_column(array_column($payload['lineItems'], 'tax'), 'amount')),
            0.01,
            'Filed tax should equal the bundle line tax, counted once.'
        );
    }

    /**
     * The inverse: a parent-priced composite keeps filing its parent, because
     * that is the line carrying the price and the one the cart used.
     */
    public function testOrderPayloadFilesTheParentOfAParentPricedComposite(): void
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(self::V1_DESTINATION);
        $this->ticService->method('getProductTic')->willReturn('20010');
        $this->ticService->method('getShippingTic')->willReturn('11010');

        $child = $this->orderItem('test-product', 2.0, 0.0, 0.0, 0.0, 0.0);
        $parent = $this->orderItem('test-bundle-fixed', 2.0, 50.0, 0.0, 8.25, 8.25);
        $parent->method('isChildrenCalculated')->willReturn(false);
        $parent->method('getChildrenItems')->willReturn([$child]);

        $payload = $builder->buildOrderPayload($this->order([$parent]));

        $this->assertSame(['test-bundle-fixed'], array_column($payload['lineItems'], 'itemId'));
        $this->assertSame([2.0], array_column($payload['lineItems'], 'quantity'));
    }

    private function orderItem(
        string $sku,
        float $qty,
        float $price,
        float $discount,
        float $taxAmount,
        float $taxPercent
    ): OrderItem {
        $item = $this->createMock(OrderItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQtyOrdered')->willReturn($qty);
        $item->method('getPrice')->willReturn($price);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getTaxAmount')->willReturn($taxAmount);
        $item->method('getTaxPercent')->willReturn($taxPercent);

        return $item;
    }

    private function order(array $items, float $shipping = 0.0, float $shippingTax = 0.0): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getCreatedAt')->willReturn('2026-08-01 14:30:00');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllVisibleItems')->willReturn($items);
        $order->method('getShippingAmount')->willReturn($shipping);
        $order->method('getShippingTaxAmount')->willReturn($shippingTax);

        return $order;
    }

    public function testOrderPayloadSuppliesStoredTaxAndDates()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->with(3)->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(self::V1_DESTINATION);
        $this->ticService->method('getProductTic')->willReturn('20010');
        $this->ticService->method('getShippingTic')->willReturn('11010');

        // $50 unit, $10 line discount over 2 units → $45 effective unit price.
        $item = $this->orderItem('sku-1', 2.0, 50.0, 10.0, 7.65, 8.5);
        $payload = $builder->buildOrderPayload($this->order([$item], 5.99, 0.38));

        $this->assertSame('100000042', $payload['orderId']);
        $this->assertSame('42', $payload['customerId']);
        $this->assertSame('2026-08-01T14:30:00Z', $payload['transactionDate']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $payload['completedDate']
        );
        $this->assertSame(['currencyCode' => 'USD'], $payload['currency']);
        $this->assertFalse($payload['deliveredBySeller']);
        $this->assertArrayNotHasKey('exemption', $payload);

        $this->assertSame([
            'index' => 0,
            'itemId' => 'sku-1',
            'tic' => 20010,
            'price' => 45.0,
            'quantity' => 2.0,
            'tax' => ['amount' => 7.65, 'rate' => 0.085],
        ], $payload['lineItems'][0]);
        $this->assertSame([
            'index' => 1,
            'itemId' => 'shipping',
            'tic' => 11010,
            'price' => 5.99,
            'quantity' => 1,
            'tax' => ['amount' => 0.38, 'rate' => round(0.38 / 5.99, 5)],
        ], $payload['lineItems'][1]);

        $json = json_encode($payload);
        $this->assertStringNotContainsString('apiLoginID', $json);
        $this->assertStringNotContainsString('apiKey', $json);
    }

    /**
     * Capture fires before the order is saved: composite children have no
     * parent_item_id yet, so getAllVisibleItems() lets them through — the
     * builder must drop them on the in-memory parent_item object (observed
     * live 2026-08-07: a configurable filed its zero-priced child as a
     * duplicate v3 line, which then poisoned every refund of the order).
     */
    public function testOrderPayloadSkipsCompositeChildRowsOnUnsavedOrders()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(self::V1_DESTINATION);
        $this->ticService->method('getProductTic')->willReturn('0');

        $parent = $this->orderItem('test-variant-blue', 1.0, 10.0, 0.0, 0.83, 8.25);

        $child = $this->orderItem('test-variant-blue', 1.0, 0.0, 0.0, 0.0, 0.0);
        $child->method('getParentItem')->willReturn($parent);
        // Unsaved order: parent_item_id not assigned yet (mock defaults null).

        $payload = $builder->buildOrderPayload($this->order([$parent, $child]));

        $itemIds = array_column($payload['lineItems'], 'itemId');
        $this->assertSame(['test-variant-blue'], $itemIds, 'the child row must not file as a duplicate line');
        $this->assertSame(10.0, $payload['lineItems'][0]['price']);
    }

    public function testOrderPayloadExemptRecreateZeroesTaxAndOverridesId()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(self::V1_DESTINATION);
        $this->ticService->method('getProductTic')->willReturn('0');

        $item = $this->orderItem('sku-1', 1.0, 50.0, 0.0, 4.25, 8.5);
        $payload = $builder->buildOrderPayload($this->order([$item]), '100000042-exempt', true);

        $this->assertSame('100000042-exempt', $payload['orderId']);
        $this->assertSame(['isExempt' => true], $payload['exemption']);
        $this->assertSame(['amount' => 0.0, 'rate' => 0.0], $payload['lineItems'][0]['tax']);
    }

    public function testOrderPayloadRequiresValidAddressesAndLines()
    {
        $builder = $this->builder();

        // Origin invalid.
        $this->requestBuilder->method('buildOrigin')->willReturn(null);
        $this->assertNull($builder->buildOrderPayload($this->order([$this->orderItem('s', 1, 1, 0, 0, 0)])));

        // Destination invalid.
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(null);
        $this->assertNull($builder->buildOrderPayload($this->order([$this->orderItem('s', 1, 1, 0, 0, 0)])));

        // No billable lines.
        $builder = $this->builder();
        $this->requestBuilder->method('buildOrigin')->willReturn(self::V1_ORIGIN);
        $this->requestBuilder->method('buildDestinationFromOrder')->willReturn(self::V1_DESTINATION);
        $this->assertNull($builder->buildOrderPayload($this->order([], 0.0)));
    }

    private function creditmemoFor(Order $order): Creditmemo
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);

        return $creditmemo;
    }

    public function testRefundItemsConvertQuantitiesAndFractionalShipping()
    {
        $builder = $this->builder();
        $order = $this->order([], 10.0);
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '0', 'Price' => 45.0, 'Qty' => 2],
                // v1 shipping return: refunded amount as Price, Qty 1. Filed
                // shipping line is $10, so a $4 refund is 0.4 of it.
                ['ItemID' => 'shipping', 'Index' => 1, 'TIC' => '11010', 'Price' => 4.0, 'Qty' => 1],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($order));

        $this->assertFalse($result['skip']);
        $this->assertFalse($result['wasTaxOnlyRefund']);
        $this->assertFalse($result['fullRefund']);
        $this->assertSame([
            ['itemId' => 'sku-1', 'quantity' => 2.0],
            ['itemId' => 'shipping', 'quantity' => 0.4],
        ], $result['items']);
    }

    public function testRefundItemsConvertAdjustmentDistribution()
    {
        $builder = $this->builder();
        $order = $this->order([], 10.0);
        // RefundDistributor output: fractional quantities against effective
        // prices; its shipping entry is (remaining amount, fractional qty).
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '0', 'Price' => 45.0, 'Qty' => 0.0473],
                ['ItemID' => 'shipping', 'Index' => 1, 'TIC' => '11010', 'Price' => 10.0, 'Qty' => 0.0473],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($order));

        $this->assertSame([
            ['itemId' => 'sku-1', 'quantity' => 0.0473],
            // (10.0 × 0.0473) / 10.0 filed = 0.0473 of the filed line.
            ['itemId' => 'shipping', 'quantity' => 0.0473],
        ], $result['items']);
    }

    public function testRefundItemsPassThroughSkip()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [],
            'wasTaxOnlyRefund' => false,
            'skip' => true,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($this->order([])));

        $this->assertTrue($result['skip']);
        $this->assertSame([], $result['items']);
    }

    public function testRefundItemsMarkTaxOnlyAsFullRefund()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [],
            'wasTaxOnlyRefund' => true,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($this->order([])));

        $this->assertTrue($result['wasTaxOnlyRefund']);
        $this->assertTrue($result['fullRefund']);
        $this->assertSame([], $result['items']);
    }

    public function testRefundItemsMarkDistributorFullReturnAsFullRefund()
    {
        $builder = $this->builder();
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($this->order([])));

        $this->assertFalse($result['wasTaxOnlyRefund']);
        $this->assertTrue($result['fullRefund']);
    }

    /**
     * The live 2026-08-07 regression: a configurable credit memo carries the
     * parent row (priced) and its child row (zero-priced), both under the
     * child's SKU. v3 rejects duplicate item references, so they must collapse
     * to ONE entry with the parent's quantity — never doubled.
     */
    public function testRefundItemsCombineConfigurableParentAndChildRows()
    {
        $builder = $this->builder();
        $order = $this->order([], 10.0);
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'test-variant-red', 'Index' => 0, 'TIC' => '0', 'Price' => 10.0, 'Qty' => 1],
                ['ItemID' => 'test-variant-red', 'Index' => 1, 'TIC' => '0', 'Price' => 0.0, 'Qty' => 1],
                ['ItemID' => 'shipping', 'Index' => 2, 'TIC' => '11010', 'Price' => 5.0, 'Qty' => 1],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($order));

        $this->assertSame([
            ['itemId' => 'test-variant-red', 'quantity' => 1.0],
            ['itemId' => 'shipping', 'quantity' => 0.5],
        ], $result['items']);
    }

    public function testRefundItemsSumQuantitiesForDistinctLinesSharingASku()
    {
        $builder = $this->builder();
        $order = $this->order([], 10.0);
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '0', 'Price' => 45.0, 'Qty' => 2],
                ['ItemID' => 'sku-1', 'Index' => 1, 'TIC' => '0', 'Price' => 45.0, 'Qty' => 1],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($order));

        $this->assertSame([['itemId' => 'sku-1', 'quantity' => 3.0]], $result['items']);
    }

    public function testRefundItemsWithOnlyZeroChargeRowsSkipInsteadOfFullRefund()
    {
        // A free item refunded alone must NOT become an empty items list —
        // v3 reads that as "refund the whole order".
        $builder = $this->builder();
        $order = $this->order([], 10.0);
        $this->requestBuilder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'free-gift', 'Index' => 0, 'TIC' => '0', 'Price' => 0.0, 'Qty' => 1],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $builder->buildRefundItems($this->creditmemoFor($order));

        $this->assertTrue($result['skip']);
        $this->assertFalse($result['fullRefund']);
        $this->assertSame([], $result['items']);
    }

    public function testVerifyAddressPayloadUsesV3Shape()
    {
        $builder = $this->builder();

        $this->assertSame(
            [
                'line1' => '100 Main St',
                'city' => 'Bronx',
                'state' => 'NY',
                'zip' => '10451-1234',
                'line2' => 'Suite 5',
            ],
            $builder->buildVerifyAddressPayload(self::V1_DESTINATION)
        );
    }
}
