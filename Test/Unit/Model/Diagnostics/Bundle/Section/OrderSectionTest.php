<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Section;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleScope;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\OrderSection;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\DiagnosticsFixture;

/**
 * order.json answers "why was the tax on this order what it was": per-line TIC
 * with its source, RDF state, and the order's own store configuration.
 */
#[AllowMockObjectsWithoutExpectations]
class OrderSectionTest extends TestCase
{
    use DiagnosticsFixture;

    private function order(): Order
    {
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn('SVC-1');
        $item->method('getProductType')->willReturn('virtual');
        $item->method('getQtyOrdered')->willReturn(2);
        $item->method('getTaxAmount')->willReturn(1.5);

        $address = $this->createMock(Address::class);
        $address->method('getFirstname')->willReturn('Jane');
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('Denver');
        $address->method('getRegionCode')->willReturn('CO');
        $address->method('getPostcode')->willReturn('80202');
        $address->method('getTelephone')->willReturn('555-0100');

        $store = $this->createMock(Store::class);
        $store->method('getCode')->willReturn('us_es');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getStoreId')->willReturn(2);
        $order->method('getStore')->willReturn($store);
        $order->method('getAllItems')->willReturn([$item]);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getShippingMethod')->willReturn('flatrate_flatrate');
        $order->method('getCustomerEmail')->willReturn('jane@example.com');
        $order->method('getInvoiceCollection')->willReturn([]);
        $order->method('getCreditmemosCollection')->willReturn([]);
        $order->method('getShipmentsCollection')->willReturn([]);
        $order->method('getData')->willReturnCallback(function ($key = '') {
            return ['taxcloud_rdf_amount' => '0.2800', 'taxcloud_captured' => '1'][$key] ?? null;
        });

        return $order;
    }

    private function collect(bool $redact, ?array $logs = null): array
    {
        $this->setConfig(
            ['tax/taxcloud_settings/co_rdf_enabled' => '0'],
            [],
            ['us_es' => ['tax/taxcloud_settings/co_rdf_enabled' => '1',
                'tax/taxcloud_settings/co_rdf_delivery_methods' => 'flatrate_flatrate,tablerate_bestway']]
        );
        $tics = $this->createMock(ProductTicService::class);
        $tics->expects($this->once())->method('resolveTic')
            ->with($this->anything(), 'diagnostics', 2)
            ->willReturn(['tic' => '91000', 'source' => ProductTicService::SOURCE_CATEGORY]);

        $section = new OrderSection($tics, new TaxcloudConfig($this->scopeConfig()));
        $context = new BundleContext(
            new BundleRequest(),
            new BundleScope(BundleRequest::SCOPE_STORE, 2, 'us_es', [], []),
            $this->order(),
            sys_get_temp_dir(),
            [],
            $redact ? new PiiRedactor() : null
        );
        if ($logs !== null) {
            $context->setSectionData('logs', $logs);
        }

        return $section->collect($context, $this->createMock(BundleArchive::class));
    }

    public function testResolvesAgainstTheOrdersStore()
    {
        $data = $this->collect(false, ['order_correlation' => [
            'correlated_records' => 4,
            'tax_source_evidence' => ['taxcloud_lookup_records' => 1, 'magento_fallback_records' => 0],
        ]]);

        $this->assertSame('91000', $data['items']['lines'][0]['tic']);
        $this->assertSame('category', $data['items']['lines'][0]['tic_source']);
        $rdf = $data['taxcloud']['colorado_rdf'];
        $this->assertTrue($rdf['applied']);
        $this->assertTrue($rdf['collection_enabled_for_store'], 'the store override, not the default');
        $this->assertTrue($rdf['shipping_method_in_motor_vehicle_list']);
        $this->assertSame('taxcloud', $data['taxcloud']['tax_source']['determination']);
        $this->assertSame('Jane', $data['shipping_address']['firstname']);
    }

    public function testMasksCustomerDetailsButKeepsTaxInputs()
    {
        $data = $this->collect(true);

        $this->assertSame(PiiRedactor::MARKER, $data['shipping_address']['firstname']);
        $this->assertSame(PiiRedactor::MARKER, $data['shipping_address']['street']);
        $this->assertSame(PiiRedactor::MARKER, $data['shipping_address']['telephone']);
        $this->assertSame(PiiRedactor::MARKER, $data['customer']['email']);
        $this->assertSame('Denver', $data['shipping_address']['city']);
        $this->assertSame('CO', $data['shipping_address']['region_code']);
        $this->assertSame('80202', $data['shipping_address']['postcode']);
        $this->assertSame('91000', $data['items']['lines'][0]['tic']);
        $this->assertSame('unknown', $data['taxcloud']['tax_source']['determination']);
    }
}
