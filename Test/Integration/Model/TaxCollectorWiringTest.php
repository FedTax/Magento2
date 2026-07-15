<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model;

use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that Taxcloud\Magento2\Model\Tax::collect() is actually invoked by
 * Magento's tax total-collector pipeline when a quote's totals are collected —
 * and that the TaxCloud lookup result flows into the quote item's tax and the
 * grand total.
 *
 * A unit test can only call collect() directly; only an integration test proves
 * Magento wires our collector (the di.xml preference for
 * Magento\Tax\Model\Sales\Total\Quote\Tax) into the real pipeline.
 */
class TaxCollectorWiringTest extends IntegrationTestCase
{
    /** Tax the mocked TaxCloud lookup attributes to the single product line. */
    private const MOCK_PRODUCT_TAX = 0.83;

    protected function setUp(): void
    {
        parent::setUp();

        $soap = $this->installSoapMock();
        // Override the default (empty) lookup with a known per-line tax amount.
        // CartItemIndex 0 is the first/only product line; the handler maps its
        // TaxAmount onto that quote item.
        $soap->setResponse('lookup', [
            'LookupResult' => [
                'ResponseType' => 'OK',
                'Messages'     => '',
                'CartItemsResponse' => [
                    'CartItemResponse' => [
                        ['CartItemIndex' => 0, 'TaxAmount' => self::MOCK_PRODUCT_TAX],
                    ],
                ],
            ],
        ]);
    }

    public function testTaxcloudCollectorRunsDuringQuoteTotalsCalculation(): void
    {
        $soap = $this->soapClient();

        $quote = $this->buildQuoteWithTestProduct(1);

        $this->assertGreaterThanOrEqual(
            1,
            $soap->callCount('lookup'),
            'Collecting quote totals should drive Tax::collect() -> Api::lookupTaxes() -> the '
            . 'lookup SOAP call. If this is 0, our collector was not wired into Magento\'s pipeline.'
        );

        $items = $quote->getAllVisibleItems();
        $this->assertCount(1, $items, 'Expected exactly one product line on the quote.');
        $item = $items[0];

        $this->assertEqualsWithDelta(
            self::MOCK_PRODUCT_TAX,
            (float) $item->getTaxAmount(),
            0.001,
            'The quote item tax_amount should equal the tax the mocked lookup returned.'
        );

        $address = $quote->getShippingAddress();
        $this->assertEqualsWithDelta(
            self::MOCK_PRODUCT_TAX,
            (float) $address->getTaxAmount(),
            0.001,
            'The collected address tax total should equal the mocked product tax.'
        );

        // Grand total must include the tax: subtotal + shipping + tax.
        $expectedGrand = (float) $address->getSubtotal()
            + (float) $address->getShippingAmount()
            + self::MOCK_PRODUCT_TAX;
        $this->assertEqualsWithDelta(
            $expectedGrand,
            (float) $quote->getGrandTotal(),
            0.001,
            'The quote grand total should include the TaxCloud tax.'
        );
    }
}
