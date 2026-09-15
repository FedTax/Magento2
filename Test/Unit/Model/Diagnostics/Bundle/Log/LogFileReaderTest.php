<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Log;

use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogFileReader;

/**
 * Reading must be proportional to the window, not the file: seek to an offset,
 * find records by time with a binary search, group multi-line records, and
 * never hold an oversized record whole.
 */
class LogFileReaderTest extends TestCase
{
    /**
     * @var string
     */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tc-logreader-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function reader(): LogFileReader
    {
        return new LogFileReader(new File());
    }

    /**
     * One record per minute from $start, each with a continuation line every
     * fifth record (a stack trace).
     */
    private function writeLog(string $name, int $start, int $count): string
    {
        $path = $this->dir . '/' . $name;
        $handle = fopen($path, 'w');
        for ($i = 0; $i < $count; $i++) {
            fwrite($handle, sprintf(
                "[%s] tclogger.INFO: record %d [] []\n",
                gmdate('Y-m-d\TH:i:s.000000+00:00', $start + $i * 60),
                $i
            ));
            if ($i % 5 === 0) {
                fwrite($handle, "#0 /var/www/html/vendor/some/Trace.php(12): call()\n");
            }
        }
        fclose($handle);

        return $path;
    }

    public function testParsesMonologTimestampsAndIgnoresContinuationLines()
    {
        $reader = $this->reader();

        $this->assertSame(
            strtotime('2026-09-14 10:00:00 UTC'),
            $reader->parseTimestamp('[2026-09-14T10:00:00.123456+00:00] tclogger.INFO: x')
        );
        $this->assertSame(
            strtotime('2026-09-14 10:00:00 UTC'),
            $reader->parseTimestamp('[2026-09-14 12:00:00+02:00] main.ERROR: x')
        );
        $this->assertSame(strtotime('2026-09-14 10:00:00 UTC'), $reader->parseTimestamp('[2026-09-14 10:00:00] x'));
        $this->assertNull($reader->parseTimestamp('#0 /var/www/html/Trace.php(12)'));
    }

    public function testOffsetForTimeFindsTheFirstRecordAtOrAfter()
    {
        $start = strtotime('2026-09-01 00:00:00 UTC');
        $path = $this->writeLog('taxcloud.log', $start, 5000);

        $offset = $this->reader()->offsetForTime($path, $start + 1234 * 60);

        $handle = fopen($path, 'r');
        fseek($handle, $offset);
        $line = fgets($handle);
        fclose($handle);
        $this->assertStringContainsString('record 1234 ', $line);

        $this->assertSame(0, $this->reader()->offsetForTime($path, $start - 3600));
        $this->assertSame(filesize($path), $this->reader()->offsetForTime($path, $start + 10000 * 60));
    }

    public function testReadRangeGroupsRecordsAndSkipsThePartialFirstLine()
    {
        $start = strtotime('2026-09-01 00:00:00 UTC');
        $path = $this->writeLog('taxcloud.log', $start, 20);

        $records = [];
        // Offset 10 lands mid-way through record 0.
        $stats = $this->reader()->readRange($path, 10, null, null, function ($record, $ts) use (&$records) {
            $records[] = [$record, $ts];
        });

        // Record 0 started before the offset: neither it nor its stack-trace
        // continuation is emitted.
        $this->assertStringContainsString('record 1 ', $records[0][0]);
        $this->assertSame($start + 60, $records[0][1]);
        $this->assertCount(19, $records);
        // Record 5 carries its stack-trace line.
        $this->assertStringContainsString("record 5 [] []\n#0 ", $records[4][0]);
        $this->assertSame(19, $stats['records']);
    }

    public function testReadRangeAppliesTheAgeCutoffAndEndOffset()
    {
        $start = strtotime('2026-09-01 00:00:00 UTC');
        $path = $this->writeLog('taxcloud.log', $start, 100);
        $reader = $this->reader();

        $records = [];
        $end = $reader->offsetForTime($path, $start + 60 * 60);
        $stats = $reader->readRange($path, 0, $end, $start + 50 * 60, function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->assertCount(10, $records);
        $this->assertStringContainsString('record 50 ', $records[0]);
        $this->assertStringContainsString('record 59 ', $records[9]);
        $this->assertSame(50, $stats['skipped_by_age']);
    }

    public function testOversizedRecordsAreTruncatedNotHeldWhole()
    {
        $path = $this->dir . '/big.log';
        file_put_contents(
            $path,
            '[2026-09-01T00:00:00.000000+00:00] tclogger.DEBUG: ' . str_repeat('x', LogFileReader::MAX_RECORD_BYTES * 2)
            . "\n[2026-09-01T00:01:00.000000+00:00] tclogger.INFO: after\n"
        );

        $records = [];
        $this->reader()->readRange($path, 0, null, null, function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->assertCount(2, $records);
        $this->assertLessThan(LogFileReader::MAX_RECORD_BYTES + 200, strlen($records[0]));
        $this->assertStringContainsString('record truncated by diagnostics export', $records[0]);
        $this->assertStringContainsString('after', $records[1]);
    }

    public function testReadsGzipRotations()
    {
        $start = strtotime('2026-09-01 00:00:00 UTC');
        $plain = $this->writeLog('taxcloud.log.1', $start, 10);
        file_put_contents($plain . '.gz', gzencode((string) file_get_contents($plain)));

        $records = [];
        $stats = $this->reader()->readGzip($plain . '.gz', $start + 5 * 60, function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->assertCount(5, $records);
        $this->assertStringContainsString('record 5 ', $records[0]);
        $this->assertSame(5, $stats['skipped_by_age']);
    }

    public function testAStartOffsetOnARecordBoundaryKeepsThatRecord()
    {
        $start = strtotime('2026-09-01 00:00:00 UTC');
        $path = $this->writeLog('taxcloud.log', $start, 10);
        $reader = $this->reader();
        $offset = $reader->offsetForTime($path, $start + 3 * 60);

        $records = [];
        $reader->readRange($path, $offset, null, null, function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->assertStringContainsString('record 3 ', $records[0]);
        $this->assertCount(7, $records);
    }
}
