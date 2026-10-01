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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Audit;

use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleResult;

/**
 * Records who exported diagnostics, when, for which scope, and whether customer
 * details were masked — the question a merchant's compliance team asks about
 * any export of customer data.
 *
 * Written unconditionally to Magento's system log (independent of the TaxCloud
 * logging setting, which a merchant may have switched off). On Adobe Commerce
 * the same facts are attached to the Admin Actions Log entry for the export,
 * through {@see ActionLogHandler}; this class is where that handler reads them.
 */
class DiagnosticsAudit
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Facts of the export in the current request, for the action-log handler.
     *
     * @var array|null
     */
    private $lastRecord;

    /**
     * @param LoggerInterface $logger Magento's main logger (system.log)
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param BundleRequest     $request
     * @param BundleResult|null $result Null when generation failed
     * @param string|null       $error
     * @return array The recorded facts
     */
    public function record(BundleRequest $request, ?BundleResult $result, ?string $error = null): array
    {
        $record = [
            'event' => 'taxcloud_diagnostics_export',
            'admin_user' => $request->getGeneratedBy(),
            'origin' => $request->getOrigin(),
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'scope' => $result !== null
                ? $result->getScope()->getLabel()
                : $request->getScopeType() . ($request->getScopeId() !== null ? '/' . $request->getScopeId() : ''),
            // The increment id a merchant recognises; the entity id only when
            // generation failed before the order was loaded.
            'order' => $result !== null && $result->getOrderIncrementId() !== null
                ? $result->getOrderIncrementId()
                : ($request->getOrderIncrementId()
                    ?? ($request->getOrderId() !== null ? 'entity_id ' . $request->getOrderId() : null)),
            'customer_details' => $request->isRedactPii() ? 'masked' : 'included',
            'probe' => $request->isRunProbe(),
            'log_window' => $request->getLogWindow(),
            'file' => $result !== null ? $result->getFileName() : null,
            'status' => $error === null ? 'generated' : 'failed',
        ];
        if ($error !== null) {
            $record['error'] = $error;
        }

        $this->lastRecord = $record;
        $this->logger->info('TaxCloud diagnostics bundle exported', $record);

        return $record;
    }

    /**
     * @return array|null
     */
    public function getLastRecord(): ?array
    {
        return $this->lastRecord;
    }
}
