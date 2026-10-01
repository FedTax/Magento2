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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialInventory;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\SectionInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Summary\SummaryRenderer;

/**
 * Builds a diagnostics bundle: a ZIP of the store's TaxCloud state, designed to
 * be attached to a support ticket and read cold by an engineer or an AI
 * assistant.
 *
 * HTTP-free: the admin controller (global and per-order) and the CLI command
 * all call generate(), so every surface produces the same bundle.
 *
 * Guarantees, in order of importance:
 *  - No credential value is ever written. Every byte passes through the
 *    context's scrubber: credential patterns plus every credential value
 *    configured anywhere in the installation.
 *  - A failing section never fails the bundle. It is recorded in manifest.json
 *    and summary.md, and the rest is still delivered.
 *  - Scratch files are removed on success and failure alike.
 */
class BundleGenerator
{
    /**
     * Version of the bundle layout. Bump on any breaking change to file names
     * or JSON shapes so readers can detect the format they are given.
     */
    public const SCHEMA_VERSION = '1.0';

    public const MODULE_NAME = 'Taxcloud_Magento2';

    /**
     * @var ScopeResolver
     */
    private $scopeResolver;

    /**
     * @var BundleWorkspace
     */
    private $workspace;

    /**
     * @var CredentialInventory
     */
    private $credentialInventory;

    /**
     * @var PiiRedactor
     */
    private $piiRedactor;

    /**
     * @var SummaryRenderer
     */
    private $summaryRenderer;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var PackageInfo
     */
    private $packageInfo;

    /**
     * @var SectionInterface[]
     */
    private $sections;

    /**
     * @param ScopeResolver            $scopeResolver
     * @param BundleWorkspace          $workspace
     * @param CredentialInventory      $credentialInventory
     * @param PiiRedactor              $piiRedactor
     * @param SummaryRenderer          $summaryRenderer
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder    $searchCriteriaBuilder
     * @param TimezoneInterface        $timezone
     * @param PackageInfo              $packageInfo
     * @param SectionInterface[]       $sections Run in this order; wired in di.xml
     */
    public function __construct(
        ScopeResolver $scopeResolver,
        BundleWorkspace $workspace,
        CredentialInventory $credentialInventory,
        PiiRedactor $piiRedactor,
        SummaryRenderer $summaryRenderer,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        TimezoneInterface $timezone,
        PackageInfo $packageInfo,
        array $sections = []
    ) {
        $this->scopeResolver = $scopeResolver;
        $this->workspace = $workspace;
        $this->credentialInventory = $credentialInventory;
        $this->piiRedactor = $piiRedactor;
        $this->summaryRenderer = $summaryRenderer;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->timezone = $timezone;
        $this->packageInfo = $packageInfo;
        $this->sections = array_values(array_filter($sections, static function ($section) {
            return $section instanceof SectionInterface;
        }));
    }

    /**
     * Generate a bundle. The caller owns the returned file and must remove it.
     *
     * @param BundleRequest $request
     * @return BundleResult
     * @throws LocalizedException When the order or scope does not exist, or the archive cannot be written
     */
    public function generate(BundleRequest $request): BundleResult
    {
        $order = $this->loadOrder($request);
        try {
            $scope = $this->scopeResolver->resolve($request, $order);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new LocalizedException(__('The requested website or store view does not exist.'), $e);
        }

        $now = time();
        $fileName = sprintf(
            'taxcloud-diagnostics-%s-%s.zip',
            preg_replace('/[^A-Za-z0-9_\-]/', '_', $scope->getCode()),
            gmdate('Ymd-His', $now)
        );

        $workDir = $this->workspace->createWorkDir();
        $zipPath = $workDir . '.zip';
        $archive = null;

        try {
            $failures = [];
            try {
                $secrets = $this->credentialInventory->collectSecrets();
            } catch (\Throwable $e) {
                // Pattern-based redaction still applies to everything; only the
                // exact-value safety net is weaker, and the reader is told.
                $secrets = [];
                $failures[] = ['section' => 'credential_inventory', 'message' => $e->getMessage(),
                    'exception' => get_class($e)];
            }

            $context = new BundleContext(
                $request,
                $scope,
                $order,
                $workDir,
                $secrets,
                $request->isRedactPii() ? $this->piiRedactor : null
            );
            $archive = new BundleArchive($zipPath, $context);

            foreach ($failures as $i => $failure) {
                $failures[$i]['message'] = $context->scrub($failure['message']);
            }

            $sectionData = [];
            foreach ($this->sections as $section) {
                try {
                    if (!$section->isApplicable($context)) {
                        continue;
                    }
                    $data = $section->collect($context, $archive);
                    $context->setSectionData($section->getCode(), $data);
                    $sectionData[$section->getCode()] = $data;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'section' => $section->getCode(),
                        'message' => $context->scrub($e->getMessage()),
                        'exception' => get_class($e),
                    ];
                }
            }

            $meta = $this->meta($request, $scope, $order, $now);

            try {
                $summary = $this->summaryRenderer->render($meta, $sectionData, $failures);
            } catch (\Throwable $e) {
                $failures[] = ['section' => 'summary', 'message' => $context->scrub($e->getMessage()),
                    'exception' => get_class($e)];
                $summary = "# TaxCloud diagnostics\n\nBundle schema " . self::SCHEMA_VERSION
                    . "\n\n## Blockers\n\n- **The summary could not be rendered: "
                    . $e->getMessage() . "** See manifest.json and the JSON files.\n";
            }
            $archive->addString('summary.md', $summary);

            $manifest = $meta + [
                'files' => [],
                'failures' => $failures,
            ];
            foreach ($archive->getEntries() as $name => $bytes) {
                $manifest['files'][] = ['path' => $name, 'bytes' => $bytes];
            }
            $manifest['files'][] = ['path' => 'manifest.json', 'bytes' => null];
            $archive->addJson('manifest.json', $manifest);

            $entries = $archive->getEntries();
            $archive->close();
            $archive = null;
        } catch (\Throwable $e) {
            $this->workspace->remove($zipPath);
            throw $e instanceof LocalizedException
                ? $e
                : new LocalizedException(
                    __('The diagnostics bundle could not be written: %1', $e->getMessage()),
                    $e instanceof \Exception ? $e : null
                );
        } finally {
            // Staged files are only needed until the archive closes.
            $this->workspace->remove($workDir);
        }

