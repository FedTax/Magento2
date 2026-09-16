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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Section;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Module\PackageInfo;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ConfigSourceReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\DatabaseUpgradeCheck;

/**
 * environment.json: platform and infrastructure facts.
 *
 * Deployment settings come only through the reader's whitelist, and only as
 * backend names — never hosts, ports, credentials or connection strings.
 */
class EnvironmentSection implements SectionInterface
{
    public const FILE = 'environment.json';

    public const MODULE_NAME = 'Taxcloud_Magento2';

    /**
     * PHP extensions the extension or its transports depend on.
     */
    private const EXTENSIONS = ['soap', 'curl', 'openssl', 'zip', 'intl', 'json'];

    /**
     * Cron jobs whose last run is reported individually: the ones whose absence
     * shows up as TaxCloud symptoms (stale indexes, unprocessed queues, async
     * order grids).
     */
    private const TRACKED_CRON_JOBS = [
        'indexer_reindex_all_invalid',
        'indexer_update_all_views',
        'consumers_runner',
        'sales_grid_order_async_insert',
        'sales_clean_quotes',
    ];

    /**
     * A running job older than this is reported as stuck.
     */
    private const STUCK_AFTER_SECONDS = 7200;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var PackageInfo
     */
    private $packageInfo;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var ConfigSourceReader
     */
    private $sourceReader;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var DatabaseUpgradeCheck
     */
    private $upgradeCheck;

