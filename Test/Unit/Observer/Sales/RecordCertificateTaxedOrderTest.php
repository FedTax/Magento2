<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Sales;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Address\TaxAddressResolver;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\OrderCertificateRecord;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Observer\Sales\RecordCertificate;

/**
 * The record is evidence that a sale was untaxed, so it is never written onto
 * an order that carries tax.
 *
 * Resolving at placement says which certificate WOULD exempt the order, not
 * that its totals were computed with it. A lookup that fell back to Magento's
 * rates, or a certificate attached after the last totals calculation, leaves a
 * taxed order behind — and recording the certificate on it showed the
 * TaxCloud Exemption block on an order the customer paid tax on (CXRE-232).
 */
#[AllowMockObjectsWithoutExpectations]
class RecordCertificateTaxedOrderTest extends TestCase
{
    public function testAnUntaxedOrderRecordsItsCertificate()
    {
        $certificate = new Certificate('cert-9', '42', ['NY']);
        $record = $this->createMock(OrderCertificateRecord::class);
        $order = $this->order(0.0, 0.0);
        $record->expects($this->once())->method('record')->with($order, $certificate);

        $logger = $this->createMock(GatewayLogger::class);
        $logger->expects($this->never())->method('warning');

        $this->observer($certificate, $record, $logger)->execute($this->observerFor($order));
    }

    /**
     * @dataProvider taxedOrderProvider
     */
    #[DataProvider('taxedOrderProvider')]
    public function testATaxedOrderRecordsNoCertificateAndWarns(float $taxAmount, float $baseTaxAmount, string $logged)
    {
        $record = $this->createMock(OrderCertificateRecord::class);
        $record->expects($this->never())->method('record');

        $warnings = [];
        $logger = $this->createMock(GatewayLogger::class);
        $logger->method('warning')->willReturnCallback(function ($message) use (&$warnings) {
            $warnings[] = $message;
        });

        $this->observer(new Certificate('cert-9', '42', ['NY']), $record, $logger)
            ->execute($this->observerFor($this->order($taxAmount, $baseTaxAmount)));

        $this->assertSame(
            [
                'Not recording certificate cert-9 on order 3000022065: the order carries tax of '
                . $logged . ', so it was not exempted',
            ],
            $warnings
        );
    }

    public static function taxedOrderProvider(): array
    {
        return [
            'taxed in both currencies' => [8.25, 8.25, '8.25'],
            'taxed in base currency only' => [0.0, 0.01, '0.01'],
        ];
    }

    /**
     * Float noise left by totals arithmetic is not tax.
     */
    public function testRoundingNoiseIsNotTax()
    {
        $record = $this->createMock(OrderCertificateRecord::class);
        $record->expects($this->once())->method('record');

        $this->observer(
            new Certificate('cert-9', '42', ['NY']),
            $record,
            $this->createMock(GatewayLogger::class)
        )->execute($this->observerFor($this->order(0.00001, 0.0)));
    }

    public function testNoCertificateMeansNoRecordAndNoWarning()
    {
        $record = $this->createMock(OrderCertificateRecord::class);
        $record->expects($this->never())->method('record');

        $logger = $this->createMock(GatewayLogger::class);
        $logger->expects($this->never())->method('warning');

        $this->observer(null, $record, $logger)->execute($this->observerFor($this->order(8.25, 8.25)));
    }

    private function observer(
        ?Certificate $resolved,
        OrderCertificateRecord $record,
        GatewayLogger $logger
    ): RecordCertificate {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $resolver = $this->createMock(CertificateResolver::class);
        $resolver->method('resolve')->willReturn($resolved);

        return new RecordCertificate($resolver, $record, $config, $logger, new TaxAddressResolver());
    }

    private function order(float $taxAmount, float $baseTaxAmount): Order
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getRegionCode')->willReturn('NY');
        $address->method('getCountryId')->willReturn('US');

        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getIncrementId')->willReturn('3000022065');
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getBillingAddress')->willReturn($address);
        $order->method('getTaxAmount')->willReturn($taxAmount);
        $order->method('getBaseTaxAmount')->willReturn($baseTaxAmount);

        return $order;
    }

    /**
     * Real Event and Observer instances, not mocks: both are light data
     * carriers, and a mocked getData() would have to model the exact argument
     * arity PHPUnit records — which differs between the versions this suite
     * runs on.
     */
    private function observerFor(Order $order): Observer
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        return new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]);
    }
}
