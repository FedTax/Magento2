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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model\RetailDeliveryFee;

use Magento\Directory\Model\RegionFactory;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Taxcloud\Magento2\Model\RetailDeliveryFee\FeeService;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The Colorado Retail Delivery Fee through a real Magento order lifecycle:
 * charged at checkout, carried in the Lookup cart, persisted to the order,
 * and reversed on full returns only.
 *
 * The unit suite pins the same rules against mocks; this file proves them
 * against carts, orders and credit memos Magento actually built, and against
 * the exact SOAP payloads the recording client saw — the same
 * deliberately-redundant split as CatalogTypeLookupTest.
 */
class RetailDeliveryFeeLifecycleTest extends IntegrationTestCase
{
    private const FEE = '0.31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();

        $this->setScopedConfig('tax/taxcloud_settings/co_rdf_enabled', '1');
        $this->setScopedConfig('tax/taxcloud_settings/co_rdf_amount', self::FEE);
        $this->setScopedConfig('tax/taxcloud_settings/co_rdf_delivery_methods', 'flatrate_flatrate');
    }

    /**
     * Eligible checkout: the fee is charged exactly once, into its own
     * bucket — grand total includes it, tax total does not.
     */
    public function testEligibleQuoteIsChargedTheFeeOnce(): void
    {
        $quote = $this->coloradoQuote();
        $address = $quote->getShippingAddress();

        $this->assertSame(0.31, round((float) $address->getTaxcloudRdfAmount(), 2));
        $this->assertSame(0.31, round((float) $address->getBaseTaxcloudRdfAmount(), 2));

        $expectedGrand = round(
            (float) $address->getSubtotal()
            + (float) $address->getShippingAmount()
            + (float) $address->getTaxAmount()
            + 0.31,
            2
        );
        $this->assertSame(
            $expectedGrand,
            round((float) $address->getGrandTotal(), 2),
            'The grand total must include the fee exactly once, outside the tax total.'
        );

        // Re-collecting must not double-charge.
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->assertSame(0.31, round((float) $quote->getShippingAddress()->getTaxcloudRdfAmount(), 2));
    }

    /**
     * The Lookup that priced this cart carried the fee as a discrete
     * zero-rated TIC 11098 line at the configured amount.
     */
    public function testLookupCartCarriesTheFeeLine(): void
    {
        $this->coloradoQuote();

        $soap = $this->soapClient();
        $this->assertGreaterThanOrEqual(1, $soap->callCount('lookup'));

        $feeLines = array_values(array_filter(
            $soap->firstCallArgs('lookup')['cartItems'] ?? [],
            static function (array $line): bool {
                return $line['ItemID'] === FeeService::ITEM_ID;
            }
        ));

        $this->assertCount(1, $feeLines, 'Exactly one fee line must ride the Lookup.');
        $this->assertSame('11098', (string) $feeLines[0]['TIC']);
        $this->assertSame(0.31, round((float) $feeLines[0]['Price'], 2));
        $this->assertSame(1, (int) $feeLines[0]['Qty']);
    }

    /**
     * Ineligible destination: no fee charged, no fee line sent.
     */
    public function testNonColoradoQuoteHasNoFeeAnywhere(): void
    {
        // The default helper address is Austin TX.
        $quote = $this->buildQuoteWithTestProduct();

        $this->assertSame(0.0, (float) $quote->getShippingAddress()->getTaxcloudRdfAmount());

        foreach ($this->soapClient()->callsTo('lookup') as $call) {
            foreach ($call['cartItems'] ?? [] as $line) {
                $this->assertNotSame(
                    FeeService::ITEM_ID,
                    $line['ItemID'],
                    'An ineligible cart must not carry the fee line.'
                );
            }
        }
    }

    /**
     * Unmapped shipping method: Colorado destination alone is not enough.
     */
    public function testUnmappedShippingMethodIsNotCharged(): void
    {
        $this->setScopedConfig('tax/taxcloud_settings/co_rdf_delivery_methods', 'ups_ground');

        $quote = $this->coloradoQuote();

        $this->assertSame(
            0.0,
            (float) $quote->getShippingAddress()->getTaxcloudRdfAmount(),
            'flatrate is not in the configured motor-vehicle methods, so no fee.'
        );
    }

    /**
     * The placed order persists the fee and includes it in the grand total —
     * the amounts capture and refunds later read.
     */
    public function testPlacedOrderPersistsTheFee(): void
    {
        $order = $this->placeColoradoOrder();

        $this->assertSame(0.31, round((float) $order->getTaxcloudRdfAmount(), 2));
        $this->assertSame(0.31, round((float) $order->getBaseTaxcloudRdfAmount(), 2));
        $this->assertGreaterThan(0, (int) $this->soapClient()->callCount('authorizedWithCapture'));

        // On v1 the capture references the Lookup's cart, so the filed cart is
        // the one already asserted to carry the fee line; the reload proves
        // the columns survived the save pipeline.
        $fresh = $this->reloadOrder($order);
        $this->assertSame(0.31, round((float) $fresh->getData('taxcloud_rdf_amount'), 2));
    }

    /**
     * Full return: the memo credits the fee and the Returned call reverses it.
     */
    public function testFullRefundCreditsAndReversesTheFee(): void
    {
        $order = $this->placeColoradoOrder();
        $this->payInvoice($order);
        $order = $this->reloadOrder($order);
        $this->soapClient()->resetCalls();

        $creditmemo = $this->refundOrder($order);

        $this->assertSame(
            0.31,
            round((float) $creditmemo->getTaxcloudRdfAmount(), 2),
            'A full return must credit the fee to the customer.'
        );

        $this->assertSame(1, $this->soapClient()->callCount('Returned'));
        $args = $this->soapClient()->firstCallArgs('Returned');

        $cartItems = $args['cartItems'] ?? [];
        $feeLineSent = false;
        foreach ((array) $cartItems as $line) {
            if (is_array($line) && ($line['ItemID'] ?? null) === FeeService::ITEM_ID) {
                $feeLineSent = true;
                $this->assertSame(0.31, round((float) $line['Price'], 2));
            }
        }

        $this->assertTrue(
            $feeLineSent || !empty($args['returnCoDeliveryFeeWhenNoCartItems']),
            'A full return must reverse the fee in TaxCloud: either as a returned '
            . 'cart line or, for the empty-cart form, via returnCoDeliveryFeeWhenNoCartItems.'
        );
    }

    /**
     * Partial return: the fee stays — not credited, not reversed.
     */
    public function testPartialRefundLeavesTheFeeInPlace(): void
    {
        $order = $this->placeColoradoOrder(2);
        $this->payInvoice($order);
        $order = $this->reloadOrder($order);
        $this->soapClient()->resetCalls();

        $orderItem = current($order->getAllVisibleItems());
        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, [
            'qtys' => [$orderItem->getId() => 1],
            'shipping_amount' => 0,
        ]);
        $this->get(CreditmemoService::class)->refund($creditmemo, true);

        $this->assertSame(
            0.0,
            (float) $creditmemo->getTaxcloudRdfAmount(),
            'A partial return must not credit the fee.'
        );

        foreach ($this->soapClient()->callsTo('Returned') as $args) {
            foreach ((array) ($args['cartItems'] ?? []) as $line) {
                if (is_array($line)) {
                    $this->assertNotSame(FeeService::ITEM_ID, $line['ItemID'] ?? null);
                }
            }
            $this->assertEmpty(
                $args['returnCoDeliveryFeeWhenNoCartItems'] ?? false,
                'A partial return must not drive the fee-return flag.'
            );
        }
    }

    /**
     * A quote to Denver, using the seeded test product and the flatrate
     * method the config maps as motor-vehicle delivery.
     */
    private function coloradoQuote(int $qty = 1): Quote
    {
        return $this->buildQuoteWithTestProduct($qty, $this->denverAddress());
    }

    private function placeColoradoOrder(int $qty = 1): Order
    {
        $quote = $this->coloradoQuote($qty);

        $orderId = $this->get(\Magento\Quote\Api\CartManagementInterface::class)
            ->placeOrder((int) $quote->getId());

        return $this->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId);
    }

    /**
     * Denver, with the region id resolved from the directory rather than
     * hardcoded, so the test cannot silently become a no-op if region ids
     * ever differ between installs.
     *
     * @return array<string, mixed>
     */
    private function denverAddress(): array
    {
        $region = $this->get(RegionFactory::class)->create()->loadByCode('CO', 'US');

        return [
            'street' => '1600 Broadway',
            'city' => 'Denver',
            'region_id' => (int) $region->getId(),
            'region' => 'Colorado',
            'postcode' => '80202',
        ];
    }
}
