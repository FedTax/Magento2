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

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LogLevel;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Model\Tax;

/**
 * Catches the case where TaxCloud is enabled but another module owns the tax
 * collector, at the one moment it is provable: an order being placed.
 *
 * Tax::collect() marks the quote when it runs. This observer fires on
 * sales_model_service_quote_submit_before, which Magento dispatches whether or
 * not our collector ran — so a missing mark on a store with TaxCloud enabled
 * means our calculation never happened. Without this the failure is completely
 * silent: no exception, no zero-tax error, just an order that under-collected.
 *
 * QuoteManagement::placeOrderRun() calls collectTotals() and then submit() in
 * the same request on the same quote object, so the transient mark is in hand
 * here. The totals-collected guard below covers the paths where it is not.
 *
 * Deliberately computes no collector verdict: that would put the diagnostics
 * probe on the order placement path. Naming the module that won belongs to
 * `bin/magento taxcloud:diagnose` and the admin notification.
 */
class VerifyTaxCollector implements ObserverInterface
{
    /**
     * Cache key prefix for the per-store warning rate limit.
     */
    private const RATE_LIMIT_KEY_PREFIX = 'taxcloud_collector_warning_';

    /**
     * One warning per store per hour. A busy store that has lost the collector
     * loses it on every order; the log needs the signal, not the volume.
     */
    private const RATE_LIMIT_SECONDS = 3600;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @var FrontendInterface
     */
    private $cache;

    /**
     * @param TaxcloudConfig    $config
     * @param GatewayLogger     $logger
     * @param FrontendInterface $cache Bound to the TaxCloud cache type in di.xml
     */
    public function __construct(
        TaxcloudConfig $config,
        GatewayLogger $logger,
        FrontendInterface $cache
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote instanceof \Magento\Quote\Model\Quote) {
            return;
        }

        // Nothing to conclude if totals were never collected on this object in
        // this request — the mark's absence would say nothing about ownership.
        if (!$quote->getTotalsCollectedFlag()) {
            return;
        }

        // The quote's own store, not the ambient one: admin order creation and
        // API checkouts run with the default store view in scope.
        $storeId = $quote->getStoreId();
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        if ($quote->getData(Tax::COLLECTED_FLAG)) {
            return;
        }

        if (!$this->shouldWarn((int) $storeId)) {
            return;
        }

        $this->logger->setStore($storeId);
        $this->logger->log(
            LogLevel::WARNING,
            'TaxCloud is enabled for this store but its tax collector did not run, so no TaxCloud '
            . 'tax was calculated for this order. Another module has taken the tax total collector. '
            . 'Run bin/magento taxcloud:diagnose to see which one.',
            ['store_id' => $storeId, 'quote_id' => $quote->getId()]
        );
    }

    /**
     * @param int $storeId
     * @return bool
     */
    private function shouldWarn(int $storeId): bool
    {
        $key = self::RATE_LIMIT_KEY_PREFIX . $storeId;
        if ($this->cache->test($key)) {
            return false;
        }
        $this->cache->save('1', $key, [], self::RATE_LIMIT_SECONDS);

        return true;
    }
}
