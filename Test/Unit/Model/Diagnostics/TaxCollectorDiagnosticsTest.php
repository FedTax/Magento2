<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Address\Total\Collector;
use Magento\Quote\Model\Quote\Address\Total\CollectorFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;
use Taxcloud\Magento2\Model\Tax;

/**
 * The probe that decides whether TaxCloud calculates tax at all.
 *
 * Its whole value is that it reads the collector array Magento will really run,
 * so a competitor arriving by ANY route shows up. These tests pin that: a
 * different class in the tax slot is unhealthy, an `around` plugin is unhealthy
 * even though we still own the slot, and a store with TaxCloud switched off is
 * not our business. The guard test matters most in production — building the
 * collector list instantiates other vendors' collectors, and one of those
 * throwing must not take the admin dashboard down with it.
 */
#[AllowMockObjectsWithoutExpectations]
class TaxCollectorDiagnosticsTest extends TestCase
{
    private const COMPETITOR = \Competitor\Tax\Model\Total::class;

    /**
     * @var FrontendInterface
     */
    private $cache;

    protected function setUp(): void
    {
        // A cache that always misses and swallows writes: these tests are about
        // the probe, and cache behaviour is exercised through its own test.
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
    }

    /**
     * @param array<int, bool> $enabledByStore
     */
    private function buildConfig(array $enabledByStore): TaxcloudConfig
    {
        $map = [];
        foreach ($enabledByStore as $storeId => $enabled) {
            $map[] = [
                'tax/taxcloud_settings/enabled',
                ScopeInterface::SCOPE_STORE,
                $storeId,
                $enabled ? '1' : '0',
            ];
        }
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($map);

        return new TaxcloudConfig($scopeConfig);
    }

