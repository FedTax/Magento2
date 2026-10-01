<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Section;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Logger\Handler;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleScope;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogFileReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogPathResolver;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\LogsSection;
use Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\DiagnosticsFixture;

/**
 * Global bundles take the newest in-window data; per-order bundles take only
 * the order's correlated records plus context — and say so when there are none.
 */
#[AllowMockObjectsWithoutExpectations]
class LogsSectionTest extends TestCase
{
    use DiagnosticsFixture;

    /**
     * @var string
     */
    private $root;

    /**
     * @var array<string, array{0: string, 1: int}>
     */
    private $added = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tc-logs-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/log', 0777, true);
        mkdir($this->root . '/work');
        $this->setConfig(['tax/taxcloud_settings/enabled' => '1', 'tax/taxcloud_settings/logging' => '1']);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    private function line(int $ago, string $message, array $context = []): string
    {
        return sprintf(
            "[%s] tclogger.INFO: %s %s []\n",
            gmdate('Y-m-d\TH:i:s.000000+00:00', time() - $ago),
            $message,
            $context === [] ? '[]' : json_encode($context)
        );
    }

    private function collect(BundleRequest $request, ?Order $order = null): array
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn($this->root);
        $directoryList->method('getPath')->willReturn($this->root . '/var/log');
        $logger = new Logger('tclogger', [
            new Handler($this->createMock(DriverInterface::class), $this->root . '/', 'var/log/taxcloud.log'),
        ]);
        $driver = new File();

        $section = new LogsSection(
            new LogPathResolver($logger, $directoryList),
            new LogFileReader($driver),
            $driver,
            new TaxcloudConfig($this->scopeConfig())
        );

        $stores = $this->stores();
        $scope = new BundleScope(BundleRequest::SCOPE_STORE, 1, 'us_en', [1 => $stores[1]], []);
        $context = new BundleContext($request, $scope, $order, $this->root . '/work', [], null);

        $this->added = [];
        $archive = $this->createMock(BundleArchive::class);
        $archive->method('addStagedFile')->willReturnCallback(function ($name, $path, $bytes) {
            $this->added[$name] = [(string) file_get_contents($path), $bytes];
        });

