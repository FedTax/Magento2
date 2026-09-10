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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Taxcloud\Magento2\Model\CredentialMigrator;

/**
 * bin/magento taxcloud:migrate-credentials
 *
 * Runs the same V1→V3 credential migration the MigrateSoapCredentialsToRest
 * data patch performs — for installs that deferred it with
 * TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1, or after fixing credentials that
 * made setup:upgrade abort.
 */
class MigrateCredentialsCommand extends Command
{
    /**
     * @var CredentialMigrator
     */
    private $credentialMigrator;

    /**
     * @param CredentialMigrator $credentialMigrator
     */
    public function __construct(CredentialMigrator $credentialMigrator)
    {
        parent::__construct();
        $this->credentialMigrator = $credentialMigrator;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('taxcloud:migrate-credentials');
        $this->setDescription(
            'Validate stored TaxCloud V1 SOAP credentials and fill the V3 REST connection id per scope'
        );
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->credentialMigrator->migrate();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        foreach ($result['migrated'] as $label) {
            $output->writeln('<info>Migrated: ' . $label . '</info>');
        }
        foreach ($result['skipped'] as $label) {
            $output->writeln('Skipped (already configured): ' . $label);
        }
        if (!$result['migrated'] && !$result['skipped']) {
            $output->writeln('Nothing to migrate: no scope stores its own V1 credential pair.');
        }

        return Cli::RETURN_SUCCESS;
    }
}
