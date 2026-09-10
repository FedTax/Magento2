<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Taxcloud\Magento2\Console\Command\MigrateCredentialsCommand;
use Taxcloud\Magento2\Model\CredentialMigrator;

/**
 * The CLI face of the migration: per-scope output, success exit code, and a
 * non-zero exit carrying the migrator's actionable message on failure.
 */
#[AllowMockObjectsWithoutExpectations]
class MigrateCredentialsCommandTest extends TestCase
{
    private function runCommand(CredentialMigrator $migrator): CommandTester
    {
        $tester = new CommandTester(new MigrateCredentialsCommand($migrator));
        $tester->execute([]);
        return $tester;
    }

    public function testReportsMigratedAndSkippedScopesAndExitsZero()
    {
        $migrator = $this->createMock(CredentialMigrator::class);
        $migrator->method('migrate')->willReturn([
            'migrated' => ['default scope (default/0)'],
            'skipped' => ["store view 'second' (stores/3)"],
        ]);

        $tester = $this->runCommand($migrator);

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Migrated: default scope (default/0)', $tester->getDisplay());
        $this->assertStringContainsString("Skipped (already configured): store view 'second' (stores/3)", $tester->getDisplay());
    }

    public function testNothingToMigrateIsReportedAndExitsZero()
    {
        $migrator = $this->createMock(CredentialMigrator::class);
        $migrator->method('migrate')->willReturn(['migrated' => [], 'skipped' => []]);

        $tester = $this->runCommand($migrator);

        $this->assertSame(Cli::RETURN_SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to migrate', $tester->getDisplay());
    }

    public function testFailureSurfacesTheMessageAndExitsNonZero()
    {
        $migrator = $this->createMock(CredentialMigrator::class);
        $migrator->method('migrate')->willThrowException(
            new LocalizedException(__('the pair stored at default scope (default/0) was rejected'))
        );

        $tester = $this->runCommand($migrator);

        $this->assertSame(Cli::RETURN_FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('default scope (default/0)', $tester->getDisplay());
    }

    public function testCommandName()
    {
        $command = new MigrateCredentialsCommand($this->createMock(CredentialMigrator::class));

        $this->assertSame('taxcloud:migrate-credentials', $command->getName());
    }
}
