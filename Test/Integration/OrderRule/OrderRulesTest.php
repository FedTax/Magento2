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

namespace Taxcloud\Magento2\Test\Integration\OrderRule;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\Rule as TaxRule;
use Magento\Tax\Model\TaxCalculation;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Api\Data\OrderRuleInterfaceFactory;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Order processing rules through real Magento: quote submission records the
 * outcome and its comment, a skip rule hands the quote to Magento's native
 * tax with no TaxCloud call, a Report rule overrides a non-reporting store,
 * and orders placed before rules existed keep following their store setting.
 *
 * Assertions are on the recorded SOAP traffic and the persisted order, which
 * covers the wiring unit tests cannot: the submit observer, the quote→order
 * hand-off, the collector's skip path and the DI bindings of every consumer.
 */
class OrderRulesTest extends IntegrationTestCase
{
    private const NATIVE_RATE = 8.25;
    private const RATE_CODE = 'taxcloud-it-rules-ny-8-25';
    private const RULE_CODE = 'taxcloud-it-rules-native-rule';
    private const NY_REGION_ID = 43;

    // New York, which no other suite taxes natively: Magento's in-memory rate
    // cache is keyed by region|postcode, so a state others cached "no rate"
    // for cannot poison this one.
    private const SHIP_TO_NY = [
        'city'      => 'New York',
        'region_id' => self::NY_REGION_ID,
        'region'    => 'New York',
        'postcode'  => '10001',
    ];

    /** @var int[] */
    private $ruleIds = [];

