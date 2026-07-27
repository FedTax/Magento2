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
     * @param TaxcloudConfig $config
     * @param \Taxcloud\Magento2\Api\OrderGatewayInterface $tcapi
     * @param \Psr\Log\LoggerInterface $tclogger Config-gated proxy, bound in di.xml
     */
    public function __construct(
        TaxcloudConfig $config,
        \Taxcloud\Magento2\Api\OrderGatewayInterface $tcapi,
        \Psr\Log\LoggerInterface $tclogger
    ) {
        $this->config = $config;
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
        }

        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        // Calculation-only stores never sent the sale to TaxCloud in the first
        // place, so there is nothing to reverse — a Returned call here would
        // reference an order TaxCloud has no record of.
        if ($this->config->isCalculationsOnly($storeId)) {
            $this->tclogger->info(
                'Skipping returnOrder for creditmemo ' . $creditmemo->getIncrementId() . ' (calculations-only mode)'
            );
            return;
        }

        $this->tclogger->info('Running Observer sales_order_creditmemo_refund');

        try {
            $this->tcapi->returnOrder($creditmemo);
        } catch (\Throwable $e) {
            // Magento has already committed the refund — don't let a TaxCloud
            // failure surface to the admin user.
            $this->tclogger->error('returnOrder threw exception: ' . $e->getMessage());
        }
    }
}
