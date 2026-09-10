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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Keep upgraded installs on the SOAP API they were built against.
 *
 * etc/config.xml defaults api_type to REST, which is right for fresh installs
 * but would silently re-point every existing integration the moment it
 * upgrades. Any saved api_id — in any scope — marks an install as an existing
 * SOAP integration, so this patch pins api_type=soap at the default scope for
 * those installs. Switching to REST afterwards is an explicit admin decision.
 *
 * The "no api_type row exists" guard makes the patch a no-op when an admin has
 * already saved a choice, so it stays safe even if it is ever re-applied.
 */
class PinSoapApiTypeForExistingInstalls implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @return array
     */
    public static function getDependencies()
    {
        return [];
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
        $connection = $this->moduleDataSetup->getConnection();
        $configTable = $this->moduleDataSetup->getTable('core_config_data');

        $hasApiId = (bool) $connection->fetchOne(
            $connection->select()
                ->from($configTable, ['config_id'])
                ->where('path = ?', TaxcloudConfig::XML_PATH_API_ID)
                ->where("value IS NOT NULL AND value != ''")
                ->limit(1)
        );
        if (!$hasApiId) {
            return $this;
        }

        $hasApiType = (bool) $connection->fetchOne(
            $connection->select()
                ->from($configTable, ['config_id'])
                ->where('path = ?', TaxcloudConfig::XML_PATH_API_TYPE)
                ->limit(1)
        );
        if ($hasApiType) {
            return $this;
        }

        $connection->insert($configTable, [
            'scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            'scope_id' => 0,
            'path' => TaxcloudConfig::XML_PATH_API_TYPE,
            'value' => ApiType::SOAP,
        ]);

        return $this;
    }
}
