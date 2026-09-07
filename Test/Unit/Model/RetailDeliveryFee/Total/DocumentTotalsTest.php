<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\RetailDeliveryFee\Total;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\RetailDeliveryFee\Total\Creditmemo\RetailDeliveryFee as CreditmemoTotal;
use Taxcloud\Magento2\Model\RetailDeliveryFee\Total\Invoice\RetailDeliveryFee as InvoiceTotal;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * Invoice and credit-memo fee totals.
 *
 * The invoice settles the fee whole on the first invoice (per-delivery fee,
 * whole-order capture). The credit memo carries it back only on a full
 * return — the memo's persisted amount is the single signal the TaxCloud
 * refund path reads, so these totals ARE the refund policy.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentTotalsTest extends TestCase
{
    public function testFirstInvoiceSettlesTheFeeWhole()
    {
        $order = $this->order(0.31, []);
        $invoice = $this->invoice($order, 100.00);

        (new InvoiceTotal())->collect($invoice);

        $this->assertSame(0.31, (float) $invoice->getTaxcloudRdfAmount());
        $this->assertSame(0.31, (float) $invoice->getBaseTaxcloudRdfAmount());
        $this->assertSame(100.31, (float) $invoice->getGrandTotal());
        $this->assertSame(100.31, (float) $invoice->getBaseGrandTotal());
    }

    public function testLaterInvoiceContributesNothingOnceSettled()
    {
        $previous = new Dbl\InvoiceDouble();
        $previous->setId(11);
        $previous->setTaxcloudRdfAmount(0.31);

        $order = $this->order(0.31, [$previous]);
        $invoice = $this->invoice($order, 50.00);

        (new InvoiceTotal())->collect($invoice);

        $this->assertNull($invoice->getTaxcloudRdfAmount());
        $this->assertSame(50.00, (float) $invoice->getGrandTotal());
    }

    public function testOrderWithoutFeeLeavesInvoiceUntouched()
    {
        $order = $this->order(0.0, []);
        $invoice = $this->invoice($order, 50.00);

        (new InvoiceTotal())->collect($invoice);

        $this->assertNull($invoice->getTaxcloudRdfAmount());
        $this->assertSame(50.00, (float) $invoice->getGrandTotal());
    }

    public function testFullReturnCreditsTheFee()
    {
        $order = $this->orderWithItems(0.31, [
            $this->orderItem(1, 2.0, 0.0),
        ]);
        $memo = $this->creditmemo($order, 100.00, [$this->memoItem(1, 2.0)]);

        (new CreditmemoTotal())->collect($memo);

        $this->assertSame(0.31, (float) $memo->getTaxcloudRdfAmount());
        $this->assertSame(0.31, (float) $memo->getBaseTaxcloudRdfAmount());
        $this->assertSame(100.31, (float) $memo->getGrandTotal());
    }

    public function testPartialReturnDoesNotCreditTheFee()
    {
        $order = $this->orderWithItems(0.31, [
            $this->orderItem(1, 2.0, 0.0),
        ]);
        $memo = $this->creditmemo($order, 50.00, [$this->memoItem(1, 1.0)]);

        (new CreditmemoTotal())->collect($memo);

        $this->assertNull($memo->getTaxcloudRdfAmount());
        $this->assertSame(50.00, (float) $memo->getGrandTotal());
    }

    public function testSecondMemoCompletingTheReturnCreditsTheFee()
    {
        $order = $this->orderWithItems(0.31, [
            $this->orderItem(1, 2.0, 1.0),
        ]);
        $memo = $this->creditmemo($order, 50.00, [$this->memoItem(1, 1.0)]);

        (new CreditmemoTotal())->collect($memo);

        $this->assertSame(0.31, (float) $memo->getTaxcloudRdfAmount());
    }

    public function testItemlessMemoOnFullyRefundedOrderCreditsTheFee()
    {
        $order = $this->orderWithItems(0.31, [
            $this->orderItem(1, 2.0, 2.0),
        ]);
        $memo = $this->creditmemo($order, 0.00, []);

        (new CreditmemoTotal())->collect($memo);

        $this->assertSame(0.31, (float) $memo->getTaxcloudRdfAmount());
    }

    public function testItemlessMemoWithUnitsRemainingDoesNotCreditTheFee()
    {
        $order = $this->orderWithItems(0.31, [
            $this->orderItem(1, 2.0, 0.0),
        ]);
        $memo = $this->creditmemo($order, 0.00, []);

        (new CreditmemoTotal())->collect($memo);

        $this->assertNull($memo->getTaxcloudRdfAmount());
    }

    public function testFeeIsNeverRefundedTwice()
    {
        $previous = new Dbl\CreditmemoDouble();
        $previous->setId(21);
        $previous->setTaxcloudRdfAmount(0.31);

        $order = $this->orderWithItems(0.31, [
            $this->orderItem(1, 2.0, 2.0),
        ], [$previous]);
        $memo = $this->creditmemo($order, 0.00, []);

        (new CreditmemoTotal())->collect($memo);

        $this->assertNull($memo->getTaxcloudRdfAmount());
    }

    /**
     * @param float $rdfAmount
     * @param array $invoices
     * @return Dbl\OrderDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function order(float $rdfAmount, array $invoices)
    {
        $order = $this->getMockBuilder(Dbl\OrderDouble::class)
            ->onlyMethods([
                'getTaxcloudRdfAmount',
                'getBaseTaxcloudRdfAmount',
                'getInvoiceCollection',
                'getCreditmemosCollection',
                'getAllItems',
            ])
            ->getMock();
        $order->method('getTaxcloudRdfAmount')->willReturn($rdfAmount);
        $order->method('getBaseTaxcloudRdfAmount')->willReturn($rdfAmount);
        $order->method('getInvoiceCollection')->willReturn($invoices);
        $order->method('getCreditmemosCollection')->willReturn([]);
        $order->method('getAllItems')->willReturn([]);

        return $order;
    }

    /**
     * @param float $rdfAmount
     * @param array $items
     * @return Dbl\OrderDouble|\PHPUnit\Framework\MockObject\MockObject
     */
    private function orderWithItems(float $rdfAmount, array $items, array $creditmemos = [])
    {
        $order = $this->getMockBuilder(Dbl\OrderDouble::class)
            ->onlyMethods([
                'getTaxcloudRdfAmount',
                'getBaseTaxcloudRdfAmount',
                'getInvoiceCollection',
                'getCreditmemosCollection',
                'getAllItems',
            ])
            ->getMock();
        $order->method('getTaxcloudRdfAmount')->willReturn($rdfAmount);
        $order->method('getBaseTaxcloudRdfAmount')->willReturn($rdfAmount);
        $order->method('getInvoiceCollection')->willReturn([]);
        $order->method('getCreditmemosCollection')->willReturn($creditmemos);
        $order->method('getAllItems')->willReturn($items);

        return $order;
    }

    private function invoice($order, float $grandTotal): Dbl\InvoiceDouble
    {
        $invoice = $this->getMockBuilder(Dbl\InvoiceDouble::class)
            ->onlyMethods(['getOrder'])
            ->getMock();
        $invoice->method('getOrder')->willReturn($order);
        $invoice->setGrandTotal($grandTotal);
        $invoice->setBaseGrandTotal($grandTotal);

        return $invoice;
    }

    private function creditmemo($order, float $grandTotal, array $items): Dbl\CreditmemoDouble
    {
        $memo = $this->getMockBuilder(Dbl\CreditmemoDouble::class)
            ->onlyMethods(['getOrder', 'getAllItems'])
            ->getMock();
        $memo->method('getOrder')->willReturn($order);
        $memo->method('getAllItems')->willReturn($items);
        $memo->setGrandTotal($grandTotal);
        $memo->setBaseGrandTotal($grandTotal);

        return $memo;
    }

    private function orderItem(int $id, float $ordered, float $refunded): Dbl\OrderItemDouble
    {
        $item = new Dbl\OrderItemDouble();
        $item->setId($id);
        $item->setQtyOrdered($ordered);
        $item->setQtyRefunded($refunded);

        return $item;
    }

    private function memoItem(int $orderItemId, float $qty): Dbl\CreditmemoItemDouble
    {
        $item = new Dbl\CreditmemoItemDouble();
        $item->setOrderItemId($orderItemId);
        $item->setQty($qty);

        return $item;
    }
}