    /**
     * @param int[] $storeIds
     */
    private function buildStoreManager(array $storeIds): StoreManagerInterface
    {
        $stores = [];
        foreach ($storeIds as $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getName')->willReturn('Store ' . $storeId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return $storeManager;
    }

    /**
     * @param array<string, object> $collectors
     */
    private function buildCollectorFactory(array $collectors): CollectorFactory
    {
        $collector = $this->createMock(Collector::class);
        $collector->method('getCollectors')->willReturn($collectors);

        $factory = $this->createMock(CollectorFactory::class);
        $factory->method('create')->willReturn($collector);

        return $factory;
    }

    /**
     * Fake around-plugin chain, walked the way core's Interceptor walks it:
     * getNext() hands back only the NEXT around code, so each hop feeds the
     * following call.
     *
     * @param array<string, object> $pluginsByCode Ordered code => plugin instance
     */
    private function buildPluginList(array $pluginsByCode = []): PluginListInterface
    {
        $pluginList = $this->createMock(PluginListInterface::class);

        // '' is the head of the chain, standing for getNext()'s null $code.
        // Not null itself: PHP 8.5 deprecates null as an array offset, and
        // Magento's unit bootstrap promotes deprecations to test errors.
        $chain = [];
        $previous = '';
        foreach (array_keys($pluginsByCode) as $code) {
            $chain[$previous] = [DefinitionInterface::LISTENER_AROUND => $code];
            $previous = $code;
        }
        $chain[$previous] = [];

        $pluginList->method('getNext')->willReturnCallback(
            static function ($type, $method, $code = null) use ($chain) {
                return $chain[$code ?? ''] ?? [];
            }
        );
        $pluginList->method('getPlugin')->willReturnCallback(
            static function ($type, $code) use ($pluginsByCode) {
                return $pluginsByCode[$code] ?? null;
            }
        );

        return $pluginList;
    }

    private function build(
        array $collectors,
        array $enabledByStore = [1 => true],
        array $plugins = []
    ): TaxCollectorDiagnostics {
        return new TaxCollectorDiagnostics(
            $this->buildCollectorFactory($collectors),
            $this->buildPluginList($plugins),
            $this->buildStoreManager(array_keys($enabledByStore)),
            $this->buildConfig($enabledByStore),
            $this->cache,
            new Json()
        );
    }

    private function ourCollector(): object
    {
        return $this->createMock(Tax::class);
    }

    /**
     * A collector or plugin from some other module.
     *
     * Real classes from Test/Unit/Double/CollectorDoubles.php rather than
     * mocks: the probe classifies by namespace, and a PHPUnit mock class name
     * (MockObject_Foo_1a2b3c) has none.
     *
     * @param class-string $class
     */
    private function stub(string $class): object
    {
        return new $class();
    }

    public function testHealthyWhenTaxcloudOwnsTheCollector()
    {
        $verdict = $this->build(['subtotal' => new \stdClass(), 'tax' => $this->ourCollector()])->verdict();

        $this->assertTrue($verdict->isHealthy());
        $this->assertCount(1, $verdict->getStoreVerdicts());
        $this->assertTrue($verdict->getStoreVerdicts()[0]->isOwned());
    }

    /**
     * The case the whole feature exists for: another module in the tax slot
     * means our collect() never runs, with no error anywhere.
     */
    public function testUnhealthyWhenAnotherModuleOwnsTheCollector()
    {
        $verdict = $this->build(['tax' => $this->stub(self::COMPETITOR)])->verdict();

        $storeVerdict = $verdict->getStoreVerdicts()[0];
        $this->assertFalse($verdict->isHealthy());
        $this->assertFalse($storeVerdict->isOwned());
        $this->assertSame(self::COMPETITOR, $storeVerdict->getActiveCollectorClass());
    }

    public function testUnhealthyWhenTheTaxTotalIsMissingEntirely()
    {
        $verdict = $this->build(['subtotal' => new \stdClass()])->verdict();

        $this->assertFalse($verdict->isHealthy());
        $this->assertNull($verdict->getStoreVerdicts()[0]->getActiveCollectorClass());
    }

    /**
     * Owning the slot is not enough: an `around` plugin that skips $proceed
     * suppresses the calculation while every other signal reads green.
     */
    public function testInterceptionMakesTheStoreUnhealthyEvenWhenWeOwnTheSlot()
    {
        $verdict = $this->build(
            ['tax' => $this->ourCollector()],
            [1 => true],
            ['competitor_plugin' => $this->stub(\Competitor\Tax\Model\Plugin::class)]
        )->verdict();

        $storeVerdict = $verdict->getStoreVerdicts()[0];
        $this->assertFalse($verdict->isHealthy());
        $this->assertTrue($storeVerdict->isOwned());
        $this->assertSame([\Competitor\Tax\Model\Plugin::class], $storeVerdict->getInterceptors());
    }

    /**
     * Our own plugins are not competitors; reporting them would make the banner
     * cry wolf on a correctly working install.
     */
    public function testOwnPluginsAreNotReportedAsInterceptors()
    {
        $verdict = $this->build(
            ['tax' => $this->ourCollector()],
            [1 => true],
            ['taxcloud_plugin' => new OwnPluginDouble()]
        )->verdict();

        $this->assertTrue($verdict->isHealthy());
        $this->assertSame([], $verdict->getStoreVerdicts()[0]->getInterceptors());
    }

    public function testLaterNonCoreCollectorsAreReportedButAdvisory()
    {
        $verdict = $this->build([
            'tax' => $this->ourCollector(),
            'loyalty' => $this->stub(\Loyalty\Model\Total::class),
            'grand_total' => $this->stub(\Magento\FakeCore\Model\GrandTotal::class),
        ])->verdict();

        $storeVerdict = $verdict->getStoreVerdicts()[0];
        $this->assertTrue($verdict->isHealthy(), 'A later collector alone must not raise the banner');
        $this->assertSame([\Loyalty\Model\Total::class], $storeVerdict->getLaterCollectors());
    }

    public function testCollectorsBeforeTaxAreNotReportedAsLater()
    {
        $verdict = $this->build([
            'weee' => $this->stub(\Weee\Model\Total::class),
            'tax' => $this->ourCollector(),
        ])->verdict();

        $this->assertSame([], $verdict->getStoreVerdicts()[0]->getLaterCollectors());
    }

    /**
     * A merchant deliberately running another provider on one store view is not
     * misconfigured, and a banner for it would train them to dismiss the banner.
     */
    public function testDisabledStoresAreNotEvaluated()
    {
        $verdict = $this->build(
            ['tax' => $this->stub(self::COMPETITOR)],
            [1 => false]
        )->verdict();

        $this->assertSame([], $verdict->getStoreVerdicts());
        $this->assertTrue($verdict->isHealthy());
    }

    public function testOnlyEnabledStoresAppearInTheVerdict()
    {
        $verdict = $this->build(
            ['tax' => $this->ourCollector()],
            [1 => true, 2 => false, 3 => true]
        )->verdict();

        $this->assertSame([1, 3], $verdict->getStoreIds());
    }

    /**
     * The probe instantiates third-party collectors. One of those throwing from
     * its constructor must become a reported state, not a stack trace on the
     * admin dashboard or a fatal during order placement.
     */
    public function testProbeFailureBecomesAReportedVerdict()
    {
        $factory = $this->createMock(CollectorFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('collector blew up'));

        $diagnostics = new TaxCollectorDiagnostics(
            $factory,
            $this->buildPluginList(),
            $this->buildStoreManager([1]),
            $this->buildConfig([1 => true]),
            $this->cache,
            new Json()
        );

        $verdict = $diagnostics->verdict();

        $this->assertFalse($verdict->isHealthy());
        $this->assertTrue($verdict->hasFailures());
        $this->assertSame('collector blew up', $verdict->getStoreVerdicts()[0]->getFailureReason());
    }

    /**
     * A broken plugin config elsewhere must not sink the ownership finding,
     * which is the load-bearing half of the verdict.
     */
    public function testPluginListFailureLeavesOwnershipIntact()
    {
        $pluginList = $this->createMock(PluginListInterface::class);
        $pluginList->method('getNext')->willThrowException(new \RuntimeException('bad plugin config'));

        $diagnostics = new TaxCollectorDiagnostics(
            $this->buildCollectorFactory(['tax' => $this->ourCollector()]),
            $pluginList,
            $this->buildStoreManager([1]),
            $this->buildConfig([1 => true]),
            $this->cache,
            new Json()
        );

        $verdict = $diagnostics->verdict();

        $this->assertTrue($verdict->isHealthy());
        $this->assertSame([], $verdict->getStoreVerdicts()[0]->getInterceptors());
    }
}

/**
 * A plugin of ours: its class sits under Taxcloud\Magento2\, which is what the
 * probe uses to tell our own interception apart from a competitor's.
 */
class OwnPluginDouble
{
}
