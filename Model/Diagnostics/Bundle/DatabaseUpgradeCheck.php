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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle;

use Magento\Framework\Module\DbVersionInfo;
use Magento\Framework\Setup\Patch\UpToDateData;
use Magento\Framework\Setup\Patch\UpToDateSchema;

/**
 * Whether `bin/magento setup:upgrade` is pending.
 *
 * Deploying new extension code without running setup:upgrade is the most
 * common cause of a store that breaks right after an upgrade: patches not
 * applied, module versions in setup_module behind the code, generated
 * interceptors built against old constructors. The same checks as
 * `bin/magento setup:db:status`, minus the declarative-schema diff, which
 * compares every table and is too slow for an admin request.
 */
class DatabaseUpgradeCheck
{
    /**
     * Pending patches reported per kind before the list is cut.
     */
    private const MAX_LISTED = 20;

    /**
     * @var DbVersionInfo
     */
    private $dbVersionInfo;

    /**
     * @var UpToDateSchema
     */
    private $schemaPatches;

    /**
     * @var UpToDateData
     */
    private $dataPatches;

    /**
     * @param DbVersionInfo  $dbVersionInfo
     * @param UpToDateSchema $schemaPatches
     * @param UpToDateData   $dataPatches
     */
    public function __construct(DbVersionInfo $dbVersionInfo, UpToDateSchema $schemaPatches, UpToDateData $dataPatches)
    {
        $this->dbVersionInfo = $dbVersionInfo;
        $this->schemaPatches = $schemaPatches;
        $this->dataPatches = $dataPatches;
    }

    /**
     * @return array{up_to_date: bool, module_versions: array, schema_patches_pending: bool|null,
     *               data_patches_pending: bool|null, pending: array, checked: string}
     */
    public function check(): array
    {
        $versions = [];
        // Declared string[] upstream; each entry is actually an array keyed by the KEY_* constants.
        /** @var array<int, array<string, string>> $errors */
        $errors = $this->dbVersionInfo->getDbVersionErrors();
        foreach ($errors as $error) {
            $versions[] = [
                'module' => $error[DbVersionInfo::KEY_MODULE],
                'type' => $error[DbVersionInfo::KEY_TYPE],
                'database' => $error[DbVersionInfo::KEY_CURRENT],
                'code' => $error[DbVersionInfo::KEY_REQUIRED],
            ];
        }

        $pending = [];
        $schemaPending = $this->pendingFlag($this->schemaPatches, 'schema', $pending);
        $dataPending = $this->pendingFlag($this->dataPatches, 'data', $pending);

        return [
            'up_to_date' => $versions === [] && $schemaPending !== true && $dataPending !== true,
            'module_versions' => array_slice($versions, 0, self::MAX_LISTED),
            'schema_patches_pending' => $schemaPending,
            'data_patches_pending' => $dataPending,
            'pending' => $pending,
            'checked' => 'module versions, schema patches, data patches (declarative schema diff not checked)',
        ];
    }

    /**
     * @param UpToDateSchema|UpToDateData $validator
     * @param string                      $kind
     * @param array                       $pending
     * @return bool|null Null when the check itself failed
     */
    private function pendingFlag($validator, string $kind, array &$pending): ?bool
    {
        try {
            if ($validator->isUpToDate()) {
                return false;
            }
        } catch (\Throwable $e) {
            $pending[$kind] = ['error' => $e->getMessage()];
            return null;
        }

        // getDetails() exists from Magento 2.4.9; older versions only say "not up to date".
        if (method_exists($validator, 'getDetails')) {
            try {
                $details = $validator->getDetails();
                $pending[$kind] = is_array($details) ? $this->limit($details) : [];
            } catch (\Throwable $e) {
                $pending[$kind] = [];
            }
        }

        return true;
    }

    /**
     * @param array $details
     * @return array
     */
    private function limit(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_array($value) && count($value) > self::MAX_LISTED) {
                $details[$key] = array_slice($value, 0, self::MAX_LISTED);
                $details[$key . '_truncated'] = true;
            }
        }

        return $details;
    }
}