        return new BundleResult(
            $zipPath,
            $fileName,
            $failures,
            $entries,
            $scope,
            $order !== null ? (string) $order->getIncrementId() : null
        );
    }

    /**
     * @param BundleRequest $request
     * @return OrderInterface|null
     * @throws LocalizedException
     */
    private function loadOrder(BundleRequest $request): ?OrderInterface
    {
        if (!$request->isForOrder()) {
            return null;
        }

        try {
            if ($request->getOrderId() !== null) {
                return $this->orderRepository->get($request->getOrderId());
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new LocalizedException(__('Order %1 does not exist.', $request->getOrderId()), $e);
        }

        // Increment ids are sequenced per store, so the same one can exist in
        // several stores; a store scope on the request narrows the match.
        $this->searchCriteriaBuilder->addFilter(OrderInterface::INCREMENT_ID, $request->getOrderIncrementId());
        if ($request->getScopeType() === BundleRequest::SCOPE_STORE && $request->getScopeId() !== null) {
            $this->searchCriteriaBuilder->addFilter(OrderInterface::STORE_ID, $request->getScopeId());
        }
        $orders = array_values($this->orderRepository->getList(
            $this->searchCriteriaBuilder->setPageSize(10)->create()
        )->getItems());

        if ($orders === []) {
            throw new LocalizedException(__('Order #%1 does not exist.', $request->getOrderIncrementId()));
        }
        if (count($orders) > 1) {
            $storeIds = array_map(static function (OrderInterface $order) {
                return (string) $order->getStoreId();
            }, $orders);
            throw new LocalizedException(__(
                'Order #%1 exists in more than one store (store ids %2). Pass the store id to choose one.',
                $request->getOrderIncrementId(),
                implode(', ', $storeIds)
            ));
        }

        return $orders[0];
    }

    /**
     * Facts shared by the manifest and the summary header.
     *
     * @param BundleRequest       $request
     * @param BundleScope         $scope
     * @param OrderInterface|null $order
     * @param int                 $now
     * @return array
     */
    private function meta(BundleRequest $request, BundleScope $scope, ?OrderInterface $order, int $now): array
    {
        $stores = $scope->getStores();
        $firstStore = reset($stores);
        try {
            $zone = $this->timezone->getConfigTimezone(
                ScopeInterface::SCOPE_STORE,
                $firstStore ? (string) $firstStore->getId() : null
            );
            $storeTime = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($zone))->format('c');
        } catch (\Throwable $e) {
            $zone = null;
            $storeTime = null;
        }

        try {
            $moduleVersion = $this->packageInfo->getVersion(self::MODULE_NAME);
        } catch (\Throwable $e) {
            $moduleVersion = null;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'module_version' => $moduleVersion,
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'generated_at_store' => $storeTime,
            'store_timezone' => $zone,
            'generated_by' => $request->getGeneratedBy(),
            'origin' => $request->getOrigin(),
            'scope' => $scope->toArray(),
            'scope_label' => $scope->getLabel(),
            'order_increment_id' => $order !== null ? (string) $order->getIncrementId() : null,
            'redaction' => [
                'credentials' => 'always redacted; fingerprints only',
                'customer_details' => $request->isRedactPii() ? 'masked' : 'included as-is',
                'marker' => $request->isRedactPii() ? PiiRedactor::MARKER : null,
            ],
            'redact_pii' => $request->isRedactPii(),
            'log_window' => [
                'name' => $request->getLogWindow(),
                'max_bytes' => $request->getLogMaxBytes(),
                'max_days' => $request->getLogMaxDays(),
            ],
            'probe_enabled' => $request->isRunProbe(),
        ];
    }
}
