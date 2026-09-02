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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Taxcloud\Magento2\Console\Command\DiagnoseCommand;
use Taxcloud\Magento2\Model\Diagnostics\CollectorVerdict;
use Taxcloud\Magento2\Model\Diagnostics\StoreVerdict;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;
use Taxcloud\Magento2\Model\Tax;

/**
 * The command support actually runs, so its exit status is a contract: it is
 * meant to be usable from a monitoring script, and it must report the truth
 * whatever an admin has clicked in the banner.
 */
#[AllowMockObjectsWithoutExpectations]
class DiagnoseCommandTest extends TestCase
{
    private function diagnose(CollectorVerdict $verdict): CommandTester
    {
        $diagnostics = $this->createMock(TaxCollectorDiagnostics::class);
        $diagnostics->method('verdict')->willReturn($verdict);

        $tester = new CommandTester(new DiagnoseCommand($diagnostics, $this->createMock(State::class)));
        $tester->execute([]);

        return $tester;
    }

    public function testSucceedsWhenTaxcloudOwnsTheCollector()
    {
        $tester = $this->diagnose(new CollectorVerdict([
            new StoreVerdict(1, 'Default', Tax::class, true),
        ]));

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Default', $tester->getDisplay());
    }

    /**
     * Non-zero so the command is usable as an automated check.
     */
    public function testFailsWhenAnotherModuleOwnsTheCollector()
    {
        $tester = $this->diagnose(new CollectorVerdict([
            new StoreVerdict(1, 'Default', 'Competitor\\Tax\\Total', false),
        ]));

        $this->assertSame(Cli::RETURN_FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Competitor\\Tax\\Total', $tester->getDisplay());
    }

    public function testFailsWhenTheProbeCouldNotRun()
    {
        $tester = $this->diagnose(new CollectorVerdict([
            new StoreVerdict(1, 'Default', null, false, [], [], 'collector blew up'),
        ]));

        $this->assertSame(Cli::RETURN_FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('collector blew up', $tester->getDisplay());
    }

    public function testReportsNothingToCheckWhenTaxcloudIsEnabledNowhere()
    {
        $tester = $this->diagnose(new CollectorVerdict([]));

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('not enabled for any store', $tester->getDisplay());
    }

    /**
     * A green result here is routinely read as "tax is working". It is not: the
     * command cannot see a bad credential or a Lookup failure swallowed by the
     * Magento fallback, and it has to say so.
     */
    public function testHealthyOutputDoesNotClaimTaxIsCorrect()
    {
        $tester = $this->diagnose(new CollectorVerdict([
            new StoreVerdict(1, 'Default', Tax::class, true),
        ]));

        $this->assertStringContainsString('does not verify credentials', $tester->getDisplay());
    }

    public function testReportsInterceptionAndLaterCollectors()
    {
        $tester = $this->diagnose(new CollectorVerdict([
            new StoreVerdict(1, 'Default', Tax::class, true, ['Competitor\\Plugin'], ['Loyalty\\Total']),
        ]));

        $this->assertSame(Cli::RETURN_FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Competitor\\Plugin', $tester->getDisplay());
        $this->assertStringContainsString('Loyalty\\Total', $tester->getDisplay());
    }
}