    private ?Rate $rate = null;
    private ?TaxRule $taxRule = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
    }

    protected function tearDown(): void
    {
        $repository = $this->get(OrderRuleRepositoryInterface::class);
        foreach ($this->ruleIds as $ruleId) {
            try {
                $repository->deleteById($ruleId);
            } catch (\Throwable $e) {
                // Already gone.
            }
        }
        $this->deleteNativeTaxRule();
        parent::tearDown();
    }

    /**
     * Quote submission stores the outcome and the deciding rule, writes the
     * explanatory comment, and the capture gate honours the outcome.
     */
    public function testQuoteSubmissionRecordsTheOutcomeAndComment(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
        $ruleId = $this->createRule('Check orders via ERP', OrderRuleInterface::ACTION_CALCULATE_ONLY, [
            'payment_methods' => ['checkmo'],
        ]);

        $order = $this->reloadOrder($this->placeOrder());

        $this->assertSame('calculate_only', $order->getData('taxcloud_outcome'));
        $this->assertSame($ruleId, (int) $order->getData('taxcloud_outcome_rule_id'));
        $this->assertSame('Check orders via ERP', $order->getData('taxcloud_outcome_rule_name'));
        $this->assertGreaterThan(0, $soap->callCount('lookup'), 'Calculate only still calculates with TaxCloud.');
        $this->assertSame(0, $soap->callCount('authorizedWithCapture'), 'Calculate only is never reported.');

        $comment = $this->taxcloudComment($order);
        $this->assertNotNull($comment, 'A rule-decided outcome must leave an order comment.');
        $this->assertStringContainsString('Order rule "Check orders via ERP" matched payment method', $comment);
        $this->assertStringContainsString('(checkmo)', $comment);
    }

    /**
     * A skip rule hands the quote to Magento's own tax rules: tax is the
     * native rate, and TaxCloud is never called — not to look up, not to
     * capture.
     */
    public function testASkippedQuoteIsTaxedNativelyWithoutTaxcloud(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
        $this->createNativeTaxRule();
        $this->createRule('Marketplace', OrderRuleInterface::ACTION_SKIP, ['payment_methods' => ['checkmo']]);

        $quote = $this->buildQuoteWithTestProduct(1, self::SHIP_TO_NY);
        $item = $quote->getAllVisibleItems()[0];
        $expectedTax = round((float) $item->getRowTotal() * self::NATIVE_RATE / 100, 2);

        $this->assertGreaterThan(0.0, $expectedTax);
        $this->assertEqualsWithDelta($expectedTax, (float) $item->getTaxAmount(), 0.001);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());
        /** @var Order $order */
        $order = $this->reloadOrder($this->get(OrderRepositoryInterface::class)->get($orderId));

        $this->assertSame(0, $soap->callCount('lookup'), 'A skipped quote must never reach TaxCloud.');
        $this->assertSame(0, $soap->callCount('authorizedWithCapture'));
        $this->assertSame('skip', $order->getData('taxcloud_outcome'));
        $this->assertEqualsWithDelta($expectedTax, (float) $order->getTaxAmount(), 0.001);
        $this->assertStringContainsString('TaxCloud was not used for this order', (string) $this->taxcloudComment($order));
    }

    /**
     * A Report rule sends orders from a store view that does not report.
     */
    public function testAReportRuleOverridesANonReportingStore(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
        $this->setScopedConfig('tax/taxcloud_settings/calculations_only', '1');
        $this->createRule('Checks are ours', OrderRuleInterface::ACTION_REPORT, ['payment_methods' => ['checkmo']]);

        $order = $this->reloadOrder($this->placeOrder());

        $this->assertSame('report', $order->getData('taxcloud_outcome'));
        $this->assertSame(1, $soap->callCount('authorizedWithCapture'));
        $this->assertNull($this->taxcloudComment($order), 'A reported order needs no explanation.');
    }

    /**
     * Rules decide at placement and are never re-applied: changing the rule
     * afterwards does not change what happens at a later capture trigger.
     */
    public function testARuleEditDoesNotAffectAPlacedOrder(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);
        $ruleId = $this->createRule('ERP', OrderRuleInterface::ACTION_CALCULATE_ONLY, []);

        $order = $this->placeOrder();

        $repository = $this->get(OrderRuleRepositoryInterface::class);
        $rule = $repository->getById($ruleId);
        $rule->setAction(OrderRuleInterface::ACTION_REPORT);
        $repository->save($rule);

        $this->payInvoice($order);

        $this->assertSame(0, $soap->callCount('authorizedWithCapture'));
    }

    /**
     * An order placed before rules existed carries no outcome, and follows its
     * store's setting at the time it is captured.
     */
    public function testALegacyOrderFollowsItsStoreSetting(): void
    {
        $soap = $this->soapClient();
        $this->setCaptureTrigger(CaptureTrigger::PAYMENT);
        // A rule that would keep the order from TaxCloud — it must be ignored.
        $this->createRule('Would skip reporting', OrderRuleInterface::ACTION_CALCULATE_ONLY, []);

        $order = $this->placeOrder();
        $this->forgetOutcome($order);

        $this->payInvoice($this->reloadOrder($order));
        $this->assertSame(1, $soap->callCount('authorizedWithCapture'), 'The store reports, so the legacy order captures.');

        $second = $this->placeOrder();
        $this->forgetOutcome($second);
        $this->setScopedConfig('tax/taxcloud_settings/calculations_only', '1');

        $this->payInvoice($this->reloadOrder($second));
        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'With the store no longer reporting, the second legacy order must not capture.'
        );
    }

    /**
     * @param array<string, array> $filters setter-style filter values
     * @return int
     */
    private function createRule(string $name, string $action, array $filters): int
    {
        /** @var OrderRuleInterface $rule */
        $rule = $this->get(OrderRuleInterfaceFactory::class)->create();
        $rule->setName($name);
        $rule->setIsActive(true);
        $rule->setAction($action);
        $rule->setStoreIds($filters['store_ids'] ?? []);
        $rule->setCustomerGroupIds($filters['customer_group_ids'] ?? []);
        $rule->setPaymentMethods($filters['payment_methods'] ?? []);
        $rule->setShippingMethods($filters['shipping_methods'] ?? []);
        $rule->setOrderPrefixes($filters['order_prefixes'] ?? []);
        $rule = $this->get(OrderRuleRepositoryInterface::class)->save($rule);

        $this->ruleIds[] = (int) $rule->getId();
        return (int) $rule->getId();
    }

    private function taxcloudComment(Order $order): ?string
    {
        foreach ($order->getStatusHistoryCollection() as $history) {
            if (strpos((string) $history->getComment(), 'TaxCloud:') === 0) {
                $this->assertFalse((bool) $history->getIsVisibleOnFront(), 'The comment must not be customer-visible.');
                $this->assertFalse((bool) $history->getIsCustomerNotified(), 'The customer must not be notified.');
                return (string) $history->getComment();
            }
        }
        return null;
    }

    /**
     * Make an order look like one placed before the rules shipped.
     */
    private function forgetOutcome(Order $order): void
    {
        $resource = $this->get(\Magento\Sales\Model\ResourceModel\Order::class);
        $resource->getConnection()->update(
            $resource->getMainTable(),
            ['taxcloud_outcome' => null, 'taxcloud_outcome_rule_id' => null, 'taxcloud_outcome_rule_name' => null],
            ['entity_id = ?' => (int) $order->getId()]
        );
    }

    private function createNativeTaxRule(): void
    {
        $om = $this->objectManager();

        /** @var Rate $rate */
        $rate = $om->create(Rate::class)->setData([
            'tax_country_id' => 'US',
            'tax_region_id'  => (string) self::NY_REGION_ID,
            'tax_postcode'   => '*',
            'code'           => self::RATE_CODE,
            'rate'           => (string) self::NATIVE_RATE,
        ])->save();
        $this->rate = $rate;

        /** @var TaxRule $rule */
        $rule = $om->create(TaxRule::class)->setData([
            'code'                   => self::RULE_CODE,
            'priority'               => '0',
            'position'               => '0',
            'customer_tax_class_ids' => [3],
            'product_tax_class_ids'  => [2],
            'tax_rate_ids'           => [$rate->getId()],
            'tax_rates_codes'        => [$rate->getId() => $rate->getCode()],
        ])->save();
        $this->taxRule = $rule;

        // Evict Magento's in-memory rate cache so the new rule is visible.
        $this->mutateSharedInstances([
            Calculation::class,
            TaxCalculation::class,
            TaxCalculationInterface::class,
        ]);
    }

    private function deleteNativeTaxRule(): void
    {
        try {
            if ($this->taxRule !== null && $this->taxRule->getId()) {
                $this->taxRule->delete();
            }
            if ($this->rate !== null && $this->rate->getId()) {
                $this->rate->delete();
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup; don't mask the test result.
        }
    }
}