        return $section->collect($context, $archive);
    }

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getQuoteId')->willReturn(77);
        $order->method('getCreatedAt')->willReturn(gmdate('Y-m-d H:i:s', time() - 3600));

        return $order;
    }

    public function testPerOrderBundleKeepsCorrelatedRecordsWithContext()
    {
        $log = '';
        for ($i = 0; $i < 30; $i++) {
            $log .= $this->line(7000 - $i * 10, 'noise before ' . $i);
        }
        $log .= $this->line(3500, 'Calling lookupTaxes LIVE API', ['correlation_id' => 'aaaaaaaaaaaa', 'operation' => 'lookup', 'quote_id' => '77']);
        $log .= $this->line(3499, 'Caching lookupTaxes result for 86400s', ['correlation_id' => 'aaaaaaaaaaaa', 'operation' => 'lookup', 'quote_id' => '77']);
        for ($i = 0; $i < 30; $i++) {
            $log .= $this->line(3400 - $i * 10, 'noise between ' . $i, ['correlation_id' => 'bbbbbbbbbbbb', 'quote_id' => '78']);
        }
        $log .= $this->line(1000, 'Calling authorizeCapture (v3 REST) for order 100000123');
        $log .= $this->line(900, 'Not this order: 1000001234');
        file_put_contents($this->root . '/var/log/taxcloud.log', $log);

        $data = $this->collect(new BundleRequest(BundleRequest::SCOPE_DEFAULT, null, false, 'standard', false, '', 'admin', 5, null, 2), $this->order());

        $this->assertArrayHasKey('logs/taxcloud.log', $this->added);
        [$content] = $this->added['logs/taxcloud.log'];
        $this->assertStringContainsString('Calling lookupTaxes LIVE API', $content);
        $this->assertStringContainsString('Caching lookupTaxes result', $content);
        $this->assertStringContainsString('Calling authorizeCapture (v3 REST) for order 100000123', $content);
        // Two records of context either side.
        $this->assertStringContainsString('noise before 29', $content);
        $this->assertStringContainsString('noise before 28', $content);
        $this->assertStringNotContainsString('noise before 27', $content);
        $this->assertStringContainsString('noise between 0', $content);
        $this->assertStringContainsString('noise between 1', $content);
        $this->assertStringNotContainsString('noise between 10', $content);
        $this->assertStringContainsString("--\n", $content, 'non-contiguous groups are separated');

        $correlation = $data['order_correlation'];
        $this->assertSame(3, $correlation['correlated_records']);
        $this->assertSame(['aaaaaaaaaaaa'], $correlation['correlation_ids']);
        $this->assertSame(1, $correlation['tax_source_evidence']['taxcloud_lookup_records']);
    }

    public function testPerOrderBundleWithNoCorrelatedLinesShipsNoLogFile()
    {
        file_put_contents($this->root . '/var/log/taxcloud.log', $this->line(100, 'another order 100000999'));

        $data = $this->collect(new BundleRequest(), $this->order());

        $this->assertArrayNotHasKey('logs/taxcloud.log', $this->added);
        $this->assertSame(0, $data['order_correlation']['correlated_records']);
        $this->assertFalse($data['files']['logs/taxcloud.log']['included']);
    }

    public function testGlobalBundleTakesTheNewestDataWithinTheByteCap()
    {
        // ~12 MB of in-window log against the 10 MB standard cap.
        $handle = fopen($this->root . '/var/log/taxcloud.log', 'w');
        $payload = str_repeat('p', 1000);
        for ($i = 12000; $i > 0; $i--) {
            fwrite($handle, $this->line($i, 'record ' . $i . ' ' . $payload));
        }
        fclose($handle);

        $data = $this->collect(new BundleRequest());

        [$content, $bytes] = $this->added['logs/taxcloud.log'];
        $this->assertLessThanOrEqual(BundleRequest::LOG_WINDOWS['standard'][0], $bytes);
        $this->assertGreaterThan(BundleRequest::LOG_WINDOWS['standard'][0] * 0.9, $bytes);
        $this->assertStringContainsString('record 1 ', $content, 'the newest record is kept');
        $this->assertStringNotContainsString('record 12000 ', $content, 'the oldest record is dropped');
        $this->assertTrue($data['files']['logs/taxcloud.log']['truncated_by_size']);
    }

    public function testGlobalBundleDropsRecordsOlderThanTheWindowAndRotationsOutsideIt()
    {
        file_put_contents(
            $this->root . '/var/log/taxcloud.log',
            $this->line(9 * 86400, 'too old') . $this->line(3600, 'recent')
        );
        file_put_contents($this->root . '/var/log/taxcloud.log.1', $this->line(20 * 86400, 'ancient rotation'));
        touch($this->root . '/var/log/taxcloud.log.1', time() - 20 * 86400);
        file_put_contents(
            $this->root . '/var/log/taxcloud.log.2.gz',
            gzencode($this->line(2 * 86400, 'compressed recent'))
        );
        touch($this->root . '/var/log/taxcloud.log.2.gz', time() - 2 * 86400);

        $data = $this->collect(new BundleRequest());

        $this->assertStringContainsString('recent', $this->added['logs/taxcloud.log'][0]);
        $this->assertStringNotContainsString('too old', $this->added['logs/taxcloud.log'][0]);
        $this->assertArrayNotHasKey('logs/taxcloud.log.1', $this->added);
        $this->assertSame('last written before the log window', $data['files']['logs/taxcloud.log.1']['reason']);
        $this->assertStringContainsString('compressed recent', $this->added['logs/taxcloud.log.2'][0]);
    }

    public function testMissingLogIsReportedNotShippedEmpty()
    {
        $data = $this->collect(new BundleRequest());

        $this->assertSame(LogsSection::STATUS_MISSING, $data['taxcloud_log']['status']);
        $this->assertSame([], $this->added);
        $this->assertSame(['us_en' => 'basic'], $data['logging_modes']);
    }

    public function testMagentoLogsAreFilteredToTaxcloudRecordsOnly()
    {
        file_put_contents($this->root . '/var/log/taxcloud.log', $this->line(10, 'x'));
        file_put_contents(
            $this->root . '/var/log/exception.log',
            "[" . gmdate('Y-m-d\TH:i:s.000000+00:00', time() - 50) . "] main.CRITICAL: Unrelated PDOException\n#0 trace\n"
            . "[" . gmdate('Y-m-d\TH:i:s.000000+00:00', time() - 40) . "] main.CRITICAL: Error in Taxcloud\\Magento2\\Model\\Tax::collect\n#0 /app/code/Taxcloud/Magento2/Model/Tax.php(90)\n"
        );

        $data = $this->collect(new BundleRequest());

        $content = $this->added['logs/exception-taxcloud.log'][0];
        $this->assertStringContainsString('Taxcloud\\Magento2\\Model\\Tax::collect', $content);
        $this->assertStringContainsString('#0 /app/code/Taxcloud/Magento2/Model/Tax.php(90)', $content, 'stack traces stay with their record');
        $this->assertStringNotContainsString('Unrelated PDOException', $content);
        $this->assertSame(1, $data['files']['logs/exception-taxcloud.log']['matching_records']);
        $this->assertFalse($data['files']['logs/system-taxcloud.log']['included']);
    }
}
