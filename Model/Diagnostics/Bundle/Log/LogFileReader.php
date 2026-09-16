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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Log;

use Magento\Framework\Filesystem\Driver\File;

/**
 * Reads log records out of files that may be gigabytes long.
 *
 * Nothing here loads a file whole. Reads start from a computed byte offset —
 * the tail of the file, or the first record at a given time, found by binary
 * search over timestamps — and proceed record by record, so the cost of
 * exporting from a 2 GB Advanced-mode log is proportional to the window
 * exported, not to the file.
 *
 * A record is a line starting with a Monolog timestamp plus any continuation
 * lines (stack traces) up to the next one. Oversized records are truncated so a
 * single multi-megabyte payload line cannot exhaust memory.
 */
class LogFileReader
{
    /**
     * Longest record kept whole; the remainder is replaced by a marker.
     */
    public const MAX_RECORD_BYTES = 1048576;

    /**
     * Read size for one line fragment.
     */
    private const CHUNK = 65536;

    /**
     * Monolog record start: "[2026-09-14T10:00:00.123456+00:00]" or "[2026-09-14 10:00:00]".
     */
    private const RECORD_START = '/^\[(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(\.\d+)?([+\-]\d{2}:?\d{2}|Z)?\]/';

    /**
     * @var File
     */
    private $driver;

