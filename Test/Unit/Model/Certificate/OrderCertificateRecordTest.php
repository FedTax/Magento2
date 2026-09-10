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

namespace Taxcloud\Magento2\Test\Unit\Model\Certificate;

use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\OrderCertificateRecord;

/**
 * The order's record is audit evidence: what the certificate said when the sale
 * was made. Its whole value rests on not changing afterwards, so that is what
 * most of these tests pin.
 */
class OrderCertificateRecordTest extends TestCase
{
    private function record(): OrderCertificateRecord
    {
        return new OrderCertificateRecord(new Json());
    }

    private function certificate(string $id = 'cert-tx', array $states = ['TX']): Certificate
    {
        return new Certificate($id, '42', $states, false, false, [
            'reason' => 'Resale',
            'purchaserName' => 'Exempt Customer',
            'createdDate' => '2026-08-19T14:09:00Z',
            'taxId' => '**-***4567',
        ]);
    }

    public function testAppliedCertificateIsRecordedWithItsDetail()
    {
        $order = new DataObject();
        $record = $this->record();

        $record->record($order, $this->certificate());

        $this->assertSame('cert-tx', $record->certificateId($order));

        $snapshot = $record->snapshot($order);
        $this->assertSame('cert-tx', $snapshot['certificateId']);
        $this->assertSame('42', $snapshot['customerId']);
        $this->assertSame(['TX'], $snapshot['states']);
        $this->assertSame('Resale', $snapshot['reason']);
        $this->assertSame('Exempt Customer', $snapshot['purchaserName']);
        $this->assertSame('**-***4567', $snapshot['taxId']);
    }

    /**
     * A taxed order must carry nothing, so "was this order exempt" is
     * answerable from the order alone.
     */
    public function testTaxedOrderCarriesNoRecord()
    {
        $order = new DataObject();
        $record = $this->record();

        $this->assertSame('', $record->certificateId($order));
        $this->assertSame([], $record->snapshot($order));
    }

    /**
     * The property the whole design rests on. If the certificate is edited or
     * replaced later, the order must still describe the sale as it happened.
     */
    public function testRecordIsWrittenOnceAndNeverRevised()
    {
        $order = new DataObject();
        $record = $this->record();

        $record->record($order, $this->certificate('cert-original', ['TX']));
        $record->record($order, $this->certificate('cert-replacement', ['NY']));

        $this->assertSame('cert-original', $record->certificateId($order));
        $this->assertSame(['TX'], $record->snapshot($order)['states']);
    }

    /**
     * The snapshot is a copy, not a live view: mutating the certificate object
     * afterwards cannot reach it.
     */
    public function testSnapshotIsIndependentOfTheCertificateObject()
    {
        $order = new DataObject();
        $record = $this->record();
        $certificate = $this->certificate();

        $record->record($order, $certificate);
        unset($certificate);

        $this->assertSame('cert-tx', $record->snapshot($order)['certificateId']);
    }

    /**
     * Evidence, not control flow — an unreadable record must not throw into an
     * order screen.
     */
    public function testUnreadableSnapshotDegradesToEmpty()
    {
        $order = new DataObject(['taxcloud_certificate_snapshot' => 'not-json']);

        $this->assertSame([], $this->record()->snapshot($order));
    }

    public function testNullOrderIsHandled()
    {
        $record = $this->record();

        $this->assertSame('', $record->certificateId(null));
        $this->assertSame([], $record->snapshot(null));
        $record->record(null, $this->certificate());
    }
}
