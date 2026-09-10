<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Address\TaxAddressResolver;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;

/**
 * The v3 order payload for an order that ships nothing.
 *
 * RestRequestBuilderTest mocks RequestBuilder, so its destination is whatever
 * the test says it is — which cannot show that a real order resolves one. This
 * file wires the REAL request builder behind the REST one, because the whole
 * defect lived in the seam between them: buildDestinationFromOrder() returned
 * null for a shipping-less order, buildOrderPayload() correctly refused to
 * fabricate an address, and the capture reported failure. On a REST-selected
 * store that meant a digital order was taxed, paid for, and never filed.
 */
#[AllowMockObjectsWithoutExpectations]
class VirtualOrderPayloadTest extends TestCase
{
    /**
     * @var TaxcloudConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfig;

    private function restBuilder(): RestRequestBuilder
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('getGuestCustomerId')->willReturn('-1');

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['shipping/origin/postcode', ScopeInterface::SCOPE_STORE, 3, '06851'],
            ['shipping/origin/street_line1', ScopeInterface::SCOPE_STORE, 3, '162 East Ave'],
            ['shipping/origin/street_line2', ScopeInterface::SCOPE_STORE, 3, ''],
            ['shipping/origin/city', ScopeInterface::SCOPE_STORE, 3, 'Norwalk'],
            ['shipping/origin/region_id', ScopeInterface::SCOPE_STORE, 3, null],
        ]);

        $ticService = $this->createMock(ProductTicService::class);
        $ticService->method('getProductTic')->willReturn('31000');
        $ticService->method('getShippingTic')->willReturn('11010');

        $requestBuilder = new RequestBuilder(
            $this->config,
            $this->scopeConfig,
            $this->createMock(RegionFactory::class),
            $ticService,
            $this->createMock(RefundDistributor::class),
            $this->createMock(FeeService::class),
            new NullLogger(),
            new TaxAddressResolver()
        );

        return new RestRequestBuilder($this->config, $requestBuilder, $ticService);
    }

    /**
     * @param OrderAddress|false $shipping
     */
    private function digitalOrder($shipping = false): Order
    {
        $billing = $this->createMock(OrderAddress::class);
        $billing->method('getPostcode')->willReturn('78701');
        $billing->method('getCountryId')->willReturn('US');
        $billing->method('getStreet')->willReturn(['1401 Lavaca St']);
        $billing->method('getCity')->willReturn('Austin');
        $billing->method('getRegionCode')->willReturn('TX');

        $item = $this->createMock(OrderItem::class);
        $item->method('getSku')->willReturn('ebook-1');
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getPrice')->willReturn(20.0);
        $item->method('getDiscountAmount')->willReturn(0.0);
        $item->method('getTaxAmount')->willReturn(1.65);
        $item->method('getTaxPercent')->willReturn(8.25);

        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getCreatedAt')->willReturn('2026-08-01 14:30:00');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllVisibleItems')->willReturn([$item]);
        $order->method('getShippingAmount')->willReturn(0.0);
        $order->method('getShippingTaxAmount')->willReturn(0.0);
        $order->method('getShippingAddress')->willReturn($shipping);
        $order->method('getBillingAddress')->willReturn($billing);

        return $order;
    }

    public function testAnOrderWithNoShippingAddressStillFiles()
    {
        $payload = $this->restBuilder()->buildOrderPayload($this->digitalOrder());

        $this->assertIsArray(
            $payload,
            'A digital-only order must produce a v3 order; refusing to build one leaves the sale unfiled.'
        );
        $this->assertSame('Austin', $payload['destination']['city']);
        $this->assertSame('78701', $payload['destination']['zip']);
        $this->assertSame('100000042', $payload['orderId']);
        $this->assertCount(1, $payload['lineItems'], 'A digital order files its lines and no shipping line.');
        $this->assertSame(1.65, $payload['lineItems'][0]['tax']['amount']);
    }

    /**
     * The fallback must not reach past a delivery address that does exist.
     */
    public function testAnOrderWithAShippingAddressFilesAgainstIt()
    {
        $shipping = $this->createMock(OrderAddress::class);
        $shipping->method('getPostcode')->willReturn('80202');
        $shipping->method('getCountryId')->willReturn('US');
        $shipping->method('getStreet')->willReturn(['1701 Broadway']);
        $shipping->method('getCity')->willReturn('Denver');
        $shipping->method('getRegionCode')->willReturn('CO');

        $payload = $this->restBuilder()->buildOrderPayload($this->digitalOrder($shipping));

        $this->assertSame('Denver', $payload['destination']['city']);
    }
}
