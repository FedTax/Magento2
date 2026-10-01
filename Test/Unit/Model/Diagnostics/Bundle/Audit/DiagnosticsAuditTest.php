<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Audit;

use Magento\Framework\DataObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Audit\ActionLogHandler;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Audit\DiagnosticsAudit;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleResult;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleScope;

/**
 * Compliance asks who exported customer data, when, for what, and whether it
 * was masked. Every export answers that, whatever the TaxCloud logging setting.
 */
#[AllowMockObjectsWithoutExpectations]
class DiagnosticsAuditTest extends TestCase
{
    public function testRecordsUserScopeTimeAndRedactionMode()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->expects($this->once())->method('info')->willReturnCallback(function ($message, $context) use (&$logged) {
            $logged = $context;
        });

        $request = new BundleRequest(BundleRequest::SCOPE_STORE, 2, true, 'standard', true, 'jane.admin');
        $result = new BundleResult('/tmp/x.zip', 'taxcloud-diagnostics-us_es-20260914-100000.zip', [], [],
            new BundleScope(BundleRequest::SCOPE_STORE, 2, 'us_es', [], []));

        (new DiagnosticsAudit($logger))->record($request, $result);

        $this->assertSame('jane.admin', $logged['admin_user']);
        $this->assertSame('stores/us_es', $logged['scope']);
        $this->assertSame('masked', $logged['customer_details']);
        $this->assertSame('generated', $logged['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $logged['timestamp_utc']);
    }

    public function testActionLogEventCarriesTheSameFacts()
    {
        $audit = new DiagnosticsAudit($this->createMock(LoggerInterface::class));
        $audit->record(new BundleRequest(BundleRequest::SCOPE_DEFAULT, null, false, 'standard', false, 'jane', 'admin', 65), null, 'boom');

        $event = new DataObject();
        $this->assertTrue((new ActionLogHandler($audit))->postDispatchExport([], $event));
        $this->assertSame(
            'scope: default; order: entity_id 65; customer details: included; probe: no; status: failed',
            $event->getData('info')
        );
    }

    public function testAPerOrderExportIsRecordedByTheOrderNumberNotTheEntityId()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logged = null;
        $logger->method('info')->willReturnCallback(function ($message, $context) use (&$logged) {
            $logged = $context;
        });

        // The admin button posts the entity id; the audit must name the order a merchant knows.
        $request = new BundleRequest(BundleRequest::SCOPE_DEFAULT, null, false, 'standard', false, 'jane', 'admin', 66);
        $result = new BundleResult('/tmp/x.zip', 'x.zip', [], [],
            new BundleScope(BundleRequest::SCOPE_STORE, 1, 'default', [], []), 'SO000000073');

        (new DiagnosticsAudit($logger))->record($request, $result);

        $this->assertSame('SO000000073', $logged['order']);
    }
}
