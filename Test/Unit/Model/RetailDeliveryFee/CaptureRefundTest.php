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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * The fee line on capture-side and refund-side payloads, both transports.
 *
 * Every amount here comes from the ORDER's stored fee, never config: the
 * rate changes each July 1, and what TaxCloud files or reverses must be what
 * the customer was charged.
 */
#[AllowMockObjectsWithoutExpectations]
class CaptureRefundTest extends TestCase
{
    private const STORE_ID = 3;

    /** @var FeeService|\PHPUnit\Framework\MockObject\MockObject */
    private $feeService;

    /** @var RequestBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->feeService = $this->createMock(FeeService::class);
        $this->feeService->method('getTic')->willReturn('11098');

        $ticService = $this->createMock(ProductTicService::class);
        $ticService->method('getProductTic')->willReturn('00000');
        $ticService->method('getShippingTic')->willReturn('11010');

        $this->builder = new RequestBuilder(
            $this->createMock(TaxcloudConfig::class),
            $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createMock(\Magento\Directory\Model\RegionFactory::class),
            $ticService,
            $this->createMock(RefundDistributor::class),
            $this->feeService,
            new NullLogger()
        );
    }

    public function testV1OrderCartCarriesTheStoredFeeLine()
    {
        $order = $this->order(0.31);

        $cartItems = $this->builder->buildCartItemsFromOrder($order);

        $fee = end($cartItems);
        $this->assertSame(FeeService::ITEM_ID, $fee['ItemID']);
        $this->assertSame('11098', $fee['TIC']);
        $this->assertSame(0.31, $fee['Price']);
        $this->assertSame(1, $fee['Qty']);
        // Indexes stay contiguous with the fee appended last.
        $this->assertSame(range(0, count($cartItems) - 1), array_column($cartItems, 'Index'));
    }

    public function testV1OrderCartWithoutFeeHasNoFeeLine()
    {
        $order = $this->order(0.0);

        $cartItems = $this->builder->buildCartItemsFromOrder($order);

        $this->assertNotContains(FeeService::ITEM_ID, array_column($cartItems, 'ItemID'));
    }

    public function testV3OrderPayloadFilesTheStoredFeeZeroRated()
    {
        $restBuilder = $this->restBuilder();

        $payload = $restBuilder->buildOrderPayload($this->v3Order(0.31));

        $fee = null;
        foreach ($payload['lineItems'] as $line) {
            if ($line['itemId'] === FeeService::ITEM_ID) {
                $fee = $line;
            }
        }
        $this->assertNotNull($fee);
        $this->assertSame(11098, $fee['tic']);
        $this->assertSame(0.31, $fee['price']);
        $this->assertSame(['amount' => 0.0, 'rate' => 0.0], $fee['tax']);
    }

    public function testV3OrderPayloadWithoutFeeHasNoFeeLine()
    {
        $restBuilder = $this->restBuilder();

        $payload = $restBuilder->buildOrderPayload($this->v3Order(0.0));

        $this->assertNotContains(FeeService::ITEM_ID, array_column($payload['lineItems'], 'itemId'));
    }

    public function testReturnedFeeLineAppendedToNonEmptyCartOnly()
    {
        $memo = $this->memoWithFee(0.31);
        $cart = [['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '00000', 'Price' => 50.0, 'Qty' => 1]];

        $appended = $this->builder->appendReturnedRdfLine($cart, $memo);
        $this->assertSame(FeeService::ITEM_ID, end($appended)['ItemID']);
        $this->assertSame(0.31, end($appended)['Price']);

        // The empty "return the remainder" form travels via the flag instead.
        $this->assertSame([], $this->builder->appendReturnedRdfLine([], $memo));

        // A memo that does not carry the fee (partial return) appends nothing.
        $this->assertSame($cart, $this->builder->appendReturnedRdfLine($cart, $this->memoWithFee(0.0)));
    }

    public function testV3PartialFullReturnReferencesTheFeeItem()
    {
        $restBuilder = $this->restBuilder();
        $this->v1Builder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '0', 'Price' => 50.0, 'Qty' => 1],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $restBuilder->buildRefundItems($this->memoWithFee(0.31, $this->v3Order(0.31)));

        $this->assertContains(['itemId' => FeeService::ITEM_ID, 'quantity' => 1.0], $result['items']);
    }

    public function testV3FullRefundSendsEmptyItemsWithoutFeeReference()
    {
        $restBuilder = $this->restBuilder();
        $this->v1Builder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $restBuilder->buildRefundItems($this->memoWithFee(0.31, $this->v3Order(0.31)));

        $this->assertTrue($result['fullRefund']);
        $this->assertSame([], $result['items'], 'empty items already reverse every filed line, fee included');
    }

    public function testV3MemoWithoutFeeAddsNoFeeReference()
    {
        $restBuilder = $this->restBuilder();
        $this->v1Builder->method('buildReturnCartItems')->willReturn([
            'cartItems' => [
                ['ItemID' => 'sku-1', 'Index' => 0, 'TIC' => '0', 'Price' => 50.0, 'Qty' => 1],
            ],
            'wasTaxOnlyRefund' => false,
            'skip' => false,
        ]);

        $result = $restBuilder->buildRefundItems($this->memoWithFee(0.0, $this->v3Order(0.31)));

        $this->assertNotContains(FeeService::ITEM_ID, array_column($result['items'], 'itemId'));
    }

    /** @var RequestBuilder|\PHPUnit\Framework\MockObject\MockObject */
    private $v1Builder;

