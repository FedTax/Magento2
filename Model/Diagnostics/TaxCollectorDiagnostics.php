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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Diagnostics;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Address\Total\CollectorFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Tax;

/**
 * Answers whether TaxCloud's tax collector is the one Magento will actually run.
 *
 * Tax in Magento is winner-take-all. TaxCloud claims the slot with a single
 * di.xml preference on \Magento\Tax\Model\Sales\Total\Quote\Tax; if another
 * module takes it, our collect() never runs — no calculation, no capture, and
 * no error anywhere. This service exists to make that state observable.
 *
 * The probe is \Magento\Quote\Model\Quote\Address\Total\Collector::getCollectors(),
 * which returns the sorted, instantiated collector array checkout iterates.
 * That one accessor covers two distinct attacks on the slot, because Collector
 * builds each entry with $totalFactory->create($class): $class comes from the
 * merged sales.xml (so a competing <item name="tax"> shows up) and create()
 * goes through the object manager (so a competing preference shows up too).
 * Resolving the preference alone — ObjectManager::get() on the core class —
 * would read green while a sales.xml override silently under-collected tax,
 * which is worse than no check at all.
 *
 * Two conditions survive that probe because they leave our class in place, so
 * they get probes of their own: an `around` plugin on collect() that never
 * calls $proceed, and a collector ordered after the tax total that overwrites
 * the result.
 *
 * Never call this on the storefront request path. The runtime equivalent is the
 * flag Tax::collect() sets on the quote, read by
 * {@see \Taxcloud\Magento2\Observer\Sales\VerifyTaxCollector}.
 */
class TaxCollectorDiagnostics
{
    /**
     * Total code of the tax collector, as declared in Magento_Tax's sales.xml.
     */
    public const TAX_TOTAL_CODE = 'tax';

    /**
     * Method the interception probe inspects.
     */
    private const COLLECT_METHOD = 'collect';

    /**
     * Namespace prefix of collectors and plugins that are ours, and so are
     * never reported as competitors.
     */
    private const OWN_NAMESPACE = 'Taxcloud\\Magento2\\';

    /**
     * Namespace prefix treated as core. Collectors here are expected to run
     * after tax (grand total, and friends) and are not reported.
     */
    private const CORE_NAMESPACE = 'Magento\\';

    /**
     * @var string
     */
    private const CACHE_KEY_PREFIX = 'taxcloud_collector_verdict_';

    /**
     * @var CollectorFactory
     */
    private $collectorFactory;

    /**
     * @var PluginListInterface
     */
    private $pluginList;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var FrontendInterface
     */
    private $cache;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * In-request memo, so the notification, its dismissal gate and the message
     * text do not each rebuild the collector list on one admin page render.
     *
     * @var StoreVerdict[]
     */
    private $memo = [];

    /**
     * @param CollectorFactory      $collectorFactory
     * @param PluginListInterface   $pluginList
     * @param StoreManagerInterface $storeManager
     * @param TaxcloudConfig        $config
     * @param FrontendInterface     $cache      Bound to Magento's config cache type in di.xml
     * @param SerializerInterface   $serializer
     */
    public function __construct(
        CollectorFactory $collectorFactory,
        PluginListInterface $pluginList,
        StoreManagerInterface $storeManager,
        TaxcloudConfig $config,
        FrontendInterface $cache,
        SerializerInterface $serializer
    ) {
        $this->collectorFactory = $collectorFactory;
        $this->pluginList = $pluginList;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * Verdict across every store where TaxCloud is enabled.
     *
     * Stores with TaxCloud disabled are skipped entirely: a merchant running a
     * different provider on one store view is not misconfigured, and letting
     * such a store raise a notification would train admins to dismiss it.
     *
     * @return CollectorVerdict
     */
    public function verdict(): CollectorVerdict
    {
        $verdicts = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$this->config->isEnabled($store->getId())) {
                continue;
            }
            $verdicts[] = $this->forStore($store);
        }

