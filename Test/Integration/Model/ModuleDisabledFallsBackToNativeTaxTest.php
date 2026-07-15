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

use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\Rule;
use Magento\Tax\Model\TaxCalculation;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that with the module disabled (enabled = 0), Tax::collect() defers to
 * parent::collect() and Magento's NATIVE tax rules apply — no TaxCloud SOAP
 * traffic, and tax computed from a real Magento tax rule. Covers gap #11.1,
 * which a unit test cannot reach because the parent call dispatches into the
 * real Magento tax-calculation stack. The native rate is deliberately 8.25% so
 * it can't be confused with anything TaxCloud-shaped.
 */
class ModuleDisabledFallsBackToNativeTaxTest extends IntegrationTestCase
{
    private const NATIVE_RATE = 8.25;
    private const RATE_CODE = 'taxcloud-it-ny-8-25';
    private const RULE_CODE = 'taxcloud-it-native-rule';

    // Ship to New York, NOT the Texas address every other test uses. Magento's
    // in-memory tax-rate cache is keyed by region|postcode|classes, so a state no
    // other test touches can't be poisoned by a "no rate" entry they cached.
    private const NY_REGION_ID = 43;
    private const SHIP_TO_NY = [
        'city'      => 'New York',
        'region_id' => self::NY_REGION_ID,
        'region'    => 'New York',
        'postcode'  => '10001',
    ];

    private ?Rate $rate = null;
    private ?Rule $rule = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock SOAP purely to record (and prove the absence of) lookup calls.
        $this->installSoapMock();

        // Turn the module off so Tax::collect() falls through to parent::collect().
        $this->writeConfig('tax/taxcloud_settings/enabled', '0');

        $this->createNativeTaxRule();

        // Evict Magento's in-memory tax-rate cache so the rule just created is
        // visible to this collection (earlier tests, which call getQuoteTaxDetails
        // even on the TaxCloud path, cached "no rate for TX"). The collector
        // itself is rebuilt by installSoapMock()'s resetTotalsCollector(), so it
        // will pick up these fresh calculation singletons.
        $this->mutateSharedInstances([
            Calculation::class,
            TaxCalculation::class,
            TaxCalculationInterface::class,
        ]);
    }

    protected function tearDown(): void
    {
        // Restore the seeded baseline so later tests still see the module enabled.
        $this->writeConfig('tax/taxcloud_settings/enabled', '1');
        $this->deleteNativeTaxRule();

        parent::tearDown();
    }

    public function testNativeMagentoTaxRunsWhenModuleDisabled(): void
    {
        $soap = $this->soapClient();

        $quote = $this->buildQuoteWithTestProduct(1, self::SHIP_TO_NY);

        $this->assertSame(
            0,
            $soap->callCount('lookup'),
            'With the module disabled, no TaxCloud lookup SOAP call should be made.'
        );

        $items = $quote->getAllVisibleItems();
        $this->assertCount(1, $items, 'Expected exactly one product line on the quote.');
        $item = $items[0];

        $expectedTax = round((float) $item->getRowTotal() * self::NATIVE_RATE / 100, 2);
        $this->assertGreaterThan(0.0, $expectedTax, 'Sanity: the native rate should produce some tax.');

        $this->assertEqualsWithDelta(
            $expectedTax,
            (float) $item->getTaxAmount(),
            0.001,
            'The quote item tax_amount should equal 8.25% of the row total, computed by '
            . "Magento's native tax rule — proving parent::collect() really ran."
        );
    }

    private function createNativeTaxRule(): void
    {
        $om = $this->objectManager();

        // Mirrors Magento's own tax_rule integration fixture
        // (dev/tests/integration/.../Tax/_files/tax_rule_region_1_al.php). The
        // crucial bit is tax_rates_codes on the rule: without it, Rule::afterSave()
        // never generates the tax_calculation rows, so no rate ever matches and
        // tax comes out 0.
        /** @var Rate $rate */
        $rate = $om->create(Rate::class)->setData([
            'tax_country_id' => 'US',
            'tax_region_id'  => (string) self::NY_REGION_ID, // matches the NY ship-to
            'tax_postcode'   => '*',
            'code'           => self::RATE_CODE,
            'rate'           => (string) self::NATIVE_RATE,
        ])->save();
        $this->rate = $rate;

        /** @var Rule $rule */
        $rule = $om->create(Rule::class)->setData([
            'code'                   => self::RULE_CODE,
            'priority'               => '0',
            'position'               => '0',
            'customer_tax_class_ids' => [3], // Retail Customer (guest default)
            'product_tax_class_ids'  => [2], // Taxable Goods (product default)
            'tax_rate_ids'           => [$rate->getId()],
            'tax_rates_codes'        => [$rate->getId() => $rate->getCode()],
        ])->save();
        $this->rule = $rule;
    }

    private function deleteNativeTaxRule(): void
    {
        try {
            if ($this->rule !== null && $this->rule->getId()) {
                $this->rule->delete();
            }
            if ($this->rate !== null && $this->rate->getId()) {
                $this->rate->delete();
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup; don't mask the test result.
        }
    }
}
