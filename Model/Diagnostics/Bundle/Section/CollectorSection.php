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

use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;

/**
 * collector-diagnostics.json: is TaxCloud the tax collector Magento runs?
 *
 * The single highest-value check in the bundle — another module taking the tax
 * total slot produces no error anywhere, only wrong tax. Runs the same
 * {@see TaxCollectorDiagnostics} verdict behind the admin notification and
 * `bin/magento taxcloud:diagnose`, for every store in scope, including stores
 * where TaxCloud is disabled (marked as such, so the reader is not misled).
 */
class CollectorSection implements SectionInterface
{
    public const FILE = 'collector-diagnostics.json';

    /**
     * @var TaxCollectorDiagnostics
     */
    private $diagnostics;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param TaxCollectorDiagnostics $diagnostics
     * @param TaxcloudConfig          $config
     */
    public function __construct(TaxCollectorDiagnostics $diagnostics, TaxcloudConfig $config)
    {
        $this->diagnostics = $diagnostics;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'collector';
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
        $stores = [];
        foreach ($context->getScope()->getStores() as $store) {
            $verdict = $this->diagnostics->forStore($store);
            $stores[(string) $store->getCode()] = [
                'store_id' => $verdict->getStoreId(),
                'store_name' => $verdict->getStoreName(),
                'taxcloud_enabled' => $this->config->isEnabled($store->getId()),
                'healthy' => $verdict->isHealthy(),
                'owned_by_taxcloud' => $verdict->isOwned(),
                'active_collector_class' => $verdict->getActiveCollectorClass(),
                'interceptors' => $verdict->getInterceptors(),
                'later_collectors' => $verdict->getLaterCollectors(),
                'failure_reason' => $verdict->getFailureReason(),
            ];
        }

        $data = [
            'note' => 'healthy means TaxCloud\'s collector runs; it does not mean credentials or tax amounts are '
                . 'correct — see probe.json for those.',
            'stores' => $stores,
        ];
        $archive->addJson(self::FILE, $data);

        return $data;
    }
}
