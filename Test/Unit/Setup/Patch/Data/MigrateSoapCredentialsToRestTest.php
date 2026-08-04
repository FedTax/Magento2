<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Setup\Patch\Data;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\CredentialMigrator;
use Taxcloud\Magento2\Setup\Patch\Data\MigrateSoapCredentialsToRest;
use Taxcloud\Magento2\Setup\Patch\Data\PinSoapApiTypeForExistingInstalls;

/**
 * Thin-shell contract of the migration patch: ordering after the pin patch,
 * delegation to the migrator, and the env-var escape hatch that writes
 * nothing but logs how to run the migration later.
 */
#[AllowMockObjectsWithoutExpectations]
class MigrateSoapCredentialsToRestTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(MigrateSoapCredentialsToRest::SKIP_ENV_VAR);
        parent::tearDown();
    }

    public function testRunsAfterThePinPatch()
    {
        $this->assertSame(
            [PinSoapApiTypeForExistingInstalls::class],
            MigrateSoapCredentialsToRest::getDependencies()
        );
    }

    public function testApplyDelegatesToTheMigrator()
    {
        $migrator = $this->createMock(CredentialMigrator::class);
        $migrator->expects($this->once())->method('migrate')
            ->willReturn(['migrated' => [], 'skipped' => []]);

        $patch = new MigrateSoapCredentialsToRest($migrator, $this->createMock(LoggerInterface::class));

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([], $patch->getAliases());
    }

    public function testSkipVariableBypassesTheMigratorAndLogsTheReRunPath()
    {
        putenv(MigrateSoapCredentialsToRest::SKIP_ENV_VAR . '=1');

        $migrator = $this->createMock(CredentialMigrator::class);
        $migrator->expects($this->never())->method('migrate');

        $logged = '';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(
            function ($message) use (&$logged) {
                $logged = $message;
            }
        );

        (new MigrateSoapCredentialsToRest($migrator, $logger))->apply();

        $this->assertStringContainsString('taxcloud:migrate-credentials', $logged);
    }

    /**
     * Any value other than exactly "1" does not engage the hatch — a stray
     * TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=0 must still migrate.
     */
    public function testSkipVariableZeroStillMigrates()
    {
        putenv(MigrateSoapCredentialsToRest::SKIP_ENV_VAR . '=0');

        $migrator = $this->createMock(CredentialMigrator::class);
        $migrator->expects($this->once())->method('migrate')
            ->willReturn(['migrated' => [], 'skipped' => []]);

        (new MigrateSoapCredentialsToRest($migrator, $this->createMock(LoggerInterface::class)))->apply();
    }
}
