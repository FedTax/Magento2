<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Log;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogDigest;

/**
 * The digest turns thousands of records into "what went wrong, how often, and
 * is it still happening", and into when TaxCloud last did each thing that matters.
 */
class LogDigestTest extends TestCase
{
    private function record(string $time, string $level, string $message, string $context = '[]'): string
    {
        return '[' . $time . '.000000+00:00] tclogger.' . $level . ': ' . $message . ' ' . $context . " []\n";
    }

    public function testGroupsRecurrencesOfOneProblemAndTracksFirstAndLastSighting()
    {
        $digest = new LogDigest();
        $digest->add('logs/taxcloud.log', $this->record('2026-09-10T05:10:39', 'ERROR',
            'No valid US shipping address on order SO000000057 - cannot build v3 order'), strtotime('2026-09-10T05:10:39Z'));
        $digest->add('logs/taxcloud.log', $this->record('2026-09-12T08:00:00', 'ERROR',
            'No valid US shipping address on order SO000000067 - cannot build v3 order',
            '{"correlation_id":"abc123abc123","order_increment_id":"SO000000067"}'), strtotime('2026-09-12T08:00:00Z'));
        $digest->add('logs/taxcloud.log', $this->record('2026-09-11T00:00:00', 'INFO', 'Calling lookupTaxes'),
            strtotime('2026-09-11T00:00:00Z'));

        $problems = $digest->toArray()['problems']['logs/taxcloud.log'];

        $this->assertCount(1, $problems, 'different order numbers are the same problem; INFO is not a problem');
        $this->assertSame('ERROR', $problems[0]['level']);
        $this->assertSame(2, $problems[0]['count']);
        $this->assertSame('2026-09-10T05:10:39+00:00', $problems[0]['first_seen']);
        $this->assertSame('2026-09-12T08:00:00+00:00', $problems[0]['last_seen']);
        $this->assertStringNotContainsString('correlation_id', $problems[0]['message'], 'context JSON is dropped');
    }

    public function testMultiLineRecordsAreKeyedOnTheirFirstLine()
    {
        $digest = new LogDigest();
        $trace = "[2026-09-01T00:00:00.000000+00:00] main.CRITICAL: Type Error occurred when creating object: "
            . "Taxcloud\\Magento2\\Model\\Tax\\Interceptor [] []\n#0 /var/www/html/vendor/a.php(12): x()\n";
        $digest->add('logs/system-taxcloud.log', $trace, strtotime('2026-09-01T00:00:00Z'));
        $digest->add('logs/system-taxcloud.log', str_replace('a.php(12)', 'b.php(99)', $trace), strtotime('2026-09-02T00:00:00Z'));

        $problems = $digest->toArray()['problems']['logs/system-taxcloud.log'];

        $this->assertCount(1, $problems);
        $this->assertSame('CRITICAL', $problems[0]['level']);
        $this->assertSame(2, $problems[0]['count']);
    }

    public function testNewestProblemsComeFirstAndDistinctMessagesAreCapped()
    {
        $digest = new LogDigest();
        for ($i = 0; $i < LogDigest::MAX_SIGNATURES_PER_SOURCE + 5; $i++) {
            // Letters, not digits: digits are normalised and would collapse into one signature.
            $digest->add('logs/taxcloud.log', $this->record('2026-09-01T00:00:00', 'WARNING',
                'distinct problem ' . str_repeat('x', $i + 1)), strtotime('2026-09-01T00:00:00Z') + $i);
        }

        $result = $digest->toArray();

        $this->assertCount(LogDigest::MAX_SIGNATURES_PER_SOURCE, $result['problems']['logs/taxcloud.log']);
        $this->assertSame(5, $result['overflow']['logs/taxcloud.log']);
        $this->assertStringEndsWith(
            str_repeat('x', LogDigest::MAX_SIGNATURES_PER_SOURCE),
            $result['problems']['logs/taxcloud.log'][0]['message']
        );
    }

    public function testRecordsWhenTaxCloudLastDidEachThingThatMatters()
    {
        $digest = new LogDigest();
        $lines = [
            ['2026-09-15T11:19:09', 'INFO', 'Caching lookupTaxes result for 63651s', '{"operation":"lookup"}'],
            ['2026-09-15T11:19:50', 'INFO', 'Order SO000000073 captured in TaxCloud', '{"operation":"capture"}'],
            ['2026-09-15T11:20:23', 'INFO', 'Refund for order SO000000073 recorded in TaxCloud', '{"operation":"refund"}'],
        ];
        foreach ($lines as [$time, $level, $message, $context]) {
            $digest->add('logs/taxcloud.log', $this->record($time, $level, $message, $context), strtotime($time . 'Z'), true);
        }
        // Magento's own logs never move TaxCloud milestones.
        $digest->add('logs/system-taxcloud.log', $this->record('2026-09-15T12:00:00', 'INFO',
            'Order X captured in TaxCloud'), strtotime('2026-09-15T12:00:00Z'));

        $activity = $digest->toArray()['activity'];

        $this->assertSame('2026-09-15T11:20:23+00:00', $activity['last_record_at']);
        $this->assertSame('2026-09-15T11:19:09+00:00', $activity['last_successful_lookup_at']);
        $this->assertSame('2026-09-15T11:19:50+00:00', $activity['last_capture_success_at']);
        $this->assertSame('2026-09-15T11:20:23+00:00', $activity['last_refund_success_at']);
        $this->assertNull($activity['last_capture_failure_at']);
        $this->assertNull($activity['last_fallback_at']);
    }

    public function testContextAndRequestIdentityAreStrippedFromTheMessage()
    {
        $digest = new LogDigest();
        $digest->add('logs/taxcloud.log', '[2026-09-15T11:38:53.000000+00:00] tclogger.WARNING: Cannot get SoapClient: '
            . 'timeout [] {"request":"9f3c1a2b","pid":812}' . "\n", strtotime('2026-09-15T11:38:53Z'));
        $digest->add('logs/taxcloud.log', '[2026-09-15T11:38:54.000000+00:00] tclogger.WARNING: Cannot get SoapClient: '
            . 'timeout {"correlation_id":"abc","operation":"lookup"} {"request":"1234abcd","pid":813}' . "\n",
            strtotime('2026-09-15T11:38:54Z'));

        $problems = $digest->toArray()['problems']['logs/taxcloud.log'];

        $this->assertCount(1, $problems, 'different requests are the same problem');
        $this->assertSame('Cannot get SoapClient: timeout', $problems[0]['message']);
        $this->assertSame(2, $problems[0]['count']);
    }
}
