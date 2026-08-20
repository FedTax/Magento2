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

namespace Taxcloud\Magento2\Model\Certificate;

/**
 * What an order records about the certificate that untaxed it.
 *
 * The identifier alone would not do. TaxCloud keeps neither the certificate
 * document nor an expiry date — their own documentation puts both on the
 * merchant — and a certificate can be deleted outright. Years later, when
 * someone asks why this sale carried no tax, an identifier may point at
 * nothing.
 *
 * So the order keeps a copy of what the certificate said when the sale
 * happened. WRITTEN ONCE. A record that tracked later edits would answer "what
 * does this certificate say now", which is not the question anyone asks of an
 * order — and would quietly rewrite the evidence for a sale already made.
 */
class OrderCertificateRecord
{
    /**
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    private $serializer;

    /**
     * @param \Magento\Framework\Serialize\SerializerInterface $serializer
     */
    public function __construct(\Magento\Framework\Serialize\SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * Record the certificate that exempted this order.
     *
     * A no-op when the order already carries a record: the first write is the
     * one that describes the sale.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|\Magento\Framework\DataObject $order
     * @param Certificate $certificate
     * @return void
     */
    public function record($order, Certificate $certificate)
    {
        if ($order === null || $this->certificateId($order) !== '') {
            return;
        }

        $order->setData('taxcloud_certificate_id', $certificate->getCertificateId());
        $order->setData(
            'taxcloud_certificate_snapshot',
            $this->serializer->serialize($certificate->toSnapshot())
        );
    }

    /**
     * The certificate identifier recorded on an order, or '' when it was taxed.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|\Magento\Framework\DataObject $order
     * @return string
     */
    public function certificateId($order)
    {
        if ($order === null) {
            return '';
        }

        $value = $order->getData('taxcloud_certificate_id');

        return is_string($value) ? $value : '';
    }

    /**
     * What the certificate said when this order was placed.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|\Magento\Framework\DataObject $order
     * @return array<string, mixed> Empty when the order carries no record
     */
    public function snapshot($order)
    {
        if ($order === null) {
            return [];
        }

        $raw = $order->getData('taxcloud_certificate_snapshot');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\Throwable $e) {
            // A record we cannot read is not worth failing an order over; it is
            // evidence, not control flow.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
