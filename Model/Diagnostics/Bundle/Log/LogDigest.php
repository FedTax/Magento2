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

/**
 * Condenses the log records a bundle exports into what a reader needs first.
 *
 * A bundle can carry thousands of log records; the question a support reader
 * asks of them is "what went wrong, how often, and is it still happening?".
 * This groups warning-and-above records into distinct messages — numbers,
 * ids and hashes normalised away, so "order 100000001" and "order 100000002"
 * count as one problem — with a count, first and last sighting and one sample.
 * For the TaxCloud log it also records when the extension last did each thing
 * that matters: a lookup, a capture, a refund.
 *
 * Holds only bounded summaries, never the records themselves, so it adds no
 * memory proportional to the log. Samples are scrubbed by the caller.
 */
class LogDigest
{
    /**
     * Distinct messages kept per source; further ones are only counted.
     */
    public const MAX_SIGNATURES_PER_SOURCE = 50;

    /**
     * Longest sample kept.
     */
    private const MAX_SAMPLE_LENGTH = 300;

    /**
     * "] channel.LEVEL: message"
     */
    private const LEVEL_PATTERN = '/^\[[^\]]+\]\s+[\w.\-]+\.(WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s?(.*)$/s';

    /**
     * TaxCloud log milestones: name => pattern matched against the whole record.
     */
    private const MILESTONES = [
        'last_lookup_call_at' => '/Calling lookupTaxes/',
        'last_successful_lookup_at' =>
            '/Caching lookupTaxes result|"operation":"lookup".*Using Cache|lookupTaxes RESPONSE: HTTP 2/s',
        'last_capture_success_at' => '/Order \S+ captured in TaxCloud/',
        'last_capture_failure_at' =>
            '/Order \S+ was NOT captured in TaxCloud|Error encountered during authorizeCapture/',
        'last_refund_success_at' => '/Refund for order \S+ recorded in TaxCloud/',
        'last_refund_failure_at' => '/Refund for order \S+ was NOT recorded|Error encountered during returnOrder/',
        'last_fallback_at' => '/falling back to Magento tax rates/',
    ];

    /**
     * @var array<string, array<string, array>>
     */
    private $signatures = [];

    /**
     * @var array<string, int>
     */
    private $overflow = [];

    /**
     * @var array<string, int|null>
     */
    private $milestones = [];

    /**
     * @var int|null
     */
    private $lastTaxcloudRecordAt;

    /**
     * Feed one record.
     *
     * @param string   $source    Bundle entry the record belongs to, e.g. logs/taxcloud.log
     * @param string   $record    The (already scrubbed) record
     * @param int|null $timestamp
     * @param bool     $isTaxcloudLog Whether milestones apply
     * @return void
     */
    public function add(string $source, string $record, ?int $timestamp, bool $isTaxcloudLog = false): void
    {
        if ($isTaxcloudLog && $timestamp !== null) {
            $this->lastTaxcloudRecordAt = max((int) $this->lastTaxcloudRecordAt, $timestamp);
            foreach (self::MILESTONES as $name => $pattern) {
                if (preg_match($pattern, $record)) {
                    $this->milestones[$name] = max((int) ($this->milestones[$name] ?? 0), $timestamp);
                }
            }
        }

        if (!preg_match(self::LEVEL_PATTERN, $record, $m)) {
            return;
        }
        $level = $m[1];
        $firstLine = strtok($m[2], "\n");
        $message = $this->stripContext((string) $firstLine);
        $key = $level . ' ' . $this->signature($message);

        if (!isset($this->signatures[$source][$key])) {
            if (count($this->signatures[$source] ?? []) >= self::MAX_SIGNATURES_PER_SOURCE) {
                $this->overflow[$source] = ($this->overflow[$source] ?? 0) + 1;
                return;
            }
            $this->signatures[$source][$key] = [
                'level' => $level,
                'message' => mb_substr($message, 0, self::MAX_SAMPLE_LENGTH),
                'count' => 0,
                'first_seen' => $timestamp,
                'last_seen' => $timestamp,
            ];
        }

        $entry = &$this->signatures[$source][$key];
        $entry['count']++;
        if ($timestamp !== null) {
            $entry['first_seen'] = $entry['first_seen'] === null ? $timestamp : min($entry['first_seen'], $timestamp);
            $entry['last_seen'] = max((int) $entry['last_seen'], $timestamp);
        }
        unset($entry);
    }

    /**
     * @return array{problems: array<string, array>, overflow: array<string, int>, activity: array}
     */
    public function toArray(): array
    {
        $problems = [];
        foreach ($this->signatures as $source => $entries) {
            $list = array_values($entries);
            usort($list, static function ($a, $b) {
                return [$b['last_seen'], $b['count']] <=> [$a['last_seen'], $a['count']];
            });
            foreach ($list as &$entry) {
                $entry['first_seen'] = $entry['first_seen'] !== null ? gmdate('c', $entry['first_seen']) : null;
                $entry['last_seen'] = $entry['last_seen'] !== null ? gmdate('c', $entry['last_seen']) : null;
            }
            unset($entry);
            $problems[$source] = $list;
        }

        $activity = ['last_record_at' => $this->lastTaxcloudRecordAt !== null
            ? gmdate('c', $this->lastTaxcloudRecordAt)
            : null];
        foreach (array_keys(self::MILESTONES) as $name) {
            $activity[$name] = isset($this->milestones[$name]) ? gmdate('c', $this->milestones[$name]) : null;
        }

        return ['problems' => $problems, 'overflow' => $this->overflow, 'activity' => $activity];
    }

    /**
     * Drop Monolog's trailing context/extra JSON.
     *
     * @param string $message
     * @return string
     */
    private function stripContext(string $message): string
    {
        // Monolog appends context then extra, each a JSON object or "[]"; the
        // request identity makes extra non-empty on every record.
        for ($i = 0; $i < 2; $i++) {
            $message = (string) preg_replace('/\s+(\{"[^{}]*(?:\{[^{}]*\}[^{}]*)*\}|\[\])\s*$/', '', rtrim($message));
        }

        return trim($message);
    }

    /**
     * Normalise a message so recurrences of one problem share a key.
     *
     * @param string $message
     * @return string
     */
    private function signature(string $message): string
    {
        $normalised = preg_replace(
            ['/\b[0-9a-f]{8,}\b/i', '/\d+(\.\d+)?/', '/"[^"]*"/', "/'[^']*'/", '/\s+/'],
            ['#', '#', '"…"', "'…'", ' '],
            $message
        );

        return mb_substr((string) $normalised, 0, 200);
    }
}
