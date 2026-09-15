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

use Magento\Framework\Filesystem\Driver\File;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogDigest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogFileReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogPathResolver;

/**
 * logs/: the TaxCloud log and its rotations, plus the TaxCloud-related lines of
 * Magento's system.log and exception.log.
 *
 * Global bundles take the newest data inside the log window (default: the last
 * 10 MB or 7 days, whichever is smaller). Per-order bundles instead take the
 * records correlated with the order — by the order_increment_id / quote_id the
 * gateway logger binds, or by the increment id appearing in the message — with
 * a few surrounding records for context.
 *
 * Every record is scrubbed (credentials always, customer details on request)
 * before it touches the staging file.
 */
class LogsSection implements SectionInterface
{
    /**
     * How much of system.log / exception.log is scanned, as a multiple of the
     * log window. Those files are shared with every other module, so they are
     * searched rather than copied, and the search itself is bounded.
     */
    private const MAGENTO_LOG_SCAN_MULTIPLIER = 5;

    /**
     * How much TaxCloud log a per-order bundle may scan, as a multiple of the
     * log window, starting from the order's checkout.
     */
    private const ORDER_SCAN_MULTIPLIER = 20;

    /**
     * Records of Magento's shared logs that concern TaxCloud or tax collection.
     */
    private const MAGENTO_LOG_FILTER =
        '/taxcloud|Taxcloud\\\\Magento2|Magento\\\\Tax\\\\|tax[ _]?collect|\btax\b|sales_?tax/i';

    public const STATUS_OK = 'ok';
    public const STATUS_MISSING = 'missing';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_UNREADABLE = 'unreadable';

    /**
     * @var LogPathResolver
     */
    private $pathResolver;

    /**
     * @var LogFileReader
     */
    private $reader;

    /**
     * @var File
     */
    private $driver;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * Digest of the bundle being collected; fresh for every collect() call.
     *
     * @var LogDigest|null
     */
    private $digest;

    /**
     * @param LogPathResolver $pathResolver
     * @param LogFileReader   $reader
     * @param File            $driver
     * @param TaxcloudConfig  $config
     */
    public function __construct(
        LogPathResolver $pathResolver,
        LogFileReader $reader,
        File $driver,
        TaxcloudConfig $config
    ) {
        $this->pathResolver = $pathResolver;
        $this->reader = $reader;
        $this->driver = $driver;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'logs';
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
        $request = $context->getRequest();
        $this->digest = new LogDigest();
        $now = time();
        $notBefore = $now - $request->getLogMaxDays() * 86400;

        $modes = [];
        foreach ($context->getScope()->getStores() as $store) {
            $mode = $this->config->getLoggingMode($store->getId());
            $modes[(string) $store->getCode()] = $mode === TaxcloudConfig::LOGGING_ADVANCED
                ? 'advanced'
                : ($mode === TaxcloudConfig::LOGGING_BASIC ? 'basic' : 'disabled');
        }

        $taxcloudPath = $this->pathResolver->getTaxcloudLogPath();
        $data = [
            'window' => [
                'name' => $request->getLogWindow(),
                'max_bytes' => $request->getLogMaxBytes(),
                'max_days' => $request->getLogMaxDays(),
                'not_before' => gmdate('c', $notBefore),
            ],
            'logging_modes' => $modes,
            'taxcloud_log' => $this->describe($taxcloudPath),
            'files' => [],
        ];

        $sources = $this->taxcloudSources($taxcloudPath);

        if ($context->getOrder() !== null) {
            $data['order_correlation'] = $this->collectForOrder($context, $archive, $sources, $data);
        } else {
            $this->collectWindow($context, $archive, $sources, $notBefore, $data);
        }

        $magentoLogs = ['system.log' => 'logs/system-taxcloud.log', 'exception.log' => 'logs/exception-taxcloud.log'];
        foreach ($magentoLogs as $fileName => $entry) {
            $path = $this->pathResolver->getMagentoLogPath($fileName);
            $data['files'][$entry] = $this->collectFiltered($context, $archive, $path, $entry, $notBefore);
        }

        $data['digest'] = $this->digest->toArray();

        return $data;
    }

