<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\OrderRule\SubjectReader;

#[AllowMockObjectsWithoutExpectations]
class MatchSubjectTest extends TestCase
{
    private function quote(bool $virtual, ?string $payment, ?string $shipping, $group = '1'): Quote
    {
        $paymentModel = $this->createMock(Payment::class);
        $paymentModel->method('getMethod')->willReturn($payment);
        $address = $this->createMock(Address::class);
        $address->method('getShippingMethod')->willReturn($shipping);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(4);
        $quote->method('getCustomerGroupId')->willReturn($group);
        $quote->method('getPayment')->willReturn($paymentModel);
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getShippingAddress')->willReturn($address);
        return $quote;
    }

    public function testFromQuoteHasNoOrderNumber()
    {
        $subject = (new SubjectReader())->fromQuote($this->quote(false, 'checkmo', 'flatrate_flatrate'));

        $this->assertSame(4, $subject->getStoreId());
        $this->assertSame(1, $subject->getCustomerGroupId());
        $this->assertSame('checkmo', $subject->getPaymentMethod());
        $this->assertSame('flatrate_flatrate', $subject->getShippingMethod());
        $this->assertNull($subject->getOrderNumber());
    }

    /**
     * Before the customer chooses, payment and shipping are unknown, not "".
     */
    public function testUnchosenMethodsAreNull()
    {
        $subject = (new SubjectReader())->fromQuote($this->quote(false, '', ''));

        $this->assertNull($subject->getPaymentMethod());
        $this->assertNull($subject->getShippingMethod());
    }

    public function testAVirtualQuoteHasNoShippingMethod()
    {
        $subject = (new SubjectReader())->fromQuote($this->quote(true, 'checkmo', 'flatrate_flatrate'));

        $this->assertNull($subject->getShippingMethod());
    }

    public function testGuestGroupZeroIsKept()
    {
        $this->assertSame(0, (new SubjectReader())->fromQuote($this->quote(false, null, null, '0'))->getCustomerGroupId());
    }

    public function testFromOrderCarriesEverything()
    {
        $payment = $this->createMock(OrderPayment::class);
        $payment->method('getMethod')->willReturn('m2epropayment');
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(2);
        $order->method('getCustomerGroupId')->willReturn(3);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIsVirtual')->willReturn(false);
        $order->method('getShippingMethod')->willReturn('m2eproshipping_m2eproshipping');
        $order->method('getIncrementId')->willReturn('AMZ-1');

        $subject = (new SubjectReader())->fromOrder($order);

        $this->assertSame(
            '2|3|m2epropayment|m2eproshipping_m2eproshipping|AMZ-1',
            $subject->fingerprint()
        );
    }

    public function testFingerprintChangesWithThePaymentMethod()
    {
        $a = (new SubjectReader())->fromQuote($this->quote(false, 'checkmo', null));
        $b = (new SubjectReader())->fromQuote($this->quote(false, 'paypal_express', null));

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }
}
