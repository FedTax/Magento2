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

/**
 * Adobe Commerce Admin Actions Log callback for the diagnostics export.
 *
 * Referenced from etc/logging.xml, which only Magento_Logging (Adobe Commerce)
 * reads; on Magento Open Source nothing calls this. Typed loosely on purpose:
 * the event model class exists only on Commerce, and this module must not
 * depend on it.
 */
class ActionLogHandler
{
    /**
     * @var DiagnosticsAudit
     */
    private $audit;

    /**
     * @param DiagnosticsAudit $audit
     */
    public function __construct(DiagnosticsAudit $audit)
    {
        $this->audit = $audit;
    }

    /**
     * Attach scope, order and redaction mode to the logged event.
     *
     * @param array  $config     Event config from logging.xml
     * @param object $eventModel \Magento\Logging\Model\Event
     * @return bool True to save the event
     */
    public function postDispatchExport($config, $eventModel): bool
    {
        $record = $this->audit->getLastRecord();
        // is_callable, not method_exists: setInfo() is a magic DataObject setter.
        if ($record !== null && is_callable([$eventModel, 'setInfo'])) {
            $eventModel->setInfo(sprintf(
                'scope: %s%s; customer details: %s; probe: %s; status: %s',
                $record['scope'],
                $record['order'] !== null ? '; order: ' . $record['order'] : '',
                $record['customer_details'],
                $record['probe'] ? 'yes' : 'no',
                $record['status']
            ));
        }

        return true;
    }
}
