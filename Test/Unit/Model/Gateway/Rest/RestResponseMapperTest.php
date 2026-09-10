<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponseMapper;

/**
 * v3 response mapping onto the transport-unaware output contracts: lookup tax
 * application, the v1-shaped verified address, and the OrderDetailsResult
 * shape CancellationProcessor consumes.
 */
class RestResponseMapperTest extends TestCase
{
    private function mapper(): RestResponseMapper
    {
        return new RestResponseMapper();
    }

    private function emptyResult(): array
    {
        return [Api::ITEM_TYPE_PRODUCT => [], Api::ITEM_TYPE_SHIPPING => 0];
    }

    public function testApplyCartTaxKeysProductsAndAccumulatesShipping()
    {
        $result = $this->emptyResult();
        $this->mapper()->applyCartTax(
            [
                ['index' => 0, 'itemId' => 'sku-1', 'tax' => ['amount' => 3.83, 'rate' => 0.085]],
                ['index' => 1, 'itemId' => 'sku-2', 'tax' => ['amount' => 1.06, 'rate' => 0.085]],
                ['index' => 2, 'itemId' => 'shipping', 'tax' => ['amount' => 0.51, 'rate' => 0.085]],
            ],
            [0 => 'code-a', 1 => 'code-b'],
            $result
        );

        $this->assertSame(['code-a' => 3.83, 'code-b' => 1.06], $result[Api::ITEM_TYPE_PRODUCT]);
        $this->assertSame(0.51, $result[Api::ITEM_TYPE_SHIPPING]);
    }

    public function testApplyCartTaxSkipsMalformedAndUnknownLines()
    {
        $result = $this->emptyResult();
        $this->mapper()->applyCartTax(
            [
                ['itemId' => 'sku-1'], // no index/tax
                ['index' => 9, 'itemId' => 'sku-9', 'tax' => ['amount' => 5.0]], // unknown index
                'not-an-array',
                ['index' => 0, 'itemId' => 'sku-1', 'tax' => ['amount' => 2.0]],
            ],
            [0 => 'code-a'],
            $result
        );

        $this->assertSame(['code-a' => 2.0], $result[Api::ITEM_TYPE_PRODUCT]);
        $this->assertSame(0, $result[Api::ITEM_TYPE_SHIPPING]);
    }

    public function testExtractCartReturnsFirstCartOrNull()
    {
        $cart = ['cartId' => '77', 'lineItems' => []];
        $this->assertSame($cart, $this->mapper()->extractCart(['items' => [$cart]]));
        $this->assertNull($this->mapper()->extractCart(['items' => []]));
        $this->assertNull($this->mapper()->extractCart(['items' => 'bogus']));
        $this->assertNull($this->mapper()->extractCart(null));
    }

    public function testMapVerifiedAddressSplitsZipAndKeepsContractShape()
    {
        $mapped = $this->mapper()->mapVerifiedAddress([
            'line1' => '100 Main St',
            'line2' => 'Suite 5',
            'city' => 'Bronx',
            'state' => 'NY',
            'zip' => '10451-1234',
        ]);

        $this->assertSame([
            'Address1' => '100 Main St',
            'Address2' => 'Suite 5',
            'City' => 'Bronx',
            'State' => 'NY',
            'Zip5' => '10451',
            'Zip4' => '1234',
        ], $mapped);
    }

    public function testMapVerifiedAddressWithoutZip4OrLine2()
    {
        $mapped = $this->mapper()->mapVerifiedAddress([
            'line1' => '162 East Ave',
            'city' => 'Norwalk',
            'state' => 'CT',
            'zip' => '06851',
        ]);

        $this->assertSame('06851', $mapped['Zip5']);
        $this->assertSame('', $mapped['Zip4']);
        $this->assertSame('', $mapped['Address2']);
    }

    public function testMapVerifiedAddressRejectsUnusableBodies()
    {
        $this->assertFalse($this->mapper()->mapVerifiedAddress(null));
        $this->assertFalse($this->mapper()->mapVerifiedAddress([]));
        $this->assertFalse($this->mapper()->mapVerifiedAddress(['line1' => 'x', 'city' => '', 'state' => 'NY', 'zip' => '1']));
    }

    public function testMapOrderDetailsProducesOrderDetailsResultShape()
    {
        $details = $this->mapper()->mapOrderDetails([
            'orderId' => '100000042',
            'transactionDate' => '2026-08-01T14:30:00Z',
            'completedDate' => '2026-08-02T09:00:00Z',
            'refunds' => [
                ['createdDate' => '2026-08-03T10:00:00Z', 'returnedDate' => '2026-08-03T10:00:00Z'],
                ['createdDate' => '2026-08-05T10:00:00Z'],
            ],
        ]);

        $this->assertSame([
            'ResponseType' => 'OK',
            'OrderID' => '100000042',
            'LookupDate' => '2026-08-01T14:30:00Z',
            'AuthorizedDate' => '2026-08-02T09:00:00Z',
            'CapturedDate' => '2026-08-02T09:00:00Z',
            'ReturnedDate' => '2026-08-05T10:00:00Z',
        ], $details);
    }

    public function testMapOrderDetailsPendingOrderHasNoCapturedDate()
    {
        // A v3 order without completedDate carries no filed liability, so the
        // cancellation gate (CapturedDate non-empty) must not fire a reversal.
        $details = $this->mapper()->mapOrderDetails(['orderId' => '100000042']);

        $this->assertSame('', $details['CapturedDate']);
        $this->assertSame('', $details['ReturnedDate']);
    }

    public function testMapOrderDetailsRejectsUnusableBodies()
    {
        $this->assertNull($this->mapper()->mapOrderDetails(null));
        $this->assertNull($this->mapper()->mapOrderDetails([]));
        $this->assertNull($this->mapper()->mapOrderDetails(['completedDate' => 'x']));
    }
}
