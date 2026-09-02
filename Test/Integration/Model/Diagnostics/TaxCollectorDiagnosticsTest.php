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

namespace Taxcloud\Magento2\Test\Integration\Model\Diagnostics;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Quote\Model\Quote\Address\Total\CollectorFactory;
use Magento\Quote\Model\Quote\TotalsCollectorList;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;
use Taxcloud\Magento2\Model\Tax;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The collector probe against real DI.
 *
 * The unit suite injects the collector array, so it can only prove the probe
 * reasons correctly about an array it was handed. What it cannot prove is the
 * claim the whole design rests on: that Collector::getCollectors() reflects
 * what the object manager actually resolves on a real install, and that it is
 * the same list checkout iterates. That is what these cover, along with the
 * fact that the service is wired well enough to construct at all — a wrong
 * di.xml argument fails here and nowhere in the unit suite.
 *
 * Deliberately read-only. An earlier version rebound the tax-total preference
 * at runtime to exercise the competitor path against real DI. It worked, and it
 * quietly broke fifty other tests: this suite depends on the quote collector
 * list being built once, early, and never rebuilt, because Collector keeps a
 * concrete Tax instance and TotalsCollectorList keeps that Collector. Anything
 * that forces a rebuild mid-run leaves those holding a Tax wired to the SOAP
 * mock of whichever test triggered it, and every later test's lookups go to a
 * client that has since been torn down — reported as "no lookup happened",
 * fifty tests away from the cause.
 *
 * The competitor and interception paths are covered by the unit tests, which
 * can inject a collector array without touching global state.
 */
class TaxCollectorDiagnosticsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->forgetVerdict();
    }

    protected function tearDown(): void
    {
        $this->forgetVerdict();
        parent::tearDown();
    }

    /**
     * The verdict is cached in Magento's config cache type, so without this one
     * test would read the verdict a neighbouring test computed.
     */
    private function forgetVerdict(): void
    {
        $this->get(TypeListInterface::class)->cleanType('config');
        $this->mutateSharedInstances([TaxCollectorDiagnostics::class]);
    }

    private function diagnostics(): TaxCollectorDiagnostics
    {
        return $this->get(TaxCollectorDiagnostics::class);
    }

    /**
     * Resolving the service at all is half the point: a wrong di.xml argument
     * (the config-cache binding, say) fails here and nowhere in the unit suite.
     */
    public function testVerdictIsHealthyOnTheStockInstall(): void
    {
        $verdict = $this->diagnostics()->verdict();

        $this->assertNotSame([], $verdict->getStoreVerdicts(), 'TaxCloud should be enabled on the seeded store');
        $this->assertTrue($verdict->isHealthy(), 'A stock install must report TaxCloud as the active collector');
        $this->assertSame(Tax::class, $verdict->getStoreVerdicts()[0]->getActiveCollectorClass());
    }

    /**
     * The mechanism claim: the collector array checkout iterates really does
     * carry the class our di.xml preference names. Resolving the core tax class
     * through the object manager instead would read green while a competing
     * sales.xml entry silently under-collected tax.
     */
    public function testCollectorArrayCarriesTheClassOurPreferenceNames(): void
    {
        $store = $this->get(StoreManagerInterface::class)->getStore('default');
        $collectors = $this->get(CollectorFactory::class)->create(['store' => $store])->getCollectors();

        $this->assertArrayHasKey(TaxCollectorDiagnostics::TAX_TOTAL_CODE, $collectors);
        $this->assertInstanceOf(Tax::class, $collectors[TaxCollectorDiagnostics::TAX_TOTAL_CODE]);
    }

    /**
     * The probe reads Collector::getCollectors(). Checkout reaches the totals
     * through TotalsCollectorList. This pins that those are the same list —
     * without it, the probe could report faithfully on a collector set that is
     * not the one actually pricing carts.
     */
    public function testProbeReadsTheSameCollectorListCheckoutIterates(): void
    {
        $store = $this->get(StoreManagerInterface::class)->getStore('default');

        $probed = $this->get(CollectorFactory::class)->create(['store' => $store])->getCollectors();
        $checkout = $this->get(TotalsCollectorList::class)->getCollectors((int) $store->getId());

        $this->assertSame(array_keys($probed), array_keys($checkout));
        $this->assertSame(
            get_class($probed[TaxCollectorDiagnostics::TAX_TOTAL_CODE]),
            get_class($checkout[TaxCollectorDiagnostics::TAX_TOTAL_CODE])
        );
    }

    /**
     * A merchant deliberately running another provider on one store view is not
     * misconfigured; the seeded second store has TaxCloud disabled.
     */
    public function testDisabledStoreViewIsNotEvaluated(): void
    {
        $storeIds = $this->diagnostics()->verdict()->getStoreIds();

        $this->assertNotContains($this->secondStoreId(), $storeIds);
    }
}