    /**
     * @param File $driver
     */
    public function __construct(File $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Timestamp of a record's first line, or null for a continuation line.
     *
     * @param string $line
     * @return int|null
     */
    public function parseTimestamp(string $line): ?int
    {
        if (!preg_match(self::RECORD_START, $line, $m)) {
            return null;
        }
        $zone = $m[4] ?? '';
        $ts = strtotime($m[1] . ' ' . $m[2] . ($zone !== '' ? ' ' . $zone : ' UTC'));

        return $ts === false ? null : $ts;
    }

    /**
     * Byte offset of the first record whose timestamp is at or after $time.
     *
     * Binary search over the file: logs are appended in time order, so the
     * first matching record can be found in O(log n) seeks.
     *
     * @param string $path
     * @param int    $time
     * @return int File size when every record is older
     */
    public function offsetForTime(string $path, int $time): int
    {
        $size = (int) ($this->driver->stat($path)['size'] ?? 0);
        $handle = $this->driver->fileOpen($path, 'rb');
        try {
            $low = 0;
            $high = $size;
            while ($high - $low > self::CHUNK) {
                $mid = intdiv($low + $high, 2);
                [$offset, $ts] = $this->nextRecordAt($handle, $mid, $high);
                if ($ts === null) {
                    // No record start between mid and high: the answer is at or below mid.
                    $high = $mid;
                } elseif ($ts < $time) {
                    $low = $offset + 1;
                } else {
                    $high = $offset;
                }
            }

            // Linear finish over the last small span.
            $this->alignToLineStart($handle, $low);
            $atLineStart = true;
            while (true) {
                $position = (int) ftell($handle);
                if ($position >= $size) {
                    return $size;
                }
                $line = fgets($handle, self::CHUNK);
                if ($line === false) {
                    return $size;
                }
                $ts = $atLineStart ? $this->parseTimestamp($line) : null;
                if ($ts !== null && $ts >= $time) {
                    return $position;
                }
                $atLineStart = substr($line, -1) === "\n";
            }
        } finally {
            $this->driver->fileClose($handle);
        }
    }

    /**
     * Stream the records between two byte offsets to a sink.
     *
     * Reading starts at the first record boundary at or after $start (a
     * partial first line is skipped) and stops at the first record starting
     * at or after $end. Records older than $notBefore are skipped.
     *
     * @param string   $path
     * @param int      $start
     * @param int|null $end       Null reads to end of file
     * @param int|null $notBefore Unix time; null keeps every record
     * @param callable $sink      function (string $record, ?int $timestamp): void
     * @return array{records: int, bytes_read: int, skipped_by_age: int, first_timestamp: int|null,
     *               last_timestamp: int|null}
     */
    public function readRange(string $path, int $start, ?int $end, ?int $notBefore, callable $sink): array
    {
        $stats = [
            'records' => 0,
            'bytes_read' => 0,
            'skipped_by_age' => 0,
            'first_timestamp' => null,
            'last_timestamp' => null,
        ];

        $handle = $this->driver->fileOpen($path, 'rb');
        try {
            // An offset mid-line belongs to a record we did not start.
            $aligned = $this->alignToLineStart($handle, max(0, $start));
            // Continuation lines before the first record start belong to a
            // record that began before $start: dropped, not emitted as orphans.
            $this->pump($handle, $end, $notBefore, $sink, $stats, $aligned > 0);
        } finally {
            $this->driver->fileClose($handle);
        }

        return $stats;
    }

    /**
     * Stream every record of a gzip-compressed rotated log.
     *
     * Compressed files cannot be seeked from the end, so they are read forward;
     * callers bound the cost by skipping rotated files outside the window.
     *
     * @param string   $path
     * @param int|null $notBefore
     * @param callable $sink function (string $record, ?int $timestamp): void
     * @return array
     */
    public function readGzip(string $path, ?int $notBefore, callable $sink): array
    {
        $stats = [
            'records' => 0,
            'bytes_read' => 0,
            'skipped_by_age' => 0,
            'first_timestamp' => null,
            'last_timestamp' => null,
        ];

        $handle = $this->driver->fileOpen('compress.zlib://' . $path, 'rb');
        try {
            $this->pump($handle, null, $notBefore, $sink, $stats);
        } finally {
            $this->driver->fileClose($handle);
        }

        return $stats;
    }

    /**
     * @param resource $handle
     * @param int|null $end
     * @param int|null $notBefore
     * @param callable $sink
     * @param array    $stats
     * @param bool     $skipLeadingOrphans
     * @return void
     */
    private function pump(
        $handle,
        ?int $end,
        ?int $notBefore,
        callable $sink,
        array &$stats,
        bool $skipLeadingOrphans = false
    ): void {
        $seenRecordStart = !$skipLeadingOrphans;
        $current = ['text' => '', 'timestamp' => null, 'truncated' => false];
        $atLineStart = true;

        while (true) {
            if ($end !== null && $atLineStart && ftell($handle) >= $end) {
                break;
            }
            $fragment = fgets($handle, self::CHUNK);
            if ($fragment === false) {
                break;
            }
            $stats['bytes_read'] += strlen($fragment);

            $ts = $atLineStart ? $this->parseTimestamp($fragment) : null;
            if ($ts !== null) {
                $this->flush($current, $notBefore, $sink, $stats);
                $current['timestamp'] = $ts;
                $seenRecordStart = true;
            }
            $atLineStart = substr($fragment, -1) === "\n";
            if (!$seenRecordStart) {
                continue;
            }

            if (strlen($current['text']) + strlen($fragment) <= self::MAX_RECORD_BYTES) {
                $current['text'] .= $fragment;
            } else {
                $current['truncated'] = true;
            }
        }

        $this->flush($current, $notBefore, $sink, $stats);
    }

    /**
     * Emit the record being assembled (unless it is empty or too old) and reset it.
     *
     * @param array    $current   ['text' => string, 'timestamp' => int|null, 'truncated' => bool]
     * @param int|null $notBefore
     * @param callable $sink
     * @param array    $stats
     * @return void
     */
    private function flush(array &$current, ?int $notBefore, callable $sink, array &$stats): void
    {
        $text = (string) $current['text'];
        $timestamp = $current['timestamp'];
        $truncated = (bool) $current['truncated'];
        $current = ['text' => '', 'timestamp' => null, 'truncated' => false];

        if ($text === '') {
            return;
        }
        if ($notBefore !== null && $timestamp !== null && $timestamp < $notBefore) {
            $stats['skipped_by_age']++;
            return;
        }

        if ($truncated) {
            $text = rtrim($text, "\n") . " …[record truncated by diagnostics export]\n";
        }
        $sink($text, $timestamp);
        $stats['records']++;
        if ($timestamp !== null) {
            $stats['first_timestamp'] = $stats['first_timestamp'] ?? $timestamp;
            $stats['last_timestamp'] = $timestamp;
        }
    }

    /**
     * Find the first record start at or after $offset (and before $limit).
     *
     * @param resource $handle
     * @param int      $offset
     * @param int      $limit
     * @return array{0: int, 1: int|null} [offset, timestamp]; timestamp null when none found
     */
    private function nextRecordAt($handle, int $offset, int $limit): array
    {
        $this->alignToLineStart($handle, $offset);
        while (true) {
            $position = ftell($handle);
            if ($position === false || $position >= $limit) {
                return [$limit, null];
            }
            $line = fgets($handle, self::CHUNK);
            if ($line === false) {
                return [$limit, null];
            }
            $ts = $this->parseTimestamp($line);
            if ($ts !== null) {
                return [(int) $position, $ts];
            }
            while (substr($line, -1) !== "\n") {
                $line = fgets($handle, self::CHUNK);
                if ($line === false) {
                    return [$limit, null];
                }
            }
        }
    }

    /**
     * Position the handle at the first line start at or after $offset.
     *
     * An offset that already is a line start (the byte before it is a newline)
     * is kept; anything else skips to the next line.
     *
     * @param resource $handle
     * @param int      $offset
     * @return int The aligned offset
     */
    private function alignToLineStart($handle, int $offset): int
    {
        if ($offset <= 0) {
            $this->driver->fileSeek($handle, 0);
            return 0;
        }

        $this->driver->fileSeek($handle, $offset - 1);
        if (fread($handle, 1) !== "\n") {
            $this->skipPartialLine($handle);
        }

        return (int) ftell($handle);
    }

    /**
     * Advance past the rest of the current line.
     *
     * @param resource $handle
     * @return void
     */
    private function skipPartialLine($handle): void
    {
        do {
            $fragment = fgets($handle, self::CHUNK);
        } while ($fragment !== false && substr($fragment, -1) !== "\n");
    }
}