    /**
     * Newest TaxCloud data inside the window, current log first, then
     * rotations newest first, until the byte budget is spent.
     *
     * @param BundleContext $context
     * @param BundleArchive $archive
     * @param array         $sources
     * @param int           $notBefore
     * @param array         $data
     * @return void
     */
    private function collectWindow(
        BundleContext $context,
        BundleArchive $archive,
        array $sources,
        int $notBefore,
        array &$data
    ): void {
        $budget = $context->getRequest()->getLogMaxBytes();

        foreach ($sources as $source) {
            $entry = 'logs/' . preg_replace('/\.gz$/', '', $this->fileName($source['path']));
            if ($budget <= 0) {
                $data['files'][$entry] = ['source' => $source['path'], 'included' => false,
                    'reason' => 'log window size cap reached'];
                continue;
            }
            if ($source['mtime'] < $notBefore) {
                $data['files'][$entry] = ['source' => $source['path'], 'included' => false,
                    'reason' => 'last written before the log window'];
                continue;
            }

            try {
                $result = $this->stageTail($context, $source, $notBefore, $budget, $entry);
            } catch (\Throwable $e) {
                $data['files'][$entry] = ['source' => $source['path'], 'included' => false,
                    'reason' => 'unreadable: ' . $context->scrub($e->getMessage())];
                continue;
            }

            $budget -= $result['source_bytes'];
            if ($result['bytes'] > 0) {
                $archive->addStagedFile($entry, $result['staged'], $result['bytes']);
            }
            $data['files'][$entry] = $result['meta'] + ['included' => $result['bytes'] > 0];
        }
    }

    /**
     * Stage the in-window tail of one TaxCloud log file.
     *
     * @param BundleContext $context
     * @param array         $source
     * @param int           $notBefore
     * @param int           $budget
     * @param string        $entry
     * @return array{staged: string, bytes: int, source_bytes: int, meta: array}
     */
    private function stageTail(BundleContext $context, array $source, int $notBefore, int $budget, string $entry): array
    {
        $path = $source['path'];
        $cleanup = null;

        if ($source['gzip']) {
            // Decompress the in-window records to scratch, then tail that.
            $plain = $context->getWorkDir() . '/' . hash('sha256', $path) . '.decompressed';
            $out = $this->driver->fileOpen($plain, 'wb');
            try {
                $this->reader->readGzip($path, $notBefore, function ($record) use ($out) {
                    $this->driver->fileWrite($out, $record);
                });
            } finally {
                $this->driver->fileClose($out);
            }
            $path = $plain;
            $cleanup = $plain;
        }

        $size = (int) ($this->driver->stat($path)['size'] ?? 0);
        $byTime = $this->reader->offsetForTime($path, $notBefore);
        $bySize = max(0, $size - $budget);
        $start = max($byTime, $bySize);

        $staged = $this->stagingPath($context, $entry);
        $out = $this->driver->fileOpen($staged, 'wb');
        $bytes = 0;
        try {
            $sink = function (
                $record,
                $timestamp
            ) use (
                $context,
                $out,
                $entry,
                &$bytes
            ) {
                $clean = $context->scrub($record);
                $this->driver->fileWrite($out, $clean);
                $bytes += strlen($clean);
                $this->digest->add($entry, $clean, $timestamp, true);
            };
            $stats = $this->reader->readRange($path, $start, null, $notBefore, $sink);
        } finally {
            $this->driver->fileClose($out);
            if ($cleanup !== null) {
                $this->driver->deleteFile($cleanup);
            }
        }

        return [
            'staged' => $staged,
            'bytes' => $bytes,
            'source_bytes' => max(0, $size - $start),
            'meta' => [
                'source' => $source['path'],
                'source_size' => $source['size'],
                'records' => $stats['records'],
                'bytes' => $bytes,
                'first_record_at' => $stats['first_timestamp'] !== null ? gmdate('c', $stats['first_timestamp']) : null,
                'last_record_at' => $stats['last_timestamp'] !== null ? gmdate('c', $stats['last_timestamp']) : null,
                'truncated_by_size' => $bySize > $byTime,
            ],
        ];
    }

