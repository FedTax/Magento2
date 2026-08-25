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

namespace Taxcloud\Magento2\Block\Adminhtml\Order;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Taxcloud\Magento2\Model\Certificate\OrderCertificateRecord;

/**
 * What exempted this order, shown on the order view.
 *
 * Reads the order's OWN record rather than asking TaxCloud what the customer
 * holds now. That is the entire point of keeping a snapshot: certificates can
 * be edited or deleted afterwards, and the question an order screen answers is
 * "why was this sale untaxed", which cannot change after the sale.
 *
 * Read-only once the order has been captured in TaxCloud. Before capture there
 * is still a decision to correct; after it, the sale has been filed and the
 * record is evidence.
 */
class CertificateInfo extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var OrderCertificateRecord
     */
    private $record;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param OrderCertificateRecord $record
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        OrderCertificateRecord $record,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->record = $record;
    }

    /**
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    public function getOrder()
    {
        return $this->registry->registry('current_order');
    }

    /**
     * Whether this order was exempted at all.
     *
     * @return bool
     */
    public function hasCertificate(): bool
    {
        return $this->record->certificateId($this->getOrder()) !== '';
    }

    /**
     * @return string
     */
    public function getCertificateId(): string
    {
        return $this->record->certificateId($this->getOrder());
    }

    /**
     * What the certificate said when the sale was made.
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->record->snapshot($this->getOrder());
    }

    /**
     * The covered states as recorded, already a comma list for display.
     *
     * @return string
     */
    public function getStatesLabel(): string
    {
        $states = $this->getSnapshot()['states'] ?? [];

        return is_array($states) ? implode(', ', $states) : '';
    }

    /**
     * Whether the applied certificate may still be changed.
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        $order = $this->getOrder();

        return $order !== null && !$order->getData('taxcloud_captured');
    }

    /**
     * Detail rows worth showing, skipping anything the transport did not carry.
     *
     * A v3 store records less about a certificate than a v1 store — no tax id,
     * sometimes no reason — and showing a blank row for it would read as "the
     * certificate says nothing" rather than "this API cannot tell us".
     *
     * @return array<string, string>
     */
    public function getDetailRows(): array
    {
        $snapshot = $this->getSnapshot();
        $labels = [
            'purchaserName' => (string) __('Issued to'),
            'purchaserAddress' => (string) __('Address'),
            'reason' => (string) __('Reason'),
            'reasonDescription' => (string) __('Description'),
            'businessType' => (string) __('Business type'),
            'taxId' => (string) __('Tax ID'),
            'createdDate' => (string) __('Created'),
        ];

        $rows = [];
        foreach ($labels as $key => $label) {
            if (!empty($snapshot[$key]) && is_string($snapshot[$key])) {
                $rows[$label] = $snapshot[$key];
            }
        }

        return $rows;
    }
}
