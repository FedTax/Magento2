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

namespace Taxcloud\Magento2\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;

/**
 * Transforms stored V1 SOAP credentials into a validated V3 REST
 * configuration, one scope row at a time.
 *
 * For every core_config_data scope holding its own non-blank api_id +
 * api_key pair, the pair is validated against TaxCloud's credential exchange
 * and rest_connection_id = api_key (the v1 key doubles as the v3 connection
 * id) is written at that exact scope. Scopes that already have a
 * rest_connection_id are skipped untouched — reruns are safe.
 *
 * Failures are loud by design: a rejected pair or an unreachable exchange
 * throws a LocalizedException naming the scope, so setup:upgrade (or the CLI
 * command) stops with something actionable instead of leaving a half-dead
 * REST configuration. Writes already made stay — the next run skips them.
 *
 * Invoked from the MigrateSoapCredentialsToRest data patch and the
 * taxcloud:migrate-credentials console command.
 */
class CredentialMigrator
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var TokenExchange
     */
    private $tokenExchange;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param ResourceConnection $resourceConnection
     * @param TokenExchange $tokenExchange
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        TokenExchange $tokenExchange,
        StoreManagerInterface $storeManager
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->tokenExchange = $tokenExchange;
        $this->storeManager = $storeManager;
    }

    /**
     * Run the migration over every scope with its own V1 credential pair.
     *
     * @return array{migrated: string[], skipped: string[]} Scope labels per outcome
     * @throws LocalizedException When a pair is rejected or the exchange is unreachable
     */
    public function migrate(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('core_config_data');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['scope', 'scope_id', 'path', 'value'])
                ->where('path IN (?)', [
                    TaxcloudConfig::XML_PATH_API_ID,
                    TaxcloudConfig::XML_PATH_API_KEY,
                    TaxcloudConfig::XML_PATH_REST_CONNECTION_ID,
                ])
                ->order(['scope', 'scope_id'])
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['scope'] . '|' . $row['scope_id']][$row['path']] = trim((string) $row['value']);
        }

        $migrated = [];
        $skipped = [];
        foreach ($groups as $key => $values) {
            [$scope, $scopeId] = explode('|', $key);
            $apiId = $values[TaxcloudConfig::XML_PATH_API_ID] ?? '';
            $apiKey = $values[TaxcloudConfig::XML_PATH_API_KEY] ?? '';
            if ($apiId === '' || $apiKey === '') {
                continue;
            }

            $label = $this->scopeLabel($scope, (int) $scopeId);

            if (($values[TaxcloudConfig::XML_PATH_REST_CONNECTION_ID] ?? '') !== '') {
                $skipped[] = $label;
                continue;
            }

            $this->validatePair($apiId, $apiKey, $scope, (int) $scopeId, $label);

            $connection->insert($table, [
                'scope' => $scope,
                'scope_id' => (int) $scopeId,
                'path' => TaxcloudConfig::XML_PATH_REST_CONNECTION_ID,
                'value' => $apiKey,
            ]);
            $migrated[] = $label;
        }

        return ['migrated' => $migrated, 'skipped' => $skipped];
    }

    /**
     * @param string $apiId
     * @param string $apiKey
     * @param string $scope
     * @param int $scopeId
     * @param string $label
     * @return void
     * @throws LocalizedException
     */
    private function validatePair(string $apiId, string $apiKey, string $scope, int $scopeId, string $label): void
    {
        // Store-view rows resolve their own exchange endpoint/timeout config;
        // website/default rows use the default-scope values.
        $store = $scope === 'stores' ? $scopeId : null;

        try {
            $this->tokenExchange->exchange($apiId, $apiKey, $store);
        } catch (TokenExchangeException $e) {
            if ($e->isRejected()) {
                throw new LocalizedException(__(
                    'TaxCloud credential migration failed: the V1 API ID / API Key pair stored at %1 was '
                    . 'rejected by TaxCloud. Fix the credentials at that scope (Stores → Configuration → '
                    . 'Sales → Tax → TaxCloud Settings) or remove them, then run setup:upgrade again '
                    . '(or bin/magento taxcloud:migrate-credentials).',
                    $label
                ));
            }
            throw new LocalizedException(__(
                'TaxCloud credential migration failed: the credential validation service could not be '
                . 'reached while checking the pair stored at %1 (%2). Check connectivity (or set '
                . 'TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1 to defer the migration), then run setup:upgrade '
                . 'again (or bin/magento taxcloud:migrate-credentials).',
                $label,
                $e->getMessage()
            ));
        }
    }

    /**
     * Human scope label for messages: "store view 'second' (stores/3)".
     *
     * @param string $scope
     * @param int $scopeId
     * @return string
     */
    private function scopeLabel(string $scope, int $scopeId): string
    {
        $code = '';
        try {
            if ($scope === 'stores') {
                $code = (string) $this->storeManager->getStore($scopeId)->getCode();
            } elseif ($scope === 'websites') {
                $code = (string) $this->storeManager->getWebsite($scopeId)->getCode();
            }
        } catch (\Throwable $e) {
            $code = '';
        }

        if ($scope === 'default') {
            return 'default scope (default/0)';
        }
        $kind = $scope === 'stores' ? 'store view' : 'website';

        return $kind . ($code !== '' ? " '$code'" : '') . " ($scope/$scopeId)";
    }
}
