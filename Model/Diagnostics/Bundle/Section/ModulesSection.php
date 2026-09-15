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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Section;

use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;

/**
 * modules.json: every installed module, with version and enabled state.
 *
 * Third-party modules are the usual suspects when TaxCloud's output is
 * overwritten or never produced, so nothing is filtered out.
 */
class ModulesSection implements SectionInterface
{
    public const FILE = 'modules.json';

    /**
     * @var FullModuleList
     */
    private $fullModuleList;

    /**
     * @var ModuleListInterface
     */
    private $enabledModuleList;

    /**
     * @var PackageInfo
     */
    private $packageInfo;

    /**
     * @param FullModuleList      $fullModuleList
     * @param ModuleListInterface $enabledModuleList
     * @param PackageInfo         $packageInfo
     */
    public function __construct(
        FullModuleList $fullModuleList,
        ModuleListInterface $enabledModuleList,
        PackageInfo $packageInfo
    ) {
        $this->fullModuleList = $fullModuleList;
        $this->enabledModuleList = $enabledModuleList;
        $this->packageInfo = $packageInfo;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'modules';
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(BundleContext $context): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function collect(BundleContext $context, BundleArchive $archive): array
    {
        $modules = [];
        $counts = ['total' => 0, 'enabled' => 0, 'third_party' => 0, 'third_party_enabled' => 0];

        foreach ($this->fullModuleList->getAll() as $name => $module) {
            $enabled = $this->enabledModuleList->has($name);
            $thirdParty = strpos($name, 'Magento_') !== 0 && strpos($name, 'PayPal_') !== 0;
            try {
                $version = $this->packageInfo->getVersion($name);
                $package = $this->packageInfo->getPackageName($name);
            } catch (\Throwable $e) {
                $version = null;
                $package = null;
            }

            $modules[] = [
                'name' => $name,
                'enabled' => $enabled,
                'package' => $package ?: null,
                'version' => $version ?: null,
                'setup_version' => $module['setup_version'] ?? null,
                'third_party' => $thirdParty,
            ];

            $counts['total']++;
            $counts['enabled'] += $enabled ? 1 : 0;
            $counts['third_party'] += $thirdParty ? 1 : 0;
            $counts['third_party_enabled'] += ($thirdParty && $enabled) ? 1 : 0;
        }

        usort($modules, static function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $data = ['counts' => $counts, 'modules' => $modules];
        $archive->addJson(self::FILE, $data);

        return $data;
    }
}
