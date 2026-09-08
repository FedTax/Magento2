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
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\OrderCertificateRecord;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Observer\Sales\RecordCertificate;

/**
 * Which state a certificate has to cover.
 *
 * An exemption is granted state by state, so the state matched against must be
 * the state the sale is sourced to — otherwise a customer exempt where the
 * order is taxed is charged anyway, or one exempt somewhere else is not. For a
 * digital-only order that state comes from the billing address, because there
 * is no other address on the order to take it from.
 */
#[AllowMockObjectsWithoutExpectations]
class RecordCertificateDestinationStateTest extends TestCase
{
    /**
     * @dataProvider destinationStateProvider
     */
    #[DataProvider('destinationStateProvider')]
    public function testTheCertificateIsMatchedAgainstTheSourcedState(
        bool $hasShippingAddress,
        string $expectedState,
        string $message
    ) {
        $shipping = $this->address('CO');
        $billing = $this->address('TX');

        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getShippingAddress')->willReturn($hasShippingAddress ? $shipping : false);
        $order->method('getBillingAddress')->willReturn($billing);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $sourcedState = null;
        $resolver = $this->createMock(CertificateResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->willReturnCallback(
                function ($resolvedCustomer, $state, $store) use (&$sourcedState) {
                    $sourcedState = $state;

                    return null;
                }
            );

        $observer = new RecordCertificate(
            $resolver,
            $this->createMock(OrderCertificateRecord::class),
            $config,
            $this->createMock(GatewayLogger::class),
            new TaxAddressResolver()
        );

        $observer->execute($this->observerFor($order, $quote));

        $this->assertSame($expectedState, $sourcedState, $message);
    }

    public static function destinationStateProvider(): array
    {
        return [
            'physical order' => [
                true,
                'CO',
                'A delivered order is exempt (or not) where it is delivered.',
            ],
            'digital-only order' => [
                false,
                'TX',
                'A digital order has no delivery state; the billing state is the one it is taxed in.',
            ],
        ];
    }

    /**
     * An order with no usable address records nothing rather than querying
     * TaxCloud under an empty state.
     */
    public function testNoAddressMeansNoCertificateLookup()
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getShippingAddress')->willReturn(false);
        $order->method('getBillingAddress')->willReturn(false);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $resolver = $this->createMock(CertificateResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $record = $this->createMock(OrderCertificateRecord::class);
        $record->expects($this->never())->method('record');

        $observer = new RecordCertificate(
            $resolver,
            $record,
            $config,
            $this->createMock(GatewayLogger::class),
            new TaxAddressResolver()
        );

        $observer->execute($this->observerFor($order, $quote));

        $this->addToAssertionCount(1); // both `never` expectations above are the assertions
    }

    private function address(string $regionCode): OrderAddress
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getRegionCode')->willReturn($regionCode);

        return $address;
    }

    /**
     * Real Event and Observer instances, not mocks: both are light data
     * carriers, and a mocked getData() would have to model the exact argument
     * arity PHPUnit records — which differs between the versions this suite
     * runs on.
     */
    private function observerFor(Order $order, Quote $quote): Observer
    {
        return new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]);
    }
}
