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

namespace Taxcloud\Magento2\Observer\Sales;

use \Magento\Framework\Event\ObserverInterface;
use \Magento\Framework\Event\Observer;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Model\OrderRule\ReportingPolicy;

class Refund implements ObserverInterface
{

    /**
     * TaxCloud store-scoped configuration reader
     *
     * @var TaxcloudConfig
     */
    protected $config;

    /**
     * TaxCloud order-lifecycle gateway
     *
     * @var \Taxcloud\Magento2\Api\OrderGatewayInterface
     */
    protected $tcapi;

    /**
     * TaxCloud Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $tclogger;

    /**
     * @var ReportingPolicy
     */
    private $reportingPolicy;

    /**
     * @param TaxcloudConfig $config
     * @param \Taxcloud\Magento2\Api\OrderGatewayInterface $tcapi
     * @param \Psr\Log\LoggerInterface $tclogger Config-gated proxy, bound in di.xml
     * @param ReportingPolicy|null $reportingPolicy Bound in di.xml
     */
    public function __construct(
        TaxcloudConfig $config,
        \Taxcloud\Magento2\Api\OrderGatewayInterface $tcapi,
        \Psr\Log\LoggerInterface $tclogger,
        ?ReportingPolicy $reportingPolicy = null
    ) {
        $this->config = $config;
        $this->reportingPolicy = $reportingPolicy ?? new ReportingPolicy($config);
        $this->tcapi = $tcapi;

        $this->tclogger = $tclogger;
    }

    /**
     * @param Observer $observer
     */
    public function execute(
        Observer $observer
    ) {
        // Credit memos are issued from the admin, where the ambient store is
        // the default store view — gate on the ORDER's store instead.
        $creditmemo = $observer->getEvent()->getCreditmemo();
        $storeId = $creditmemo->getOrder()->getStoreId();

        if ($this->tclogger instanceof GatewayLogger) {
            $this->tclogger->setStore($storeId);
            $this->tclogger->beginOperation(
                'refund',
                $creditmemo->getOrder()->getQuoteId(),
                $creditmemo->getOrder()->getIncrementId()
            );
        }

        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        // An order kept from TaxCloud was never sent there, so there is
        // nothing to reverse — a Returned call here would reference an order
        // TaxCloud has no record of.
        $order = $creditmemo->getOrder();
        if (!$this->reportingPolicy->isReported($order)) {
            $this->tclogger->info(
                'Skipping returnOrder for creditmemo ' . $creditmemo->getIncrementId()
                . ' (' . $this->reportingPolicy->describe($order) . ')'
            );
            return;
        }

        $this->tclogger->info('Running Observer sales_order_creditmemo_refund');

        $orderNumber = $creditmemo->getOrder()->getIncrementId();
        try {
            if ($this->tcapi->returnOrder($creditmemo)) {
                $this->tclogger->info('Refund for order ' . $orderNumber . ' recorded in TaxCloud');
            } else {
                // The gateway has already logged why; this line marks the outcome.
                $this->tclogger->warning('Refund for order ' . $orderNumber . ' was NOT recorded in TaxCloud');
            }
        } catch (\Throwable $e) {
            // Magento has already committed the refund — don't let a TaxCloud
            // failure surface to the admin user.
            $this->tclogger->error('returnOrder threw exception: ' . $e->getMessage());
        }
    }
}
