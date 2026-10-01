<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Taxcloud\Magento2\Console\Command\DiagnosticsExportCommand;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Audit\DiagnosticsAudit;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleGenerator;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleResult;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleScope;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleWorkspace;

/**
 * The CLI reuses the generator unchanged, maps its options onto the same
 * request the admin builds, and fails only when nothing could be written.
 */
#[AllowMockObjectsWithoutExpectations]
class DiagnosticsExportCommandTest extends TestCase
{
    /**
     * @var string
     */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tc-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/var/tmp/taxcloud-diagnostics', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function tester(BundleGenerator $generator, ?State $state = null): CommandTester
    {
        $workspace = $this->createMock(BundleWorkspace::class);
        $workspace->method('getBaseDir')->willReturn($this->dir . '/var/tmp/taxcloud-diagnostics');

        return new CommandTester(new DiagnosticsExportCommand(
            $generator,
            $workspace,
            $this->createMock(DiagnosticsAudit::class),
            $state ?? $this->createMock(State::class),
            new File()
        ));
    }

    private function bundleResult(array $failures = []): BundleResult
    {
        $path = $this->dir . '/var/tmp/taxcloud-diagnostics/work.zip';
        file_put_contents($path, 'zip');

        return new BundleResult(
            $path,
            'taxcloud-diagnostics-default-20260914-100000.zip',
            $failures,
            [],
            new BundleScope('default', null, 'default', [], [])
        );
    }

    public function testWritesTheBundleToVarByDefaultAndPrintsThePath()
    {
        $generator = $this->createMock(BundleGenerator::class);
        $captured = null;
        $generator->method('generate')->willReturnCallback(function (BundleRequest $request) use (&$captured) {
            $captured = $request;
            return $this->bundleResult();
        });
        $state = $this->createMock(State::class);
        $state->expects($this->once())->method('setAreaCode')->with('adminhtml');

        $tester = $this->tester($generator, $state);
        $exit = $tester->execute([]);

        $expected = $this->dir . '/var/taxcloud-diagnostics-default-20260914-100000.zip';
        $this->assertSame(Cli::RETURN_SUCCESS, $exit);
        $this->assertFileExists($expected);
        $this->assertStringContainsString($expected, $tester->getDisplay());
        $this->assertFalse($captured->isRedactPii(), 'as-is by default, matching the admin button');
        $this->assertFalse($captured->isForOrder());
        $this->assertTrue($captured->isRunProbe());
        $this->assertSame(BundleRequest::ORIGIN_CLI, $captured->getOrigin());
    }

    public function testMapsOrderRedactAndOutputOptions()
    {
        $generator = $this->createMock(BundleGenerator::class);
        $captured = null;
        $generator->method('generate')->willReturnCallback(function (BundleRequest $request) use (&$captured) {
            $captured = $request;
            return $this->bundleResult([['section' => 'probe', 'message' => 'boom']]);
        });

        $tester = $this->tester($generator);
        $output = $this->dir . '/bundle.zip';
        $exit = $tester->execute([
            '--order' => '100000123',
            '--redact' => true,
            '--output' => $output,
            '--no-probe' => true,
            '--log-window' => 'maximum',
        ]);

        $this->assertSame(Cli::RETURN_SUCCESS, $exit, 'a partial bundle is still a written bundle');
        $this->assertFileExists($output);
        $this->assertSame('100000123', $captured->getOrderIncrementId());
        $this->assertTrue($captured->isRedactPii());
        $this->assertFalse($captured->isRunProbe());
        $this->assertSame('maximum', $captured->getLogWindow());
        $this->assertStringContainsString('Partial: the probe collector failed (boom)', $tester->getDisplay());
    }

    public function testExitsNonZeroOnlyWhenNothingWasWritten()
    {
        $generator = $this->createMock(BundleGenerator::class);
        $generator->method('generate')->willThrowException(new LocalizedException(__('Order #9 does not exist.')));

        $tester = $this->tester($generator);

        $this->assertSame(Cli::RETURN_FAILURE, $tester->execute(['--order' => '9']));
        $this->assertStringContainsString('No diagnostics bundle was written: Order #9 does not exist.', $tester->getDisplay());
    }
}