    /**
     * @param ProductMetadataInterface $productMetadata
     * @param PackageInfo              $packageInfo
     * @param State                    $appState
     * @param ConfigSourceReader       $sourceReader
     * @param ResourceConnection       $resource
     * @param ScopeConfigInterface     $scopeConfig
     * @param DatabaseUpgradeCheck     $upgradeCheck
     */
    public function __construct(
        ProductMetadataInterface $productMetadata,
        PackageInfo $packageInfo,
        State $appState,
        ConfigSourceReader $sourceReader,
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig,
        DatabaseUpgradeCheck $upgradeCheck
    ) {
        $this->productMetadata = $productMetadata;
        $this->packageInfo = $packageInfo;
        $this->appState = $appState;
        $this->sourceReader = $sourceReader;
        $this->resource = $resource;
        $this->scopeConfig = $scopeConfig;
        $this->upgradeCheck = $upgradeCheck;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'environment';
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(BundleContext $context): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function collect(BundleContext $context, BundleArchive $archive): array
    {
        $data = [
            'magento' => $this->guard(function () {
                return [
                    'version' => $this->productMetadata->getVersion(),
                    'edition' => $this->productMetadata->getEdition(),
                    'deploy_mode' => $this->appState->getMode(),
                ];
            }),
            'taxcloud_module_version' => $this->guard(function () {
                return $this->packageInfo->getVersion(self::MODULE_NAME);
            }),
            'php' => $this->php(),
            'database' => $this->guard(function () {
                return ['server_version' => $this->resource->getConnection()->fetchOne('SELECT VERSION()')];
            }),
            'infrastructure' => $this->guard(function () {
                return [
                    'cache_backend' => $this->sourceReader->getDeploymentValue('cache/frontend/default/backend')
                        ?? 'file (default)',
                    'page_cache_backend' => $this->sourceReader->getDeploymentValue('cache/frontend/page_cache/backend')
                        ?? 'file (default)',
                    'session_storage' => $this->sourceReader->getDeploymentValue('session/save') ?? 'files (default)',
                ];
            }),
            'timezones' => $this->timezones($context),
            'cron' => $this->guard(function () {
                return $this->cron();
            }),
            'database_upgrade' => $this->guard(function () {
                return $this->upgradeCheck->check();
            }),
            'indexers' => $this->guard(function () {
                return $this->indexers();
            }),
        ];

        $archive->addJson(self::FILE, $data);

        return $data;
    }

    /**
     * @return array
     */
    private function php(): array
    {
        $extensions = [];
        foreach (self::EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $extensions[$extension] = [
                'loaded' => $loaded,
                'version' => $loaded ? (phpversion($extension) ?: null) : null,
            ];
        }
        if ($extensions['openssl']['loaded'] && defined('OPENSSL_VERSION_TEXT')) {
            $extensions['openssl']['library'] = OPENSSL_VERSION_TEXT;
        }

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os_family' => PHP_OS_FAMILY,
            'extensions' => $extensions,
            'ini' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'default_socket_timeout' => ini_get('default_socket_timeout'),
            ],
        ];
    }

    /**
     * @param BundleContext $context
     * @return array
     */
    private function timezones(BundleContext $context): array
    {
        $stores = [];
        foreach ($context->getScope()->getStores() as $store) {
            $stores[(string) $store->getCode()] = [
                'locale' => $this->scopeConfig->getValue(
                    'general/locale/code',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
                'timezone' => $this->scopeConfig->getValue(
                    'general/locale/timezone',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
                'base_currency' => $this->scopeConfig->getValue(
                    'currency/options/base',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
                'default_display_currency' => $this->scopeConfig->getValue(
                    'currency/options/default',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
            ];
        }

        return [
            'server' => date_default_timezone_get(),
            'php_ini_date_timezone' => ini_get('date.timezone') ?: null,
            'stores' => $stores,
        ];
    }

    /**
     * @return array
     */
    private function cron(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('cron_schedule');
        $now = time();

        $lastSuccess = $connection->fetchOne(
            $connection->select()->from($table, ['MAX(finished_at)'])->where('status = ?', 'success')
        );

        $jobs = [];
        foreach (self::TRACKED_CRON_JOBS as $job) {
            $jobs[$job] = [
                'last_success_at' => $connection->fetchOne(
                    $connection->select()->from($table, ['MAX(finished_at)'])
                        ->where('job_code = ?', $job)->where('status = ?', 'success')
                ) ?: null,
            ];
        }

        $stuck = $connection->fetchPairs(
            $connection->select()->from($table, ['job_code', 'COUNT(*)'])
                ->where('status = ?', 'running')
                ->where('executed_at < ?', gmdate('Y-m-d H:i:s', $now - self::STUCK_AFTER_SECONDS))
                ->group('job_code')
        );

        $failed = $connection->fetchAll(
            $connection->select()
                ->from($table, ['job_code', 'status', 'count' => 'COUNT(*)', 'last' => 'MAX(scheduled_at)'])
                ->where('status IN (?)', ['error', 'missed'])
                ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 86400))
                ->group(['job_code', 'status'])
                ->order('count DESC')
                ->limit(25)
        );

        return [
            'last_successful_run_at' => $lastSuccess ?: null,
            'tracked_jobs' => $jobs,
            'stuck_running' => array_map('intval', $stuck),
            'stuck_running_total' => array_sum(array_map('intval', $stuck)),
            'errors_last_24h' => array_map(static function ($row) {
                return [
                    'job_code' => $row['job_code'],
                    'status' => $row['status'],
                    'count' => (int) $row['count'],
                    'last_scheduled_at' => $row['last'],
                ];
            }, $failed),
            'error_count_last_24h' => array_sum(array_map(static function ($row) {
                return (int) $row['count'];
            }, $failed)),
        ];
    }

    /**
     * @return array
     */
    private function indexers(): array
    {
        $connection = $this->resource->getConnection();
        $indexers = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('indexer_state'), ['indexer_id', 'status', 'updated'])
        ) as $row) {
            $indexers[$row['indexer_id']] = ['status' => $row['status'], 'updated' => $row['updated']];
        }

        foreach ($connection->fetchAll(
            $connection->select()->from($this->resource->getTableName('mview_state'), ['view_id', 'mode', 'status'])
        ) as $row) {
            if (isset($indexers[$row['view_id']])) {
                $indexers[$row['view_id']]['mode'] = $row['mode'] === 'enabled' ? 'schedule' : 'realtime';
                $indexers[$row['view_id']]['mview_status'] = $row['status'];
            }
        }
        ksort($indexers);

        return [
            'invalid' => array_keys(array_filter($indexers, static function ($indexer) {
                return $indexer['status'] === 'invalid';
            })),
            'states' => $indexers,
        ];
    }

    /**
     * Run one probe of the environment; a failure becomes data, not an exception.
     *
     * @param callable $probe
     * @return mixed
     */
    private function guard(callable $probe)
    {
        try {
            return $probe();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