    private function restBuilder(): RestRequestBuilder
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('getCoRdfTic')->willReturn('11098');
        $config->method('getGuestCustomerId')->willReturn('-1');

        $this->v1Builder = $this->createMock(RequestBuilder::class);
        $this->v1Builder->method('buildOrigin')->willReturn(
            ['Address1' => '1401 Lavaca St', 'Address2' => '', 'City' => 'Austin', 'State' => 'TX', 'Zip5' => '78701', 'Zip4' => '']
        );
        $this->v1Builder->method('buildDestinationFromOrder')->willReturn(
            ['Address1' => '1600 Broadway', 'Address2' => '', 'City' => 'Denver', 'State' => 'CO', 'Zip5' => '80202', 'Zip4' => '']
        );

        $ticService = $this->createMock(ProductTicService::class);
        $ticService->method('getProductTic')->willReturn('00000');
        $ticService->method('getShippingTic')->willReturn('11010');

        return new RestRequestBuilder($config, $this->v1Builder, $ticService);
    }

    /**
     * Order with one $50 item, $10 shipping, and the given stored fee (v1 side).
     *
     * @param float $rdfAmount
     * @return Dbl\OrderDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function order(float $rdfAmount)
    {
        $item = new Dbl\OrderItemDouble();
        $item->setSku('sku-1');
        $item->setQtyOrdered(1.0);
        $item->setPrice(50.0);
        $item->setDiscountAmount(0.0);

        $order = $this->getMockBuilder(Dbl\OrderDouble::class)
            ->onlyMethods(['getStoreId', 'getAllVisibleItems', 'getBaseShippingAmount', 'getBaseTaxcloudRdfAmount'])
            ->getMock();
        $order->method('getStoreId')->willReturn(self::STORE_ID);
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getBaseShippingAmount')->willReturn(10.0);
        $order->method('getBaseTaxcloudRdfAmount')->willReturn($rdfAmount);

        return $order;
    }

    /**
     * Order shaped for the v3 order payload builder.
     *
     * @param float $rdfAmount
     * @return Dbl\OrderDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function v3Order(float $rdfAmount)
    {
        $item = $this->getMockBuilder(Dbl\OrderItemDouble::class)
            ->onlyMethods(['getSku', 'getQtyOrdered', 'getPrice', 'getDiscountAmount', 'getTaxAmount', 'getTaxPercent'])
            ->getMock();
        $item->method('getSku')->willReturn('sku-1');
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getPrice')->willReturn(50.0);
        $item->method('getDiscountAmount')->willReturn(0.0);
        $item->method('getTaxAmount')->willReturn(4.58);
        $item->method('getTaxPercent')->willReturn(9.15);

        $order = $this->getMockBuilder(Dbl\OrderDouble::class)
            ->onlyMethods([
                'getStoreId',
                'getIncrementId',
                'getCustomerId',
                'getCreatedAt',
                'getOrderCurrencyCode',
                'getAllVisibleItems',
                'getShippingAmount',
                'getShippingTaxAmount',
                'getBaseTaxcloudRdfAmount',
            ])
            ->getMock();
        $order->method('getStoreId')->willReturn(self::STORE_ID);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getCreatedAt')->willReturn('2026-08-01 14:30:00');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getShippingAmount')->willReturn(10.0);
        $order->method('getShippingTaxAmount')->willReturn(0.0);
        $order->method('getBaseTaxcloudRdfAmount')->willReturn($rdfAmount);

        return $order;
    }

    /**
     * @param float $rdfAmount
     * @param mixed $order
     * @return Dbl\CreditmemoDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function memoWithFee(float $rdfAmount, $order = null)
    {
        $memo = $this->getMockBuilder(Dbl\CreditmemoDouble::class)
            ->onlyMethods(['getOrder', 'getBaseTaxcloudRdfAmount'])
            ->getMock();
        $memo->method('getOrder')->willReturn($order ?? $this->order($rdfAmount));
        $memo->method('getBaseTaxcloudRdfAmount')->willReturn($rdfAmount);

        return $memo;
    }
}
