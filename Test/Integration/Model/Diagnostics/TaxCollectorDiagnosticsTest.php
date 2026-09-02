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
use Magento\Framework\ObjectManager\ConfigInterface as DiConfigInterface;
use Magento\Quote\Model\Quote\Address\Total\Collector;
use Magento\Quote\Model\Quote\Address\Total\CollectorFactory;
use Magento\Quote\Model\Quote\TotalsCollectorList;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Sales\Total\Quote\Tax as CoreTax;
use Monolog\Handler\TestHandler;
use Psr\Log\LogLevel;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Tax;
use Taxcloud\Magento2\Observer\Sales\VerifyTaxCollector;
use Taxcloud\Magento2\Test\Integration\Doubles\CompetingTaxCollector;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The collector probe against real DI.
 *
 * The unit suite injects the collector array, so it can only prove the probe
 * reasons correctly about an array it was handed. What it cannot prove is the
 * claim the whole design rests on: that
 * Collector::getCollectors()['tax'] reflects what the object manager ACTUALLY
 * resolves on a real install — which is why that probe was chosen over
 * resolving the core tax class directly. If that stopped being true, every unit
 * test would still pass while the probe returned a false healthy verdict on a
 * store silently under-collecting tax.
 *
 * Two further claims were read out of Magento's source and never executed: that
 * the quote marker Tax::collect() sets survives from collectTotals() to
 * sales_model_service_quote_submit_before inside one placeOrder request, and
 * that the diagnostics service is wired well enough to construct at all.
 */
class TaxCollectorDiagnosticsTest extends IntegrationTestCase
{
    /**
     * @var TestHandler|null
     */
    private $logHandler = null;

    /**
     * Distinctive fragment of the observer's warning, so the assertions ignore
     * everything else the module logs through the same shared logger.
     */
    private const COLLECTOR_WARNING = 'its tax collector did not run';

