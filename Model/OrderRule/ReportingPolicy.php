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

namespace Taxcloud\Magento2\Model\OrderRule;

use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Whether an order's sale is recorded in, and reversed from, TaxCloud.
 *
 * The single gate for capture, refund and cancellation. An order carrying a
 * stored outcome is reported only when that outcome is Report. An order
 * without one was placed before order processing rules existed and keeps the
 * behaviour it had: its store view's "Report orders to TaxCloud" decides,
 * read against the order's store when the operation runs.
 */
class ReportingPolicy
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param TaxcloudConfig $config
     */
    public function __construct(TaxcloudConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param Order $order
     * @return bool
     */
    public function isReported(Order $order): bool
    {
        $outcome = (string) $order->getData(OutcomeRecorder::FIELD_OUTCOME);
        if ($outcome !== '') {
            return $outcome === OrderRuleInterface::ACTION_REPORT;
        }
        return $this->config->isReportingByDefault($order->getStoreId());
    }

    /**
     * Why an order is not reported, for log lines.
     *
     * @param Order $order
     * @return string
     */
    public function describe(Order $order): string
    {
        $outcome = (string) $order->getData(OutcomeRecorder::FIELD_OUTCOME);
        if ($outcome === '') {
            return 'store does not report orders';
        }
        $rule = (string) $order->getData(OutcomeRecorder::FIELD_RULE_NAME);
        return 'outcome: ' . $outcome . ($rule !== '' ? ', rule "' . $rule . '"' : ', store default');
    }
}