    /**
     * Records correlated with the order, with surrounding context.
     *
     * @param BundleContext $context
     * @param BundleArchive $archive
     * @param array         $sources
     * @param array         $data
     * @return array
     */
    private function collectForOrder(
        BundleContext $context,
        BundleArchive $archive,
        array $sources,
        array &$data
    ): array {
        $order = $context->getOrder();
        $request = $context->getRequest();
        $increment = (string) $order->getIncrementId();
        $quoteId = (string) $order->getQuoteId();

        $createdAt = strtotime((string) $order->getCreatedAt() . ' UTC') ?: time();
        // Checkout lookups precede placement; an abandoned-then-recovered cart
        // can precede it by days.
        $from = $createdAt - 3 * 86400;
        $scanBudget = $request->getLogMaxBytes() * self::ORDER_SCAN_MULTIPLIER;
        $outputCap = $request->getLogMaxBytes();
        $contextLines = $request->getContextLines();

        $patterns = ['/"order_increment_id":"' . preg_quote($increment, '/') . '"/'];
        if ($quoteId !== '' && $quoteId !== '0') {
            $patterns[] = '/"quote_id":"' . preg_quote($quoteId, '/') . '"/';
        }
        // Lines written before correlation ids existed name the order in prose.
        $patterns[] = '/(?<![\w-])' . preg_quote($increment, '/') . '(?![\w-])/';

        $staged = $this->stagingPath($context, 'logs/taxcloud.log');
        $out = $this->driver->fileOpen($staged, 'wb');
        $state = [
            'bytes' => 0,
            'matched' => 0,
            'emitted' => 0,
            'buffer' => [],
            'after' => 0,
            'last_emitted' => false,
            'capped' => false,
            'correlation_ids' => [],
            'fallback_records' => 0,
            'taxcloud_lookup_records' => 0,
            'lookup_error_records' => 0,
        ];

        $sink = function ($record, $timestamp = null) use (
            $context,
            $out,
            $patterns,
            $contextLines,
            $outputCap,
            &$state
        ) {
            if ($state['capped']) {
                return;
            }
            $isMatch = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $record)) {
                    $isMatch = true;
                    break;
                }
            }

            $write = function (string $text, ?int $ts = null) use ($context, $out, $outputCap, &$state) {
                $clean = $context->scrub($text);
                $this->driver->fileWrite($out, $clean);
                $this->digest->add('logs/taxcloud.log', $clean, $ts, true);
                $state['bytes'] += strlen($clean);
                $state['emitted']++;
                if ($state['bytes'] >= $outputCap) {
                    $state['capped'] = true;
                }
            };

            if ($isMatch) {
                $state['matched']++;
                if (preg_match('/"correlation_id":"([0-9a-f]+)"/', $record, $m)) {
                    $state['correlation_ids'][$m[1]] = true;
                }
                if (stripos($record, 'falling back to Magento tax rates') !== false) {
                    $state['fallback_records']++;
                }
                if (preg_match(
                    '/Caching lookupTaxes result|lookupTaxes RESPONSE|"operation":"lookup".*Using Cache/',
                    $record
                )) {
                    $state['taxcloud_lookup_records']++;
                }
                if (stripos($record, 'Error encountered during lookupTaxes') !== false) {
                    $state['lookup_error_records']++;
                }
                if (!$state['last_emitted'] && $state['emitted'] > 0) {
                    $write("--\n");
                }
                foreach ($state['buffer'] as [$buffered, $bufferedTs]) {
                    $write($buffered, $bufferedTs);
                }
                $state['buffer'] = [];
                $write($record, $timestamp);
                $state['after'] = $contextLines;
                $state['last_emitted'] = true;
                return;
            }

            if ($state['after'] > 0) {
                $write($record, $timestamp);
                $state['after']--;
                $state['last_emitted'] = true;
                return;
            }

            $state['last_emitted'] = false;
            if ($contextLines > 0) {
                $state['buffer'][] = [$record, $timestamp];
                if (count($state['buffer']) > $contextLines) {
                    array_shift($state['buffer']);
                }
            }
        };

        $scanned = [];
        try {
            // Oldest first: the order's story reads forward from checkout.
            foreach (array_reverse($sources) as $source) {
                if ($state['capped'] || $scanBudget <= 0) {
                    break;
                }
                if ($source['mtime'] < $from) {
                    continue;
                }
                $state['buffer'] = [];
                $state['after'] = 0;
                if ($source['gzip']) {
                    $stats = $this->reader->readGzip($source['path'], $from, $sink);
                    $scanBudget -= $stats['bytes_read'];
                } else {
                    $size = $source['size'];
                    $start = $this->reader->offsetForTime($source['path'], $from);
                    $end = min($size, $start + $scanBudget);
                    $stats = $this->reader->readRange($source['path'], $start, $end, $from, $sink);
                    $scanBudget -= ($end - $start);
                }
                $scanned[] = $source['path'];
            }
        } finally {
            $this->driver->fileClose($out);
        }

        if ($state['bytes'] > 0) {
            $archive->addStagedFile('logs/taxcloud.log', $staged, $state['bytes']);
        }

        $data['files']['logs/taxcloud.log'] = [
            'source' => array_column($sources, 'path'),
            'included' => $state['bytes'] > 0,
            'bytes' => $state['bytes'],
            'records' => $state['emitted'],
            'truncated_by_size' => $state['capped'],
        ];

        return [
            'order_increment_id' => $increment,
            'quote_id' => $quoteId,
            'scanned_from' => gmdate('c', $from),
            'scanned_files' => $scanned,
            'scan_budget_exhausted' => $scanBudget <= 0,
            'correlated_records' => $state['matched'],
            'context_lines' => $contextLines,
            'correlation_ids' => array_map('strval', array_keys($state['correlation_ids'])),
            'tax_source_evidence' => [
                'taxcloud_lookup_records' => $state['taxcloud_lookup_records'],
                'lookup_error_records' => $state['lookup_error_records'],
                'magento_fallback_records' => $state['fallback_records'],
            ],
        ];
    }

    /**
     * TaxCloud/tax records from one of Magento's shared logs.
     *
     * @param BundleContext $context
     * @param BundleArchive $archive
     * @param string        $path
     * @param string        $entry
     * @param int           $notBefore
     * @return array
     */
    private function collectFiltered(
        BundleContext $context,
        BundleArchive $archive,
        string $path,
        string $entry,
        int $notBefore
    ): array {
        $meta = $this->describe($path);
        if ($meta['status'] !== self::STATUS_OK) {
            return $meta + ['included' => false];
        }

        $request = $context->getRequest();
        $size = (int) $meta['size'];
        $scan = $request->getLogMaxBytes() * self::MAGENTO_LOG_SCAN_MULTIPLIER;
        $start = max(max(0, $size - $scan), $this->reader->offsetForTime($path, $notBefore));
        $cap = $request->getLogMaxBytes();

        $staged = $this->stagingPath($context, $entry);
        $out = $this->driver->fileOpen($staged, 'wb');
        $bytes = 0;
        $records = 0;
        try {
            $sink = function (
                $record,
                $timestamp
            ) use (
                $context,
                $out,
                $cap,
                $entry,
                &$bytes,
                &$records
            ) {
                if ($bytes >= $cap || !preg_match(self::MAGENTO_LOG_FILTER, $record)) {
                    return;
                }
                $clean = $context->scrub($record);
                $this->digest->add($entry, $clean, $timestamp);
                $this->driver->fileWrite($out, $clean);
                $bytes += strlen($clean);
                $records++;
            };
            $stats = $this->reader->readRange($path, $start, null, $notBefore, $sink);
        } finally {
            $this->driver->fileClose($out);
        }

        if ($bytes > 0) {
            $archive->addStagedFile($entry, $staged, $bytes);
        }

        return $meta + [
            'included' => $bytes > 0,
            'filter' => 'records mentioning TaxCloud or tax collection',
            'scanned_bytes' => max(0, $size - $start),
            'scanned_records' => $stats['records'],
            'matching_records' => $records,
            'bytes' => $bytes,
            'truncated_by_size' => $bytes >= $cap,
        ];
    }

    /**
     * The TaxCloud log and its rotations, newest first.
     *
     * @param string $path
     * @return array<int, array{path: string, size: int, mtime: int, gzip: bool}>
     */
    private function taxcloudSources(string $path): array
    {
        $sources = [];
        $meta = $this->describe($path);
        if ($meta['status'] === self::STATUS_OK) {
            $sources[] = ['path' => $path, 'size' => (int) $meta['size'], 'mtime' => (int) $meta['mtime_unix'],
                'gzip' => false];
        }

        $dir = $this->driver->getParentDirectory($path);
        $base = $this->fileName($path);
        try {
            $candidates = $this->driver->search($base . '[.-]*', $dir);
        } catch (\Throwable $e) {
            $candidates = [];
        }

        $rotated = [];
        foreach ($candidates as $candidate) {
            $name = $this->fileName($candidate);
            $isRotation = strpos($name, $base) === 0 && preg_match('/^[.\-]/', substr($name, strlen($base)));
            if ($name === $base || !$isRotation) {
                continue;
            }
            $info = $this->describe($candidate);
            if ($info['status'] !== self::STATUS_OK) {
                continue;
            }
            $rotated[] = ['path' => $candidate, 'size' => (int) $info['size'], 'mtime' => (int) $info['mtime_unix'],
                'gzip' => substr($name, -3) === '.gz'];
        }
        usort($rotated, static function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });

        return array_merge($sources, $rotated);
    }

    /**
     * Existence, size and readability of a log file.
     *
     * @param string $path
     * @return array
     */
    private function describe(string $path): array
    {
        try {
            if (!$this->driver->isExists($path)) {
                return ['path' => $path, 'status' => self::STATUS_MISSING];
            }
            if (!$this->driver->isReadable($path)) {
                return ['path' => $path, 'status' => self::STATUS_UNREADABLE];
            }
            $stat = $this->driver->stat($path);
        } catch (\Throwable $e) {
            return ['path' => $path, 'status' => self::STATUS_UNREADABLE];
        }

        $size = (int) ($stat['size'] ?? 0);
        $mtime = (int) ($stat['mtime'] ?? 0);

        return [
            'path' => $path,
            'status' => $size > 0 ? self::STATUS_OK : self::STATUS_EMPTY,
            'size' => $size,
            'modified_at' => gmdate('c', $mtime),
            'mtime_unix' => $mtime,
        ];
    }

    /**
     * Last path segment.
     *
     * @param string $path
     * @return string
     */
    private function fileName(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /**
     * @param BundleContext $context
     * @param string        $entry
     * @return string
     */
    private function stagingPath(BundleContext $context, string $entry): string
    {
        return $context->getWorkDir() . '/' . str_replace('/', '__', $entry);
    }
}