    /**
     * Preference in force before a test rebound it, restored in tearDown.
     *
     * @var string|null
     */
    private $originalPreference = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->forgetVerdict();
        $this->clearWarningRateLimit();
    }

    protected function tearDown(): void
    {
        if ($this->logHandler !== null) {
            $this->get(Logger::class)->popHandler();
            $this->logHandler = null;
        }

        // Rebinding a preference is process-global: leaving a competitor bound
        // would make every later test in the run see a hijacked tax collector.
        $this->restoreTaxCollectorPreference();
        $this->forgetVerdict();
        parent::tearDown();
    }

    /**
     * Point Magento's tax total at another class, the way a rival extension's
     * own di.xml preference would.
     *
     * @param class-string $type
     */
    private function rebindTaxCollector(string $type): void
    {
        $diConfig = $this->get(DiConfigInterface::class);
        if ($this->originalPreference === null) {
            $this->originalPreference = $diConfig->getPreference(CoreTax::class);
        }

        $diConfig->extend(['preferences' => [CoreTax::class => $type]]);
        $this->evictCollectorGraph();
    }

    private function restoreTaxCollectorPreference(): void
    {
        if ($this->originalPreference === null) {
            return;
        }

        $this->get(DiConfigInterface::class)
            ->extend(['preferences' => [CoreTax::class => $this->originalPreference]]);
        $this->originalPreference = null;
        $this->evictCollectorGraph();
    }

    /**
     * Drop every singleton that could still be holding a collector built under
     * the previous preference — including the diagnostics service, which memoes
     * its verdict per request.
     *
     * TotalsCollectorList is the one that is easy to miss and the one that
     * matters: it takes a Collector as a constructor argument and holds it for
     * the life of the request, so leaving it cached lets checkout keep running
     * the pre-rebind collectors while the probe correctly reports the new ones.
     */
    private function evictCollectorGraph(): void
    {
        $this->mutateSharedInstances([
            TaxCollectorDiagnostics::class,
            CollectorFactory::class,
            TotalsCollectorList::class,
            \Magento\Quote\Model\Quote\TotalsCollector::class,
            Collector::class,
        ]);
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

    /**
     * Drop the observer's per-store warning rate limit.
     *
     * The entry lives in the shared cache backend for an hour and outlives the
     * process that wrote it, so without this a suite that places an order on an
     * unhealthy store silences itself for every run in the next hour — and a
     * suppressed warning is indistinguishable from a warning that never fired.
     */
    private function clearWarningRateLimit(): void
    {
        $cacheType = $this->get(\Taxcloud\Magento2\Model\Cache\Type\Taxcloud::class);
        foreach ($this->get(StoreManagerInterface::class)->getStores() as $store) {
            $cacheType->remove(VerifyTaxCollector::RATE_LIMIT_KEY_PREFIX . (int) $store->getId());
        }
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
     * carry the class our di.xml preference names.
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
        $storeId = (int) $store->getId();

        $probed = $this->get(CollectorFactory::class)->create(['store' => $store])->getCollectors();
        $checkout = $this->get(TotalsCollectorList::class)->getCollectors($storeId);

        $this->assertSame(array_keys($probed), array_keys($checkout));
        $this->assertSame(
            get_class($probed[TaxCollectorDiagnostics::TAX_TOTAL_CODE]),
            get_class($checkout[TaxCollectorDiagnostics::TAX_TOTAL_CODE])
        );
    }

    public function testCompetingPreferenceIsDetectedAndNamed(): void
    {
        $this->rebindTaxCollector(CompetingTaxCollector::class);

        $verdict = $this->diagnostics()->verdict();
        $storeVerdict = $verdict->getStoreVerdicts()[0];

        $this->assertFalse($verdict->isHealthy());
        $this->assertFalse($storeVerdict->isOwned());
        $this->assertSame(CompetingTaxCollector::class, $storeVerdict->getActiveCollectorClass());
        $this->assertNotSame('', $verdict->fingerprint());
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

    /**
     * Pins the assumption the runtime detection rests on: QuoteManagement calls
     * collectTotals() and then submit() on the same quote object in the same
     * request, so the transient marker is still there when the observer looks.
     *
     * Asserted through the observer's own output rather than by substituting
     * the observer, because Magento instantiates observers fresh for every
     * event — a seeded shared instance is never consulted. Silence here means
     * the marker was present: the observer warns only when it is missing.
     */
    public function testCollectMarkerSurvivesToOrderSubmit(): void
    {
        $logger = $this->recordLog();

        $this->placeOrder();

        $this->assertSame(
            [],
            $this->collectorWarnings($logger),
            'TaxCloud collected the totals, so the marker must still be on the quote at submit'
        );
    }

    public function testWarningIsLoggedWhenAnotherModuleOwnsTheCollector(): void
    {
        $this->rebindTaxCollector(CompetingTaxCollector::class);
        $logger = $this->recordLog();

        // Guard, not decoration: if the rebinding has not reached the list
        // checkout iterates, the assertion below would fail for a reason that
        // has nothing to do with the observer.
        $this->assertInstanceOf(
            CompetingTaxCollector::class,
            $this->get(TotalsCollectorList::class)
                ->getCollectors((int) $this->get(StoreManagerInterface::class)->getStore('default')->getId())
                [TaxCollectorDiagnostics::TAX_TOTAL_CODE],
            'The rebound collector never reached the checkout collector list'
        );

        $this->placeOrder();

        $warnings = $this->collectorWarnings($logger);
        $this->assertCount(1, $warnings, 'An order placed without our collector must warn exactly once');
        $this->assertSame(
            (int) $this->get(StoreManagerInterface::class)->getStore('default')->getId(),
            (int) $this->warningContext($warnings[0])['store_id']
        );
    }

    /**
     * Monolog 2 hands back arrays, Monolog 3 LogRecord objects; Magento 2.4.7
     * and 2.4.9 differ here.
     *
     * @param mixed $record
     * @return array<string, mixed>
     */
    private function warningContext($record): array
    {
        return is_array($record) ? $record['context'] : $record->context;
    }

    /**
     * The collector warnings captured so far, ignoring everything else the
     * module logs through the same logger.
     *
     * @param TestHandler $handler
     * @return array<int, array<string, mixed>>
     */
    private function collectorWarnings(TestHandler $handler): array
    {
        return array_values(array_filter(
            $handler->getRecords(),
            static function ($record) {
                $level = is_array($record) ? $record['level_name'] : $record->level->getName();
                $message = is_array($record) ? $record['message'] : $record->message;

                return strtoupper((string) $level) === strtoupper(LogLevel::WARNING)
                    && strpos((string) $message, self::COLLECTOR_WARNING) !== false;
            }
        ));
    }

    /**
     * Capture what the module logs, by pushing a handler onto the logger that
     * is already in use rather than substituting anything.
     *
     * Two more direct routes do not work, and both fail silently, which is why
     * this one is worth the note. Magento builds observers fresh for every
     * event (ObserverFactory::create()), so a seeded observer instance is never
     * consulted. And seeding a replacement GatewayLogger only takes if nothing
     * has resolved one yet — once any observer in the process has been handed
     * the real one, a later seed is ignored and the substitute records nothing,
     * which is indistinguishable from the observer never firing.
     *
     * Mutating the single shared Monolog logger sidesteps the timing entirely:
     * whoever already holds the GatewayLogger keeps holding it, and it still
     * writes through the handler stack this pushes onto.
     */
    private function recordLog(): TestHandler
    {
        if ($this->logHandler === null) {
            $this->logHandler = new TestHandler();
            $this->get(Logger::class)->pushHandler($this->logHandler);
        }
        $this->logHandler->clear();

        return $this->logHandler;
    }
}
