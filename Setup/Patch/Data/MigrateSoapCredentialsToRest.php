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

namespace Taxcloud\Magento2\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\CredentialMigrator;

/**
 * Fill V3 REST connection ids from validated V1 SOAP credentials at upgrade.
 *
 * Thin shell: the logic lives in {@see CredentialMigrator} so the
 * taxcloud:migrate-credentials console command can run the identical
 * migration on demand (a data patch only ever runs once). A migration
 * failure aborts setup:upgrade on purpose — the merchant is told which
 * scope's credentials to fix. Deploys that must not call out (offline/CI)
 * set TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1 and run the console command
 * later; the skip leaves nothing half-written.
 */
class MigrateSoapCredentialsToRest implements DataPatchInterface
{
    /**
     * Environment variable that defers the migration for this run.
     */
    public const SKIP_ENV_VAR = 'TAXCLOUD_SKIP_CREDENTIAL_MIGRATION';

    /**
     * @var CredentialMigrator
     */
    private $credentialMigrator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CredentialMigrator $credentialMigrator
     * @param LoggerInterface $logger
     */
    public function __construct(CredentialMigrator $credentialMigrator, LoggerInterface $logger)
    {
        $this->credentialMigrator = $credentialMigrator;
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public static function getDependencies()
    {
        return [PinSoapApiTypeForExistingInstalls::class];
    }

    /**
     * @return array
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * Apply patch
     *
     * @return $this
     */
    public function apply()
    {
        // getenv() rather than a config read: the hatch must work on installs
        // whose DB is the thing being deployed.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        if ((string) getenv(self::SKIP_ENV_VAR) === '1') {
            $this->logger->warning(
                'Taxcloud: credential migration skipped (' . self::SKIP_ENV_VAR . '=1). '
                . 'Run "bin/magento taxcloud:migrate-credentials" to perform it later.'
            );
            return $this;
        }

        $this->credentialMigrator->migrate();

        return $this;
    }
}
