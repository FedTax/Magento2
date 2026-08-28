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

namespace Taxcloud\Magento2\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\OrderCertificateRecord;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * Writes onto a placed order which certificate untaxed it, and what that
 * certificate said at the time.
 *
 * Runs before the quote is converted to an order, which is the last moment both
 * are in hand. It re-resolves rather than reading a value stashed during tax
 * calculation, because the quote has nowhere to stash one: the applied
 * certificate is not a quote column, and quote data without a column does not
 * survive the request that computed the totals.
 *
 * Re-resolving is deterministic and served from the certificate cache, so it
 * lands on the same certificate the totals used. The exception worth naming:
 * if the customer's certificates changed between the last totals calculation
 * and checkout, the record describes the certificate that WOULD apply now, and
 * TaxCloud will have been sent that same one when the order was filed — so the
 * record still matches what was transacted, which is the property that matters.
 */
class RecordCertificate implements ObserverInterface
{
    /**
     * @var CertificateResolver
     */
    private $resolver;

    /**
     * @var OrderCertificateRecord
     */
    private $record;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @param CertificateResolver $resolver
     * @param OrderCertificateRecord $record
     * @param TaxcloudConfig $config
     * @param GatewayLogger $logger
     */
    public function __construct(
        CertificateResolver $resolver,
        OrderCertificateRecord $record,
        TaxcloudConfig $config,
        GatewayLogger $logger
    ) {
        $this->resolver = $resolver;
        $this->record = $record;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $quote = $observer->getEvent()->getData('quote');
        if (!$order || !$quote) {
            return;
        }

        $storeId = $order->getStoreId();
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $customer = $quote->getCustomer();
        if (!$customer || !$customer->getId()) {
            // Guests hold no certificates; nothing to record.
            return;
        }

        $destinationState = $this->destinationState($order);
        if ($destinationState === '') {
            return;
        }

        try {
            $certificate = $this->resolver->resolve($customer, $destinationState, $storeId);
        } catch (\Throwable $e) {
            // Recording is evidence-keeping, not control flow: an order must
            // never fail to place because we could not describe its exemption.
            $this->logger->error(
                'Could not record the exemption certificate for order '
                . (string) $order->getIncrementId() . ': ' . $e->getMessage()
            );
            return;
        }

        if ($certificate === null) {
            return;
        }

        $this->record->record($order, $certificate);
    }

    /**
     * The state tax was calculated against — shipping where there is one,
     * billing for a virtual order.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|\Magento\Framework\DataObject $order
     * @return string
     */
    private function destinationState($order)
    {
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        if (!$address) {
            return '';
        }

        $region = $address->getRegionCode();

        return is_string($region) ? $region : '';
    }
}