        return new CollectorVerdict($verdicts);
    }

    /**
     * Verdict for one store, served from cache when available.
     *
     * Computed lazily per store rather than looping every store up front, so a
     * large multi-store install does not pay for all of them on one request.
     *
     * @param StoreInterface $store
     * @return StoreVerdict
     */
    public function forStore(StoreInterface $store): StoreVerdict
    {
        $storeId = (int) $store->getId();
        if (isset($this->memo[$storeId])) {
            return $this->memo[$storeId];
        }

        $cached = $this->loadCached($storeId);
        if ($cached !== null) {
            $this->memo[$storeId] = $cached;
            return $cached;
        }

        $verdict = $this->compute($store);
        $this->saveCached($verdict);
        $this->memo[$storeId] = $verdict;

        return $verdict;
    }

    /**
     * Run the three probes for one store.
     *
     * Guarded end to end: building the collector list instantiates THIRD-PARTY
     * collectors, including the competitor's. A heavy or throwing constructor
     * there must not become a stack trace on the admin dashboard, so a failure
     * to compute becomes its own reportable verdict instead.
     *
     * @param StoreInterface $store
     * @return StoreVerdict
     */
    private function compute(StoreInterface $store): StoreVerdict
    {
        $storeId = (int) $store->getId();
        $storeName = (string) $store->getName();

        try {
            $collectors = $this->collectorFactory->create(['store' => $store])->getCollectors();
        } catch (\Throwable $e) {
            return new StoreVerdict(
                $storeId,
                $storeName,
                null,
                false,
                [],
                [],
                $e->getMessage()
            );
        }

        $taxCollector = $collectors[self::TAX_TOTAL_CODE] ?? null;
        if ($taxCollector === null) {
            return new StoreVerdict($storeId, $storeName, null, false);
        }

        $owned = $taxCollector instanceof Tax;
        $activeClass = $this->resolveClass($taxCollector);

        return new StoreVerdict(
            $storeId,
            $storeName,
            $activeClass,
            $owned,
            $owned ? $this->findInterceptors($activeClass) : [],
            $this->findLaterCollectors($collectors)
        );
    }

    /**
     * `around` plugins wrapping collect() on the winning collector.
     *
     * Only meaningful when we own the slot: if a competitor owns it, whatever
     * wraps THEIR collect() is their business, and reporting it would bury the
     * finding that actually matters.
     *
     * Walks the around chain the way \Magento\Framework\Interception\Interceptor
     * does — getNext() returns only the next around code, so each hop feeds the
     * following call.
     *
     * @param string $type Non-interceptor class name of the winning collector
     * @return string[]
     */
    private function findInterceptors(string $type): array
    {
        $interceptors = [];
        $seen = [];

        try {
            $info = $this->pluginList->getNext($type, self::COLLECT_METHOD);
            while (is_array($info) && isset($info[DefinitionInterface::LISTENER_AROUND])) {
                $code = $info[DefinitionInterface::LISTENER_AROUND];
                if (isset($seen[$code])) {
                    break;
                }
                $seen[$code] = true;

                $plugin = $this->pluginList->getPlugin($type, $code);
                if ($plugin !== null) {
                    $class = $this->resolveClass($plugin);
                    if (!$this->isOurs($class)) {
                        $interceptors[] = $class;
                    }
                }

                $info = $this->pluginList->getNext($type, self::COLLECT_METHOD, $code);
            }
        } catch (\Throwable $e) {
            // A broken plugin config must not sink the whole verdict; the
            // ownership finding above is the load-bearing one.
            return $interceptors;
        }

        return $interceptors;
    }

    /**
     * Non-core collectors ordered after the tax total.
     *
     * Advisory: running later does not prove a collector writes tax. Reported
     * so support can see the candidates when tax is computed and then vanishes.
     *
     * @param array<string, mixed> $collectors Sorted collector array, keyed by total code
     * @return string[]
     */
    private function findLaterCollectors(array $collectors): array
    {
        $codes = array_keys($collectors);
        $taxPosition = array_search(self::TAX_TOTAL_CODE, $codes, true);
        if ($taxPosition === false) {
            return [];
        }

        $later = [];
        foreach (array_slice($codes, (int) $taxPosition + 1) as $code) {
            $class = $this->resolveClass($collectors[$code]);
            if ($this->isOurs($class) || strpos($class, self::CORE_NAMESPACE) === 0) {
                continue;
            }
            $later[] = $class;
        }

        return array_values(array_unique($later));
    }

    /**
     * Class name of an object, unwrapping Magento's generated interceptor.
     *
     * Without this, any class that happens to have plugins reports as
     * "Foo\Bar\Interceptor", which names nothing a merchant can act on.
     *
     * @param object $object
     * @return string
     */
    private function resolveClass($object): string
    {
        $class = get_class($object);
        if ($object instanceof InterceptorInterface) {
            $parent = get_parent_class($object);
            if ($parent !== false) {
                return $parent;
            }
        }

        return $class;
    }

    /**
     * @param string $class
     * @return bool
     */
    private function isOurs(string $class): bool
    {
        return strpos($class, self::OWN_NAMESPACE) === 0;
    }

    /**
     * @param int $storeId
     * @return StoreVerdict|null
     */
    private function loadCached(int $storeId): ?StoreVerdict
    {
        $raw = $this->cache->load(self::CACHE_KEY_PREFIX . $storeId);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = $this->serializer->unserialize($raw);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return new StoreVerdict(
            (int) ($data['store_id'] ?? $storeId),
            (string) ($data['store_name'] ?? ''),
            $data['active_class'] ?? null,
            (bool) ($data['owned'] ?? false),
            (array) ($data['interceptors'] ?? []),
            (array) ($data['later_collectors'] ?? []),
            $data['failure_reason'] ?? null
        );
    }

    /**
     * Unhealthy verdicts are cached too, so a store with a conflict does not
     * rebuild the collector list on every admin page render.
     *
     * @param StoreVerdict $verdict
     * @return void
     */
    private function saveCached(StoreVerdict $verdict): void
    {
        $this->cache->save(
            $this->serializer->serialize([
                'store_id' => $verdict->getStoreId(),
                'store_name' => $verdict->getStoreName(),
                'active_class' => $verdict->getActiveCollectorClass(),
                'owned' => $verdict->isOwned(),
                'interceptors' => $verdict->getInterceptors(),
                'later_collectors' => $verdict->getLaterCollectors(),
                'failure_reason' => $verdict->getFailureReason(),
            ]),
            self::CACHE_KEY_PREFIX . $verdict->getStoreId()
        );
    }
}
