<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Summary;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialFingerprint;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\LogsSection;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Summary\SummaryRenderer;

/**
 * summary.md is read cold. Anything that limits the diagnosis must be at the
 * top, anomalies must be flagged where they are, and masked values must be
 * visibly masked.
 */
class SummaryRendererTest extends TestCase
{
    private function meta(array $overrides = []): array
    {
        return $overrides + [
            'schema_version' => '1.0',
            'generated_at_utc' => '2026-09-14T10:00:00Z',
            'generated_at_store' => '2026-09-14T05:00:00-05:00',
            'generated_by' => 'jane.admin',
            'origin' => 'admin',
            'scope_label' => 'default',
            'redact_pii' => false,
            'order_increment_id' => null,
        ];
    }

    private function effective($value, array $extra = []): array
    {
        return ['us_en' => ['resolved_from' => 'default', 'locked' => false, 'value' => $value] + $extra];
    }

    private function healthySections(): array
    {
        $fp = (new CredentialFingerprint())->fingerprint('8F1C2D3E-4A5B-6C7D-8E9F-0A1B2C3D4E5F');

        return [
            'environment' => [
                'magento' => ['version' => '2.4.8-p1', 'edition' => 'Community', 'deploy_mode' => 'production'],
                'taxcloud_module_version' => '1.5.0',
                'php' => ['version' => '8.3.4', 'sapi' => 'fpm-fcgi', 'extensions' => [
                    'soap' => ['loaded' => true], 'curl' => ['loaded' => true],
                ], 'ini' => []],
                'infrastructure' => ['cache_backend' => 'Magento\\Framework\\Cache\\Backend\\Redis', 'session_storage' => 'redis'],
                'database' => ['server_version' => '10.6'],
                'timezones' => ['server' => 'UTC'],
                'cron' => ['last_successful_run_at' => gmdate('Y-m-d H:i:s'), 'stuck_running_total' => 0,
                    'error_count_last_24h' => 0],
                'indexers' => ['invalid' => []],
            ],
            'settings' => [
                'settings' => [
                    'enabled' => ['effective' => $this->effective('1')],
                    'api_type' => ['effective' => $this->effective('rest')],
                    'logging' => ['effective' => $this->effective('1')],
                    'rest_connection_id' => ['effective' => $this->effective('25eb9b97-5acb-492d-b720-c03e79cf715a')],
                    'rest_api_key' => ['effective' => ['us_en' => ['resolved_from' => 'default', 'locked' => false,
                        'fingerprint' => $fp]]],
                    'default_tic' => ['effective' => $this->effective('20010')],
                ],
                'locked' => [],
            ],
            'magento_tax' => ['taxcloud_cache_type_enabled' => true, 'tax_rules' => [], 'tax_rates' => ['count' => 0]],
            'collector' => ['stores' => ['us_en' => [
                'healthy' => true, 'owned_by_taxcloud' => true, 'taxcloud_enabled' => true,
                'active_collector_class' => 'Taxcloud\\Magento2\\Model\\Tax', 'interceptors' => [],
                'later_collectors' => [], 'failure_reason' => null,
            ]]],
            'probe' => ['ran' => true, 'configurations' => [[
                'stores' => ['us_en'], 'api_type' => 'rest', 'endpoint' => 'https://api.v3.taxcloud.com',
                'network' => ['dns' => ['ok' => true, 'duration_ms' => 3], 'tls' => ['ok' => true, 'duration_ms' => 40]],
                'authentication' => ['ok' => true, 'method' => 'api_key'],
                'calls' => ['lookup' => ['success' => true, 'http_status' => 200, 'duration_ms' => 300]],
            ]]],
            'logs' => [
                'taxcloud_log' => ['path' => '/var/www/var/log/taxcloud.log', 'status' => LogsSection::STATUS_OK,
                    'size' => 2048, 'modified_at' => '2026-09-14T09:00:00+00:00'],
                'logging_modes' => ['us_en' => 'basic'],
                'window' => ['name' => 'standard', 'max_bytes' => 10485760, 'max_days' => 7],
                'files' => ['logs/taxcloud.log' => ['included' => true, 'bytes' => 2048, 'records' => 12]],
            ],
        ];
    }

    private function render(array $sections, array $meta = [], array $failures = []): string
    {
        return (new SummaryRenderer(new CredentialFingerprint()))->render($this->meta($meta), $sections, $failures);
    }

    private function blockers(string $summary): string
    {
        $start = strpos($summary, '## Blockers');
        $end = strpos($summary, '## Environment');

        return substr($summary, $start, $end - $start);
    }

    public function testAHealthyStoreHasNoBlockersAndStatesTheSchema()
    {
        $summary = $this->render($this->healthySections());

        $this->assertStringContainsString("## Blockers\n\nNone.", $summary);
        $this->assertStringContainsString('Bundle schema 1.0', $summary);
        $this->assertStringContainsString('`jane.admin`', $summary);
        $this->assertStringContainsString('customer details: included as-is', $summary);
        $this->assertStringContainsString('Magento 2.4.8-p1 Community · PHP 8.3.4 (fpm-fcgi) · TaxCloud 1.5.0 · production mode', $summary);
        $this->assertStringContainsString('25eb9b97-5acb-492d-b720-c03e79cf715a', $summary);
        $this->assertStringContainsString('[redacted: 36 chars, …4E5F', $summary);
        $this->assertStringNotContainsString('8F1C2D3E-4A5B', $summary);

        $order = ['## Blockers', '## Environment', '## TaxCloud settings', '## Tax collector', '## Live API probe', '## Logs'];
        $positions = array_map(function ($heading) use ($summary) {
            return strpos($summary, $heading);
        }, $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'sections appear in the documented order');
    }

    public function testEverythingThatLimitsTheDiagnosisIsABlocker()
    {
        $sections = $this->healthySections();
        $sections['settings']['settings']['logging']['effective'] = $this->effective('0');
        $sections['logs']['taxcloud_log']['status'] = LogsSection::STATUS_MISSING;
        $sections['collector']['stores']['us_en'] = [
            'healthy' => false, 'owned_by_taxcloud' => false, 'taxcloud_enabled' => true,
            'active_collector_class' => 'Vendor\\Avalara\\Model\\Tax', 'interceptors' => [], 'later_collectors' => [],
            'failure_reason' => null,
        ];
        $sections['probe']['configurations'][0]['calls']['lookup'] = [
            'success' => false, 'http_status' => 401, 'duration_ms' => 120, 'error_message' => 'Unauthorized',
        ];
        $sections['environment']['indexers']['invalid'] = ['catalog_product_attribute'];
        unset($sections['magento_tax']);

        $blockers = $this->blockers($this->render(
            $sections,
            ['redact_pii' => true],
            [['section' => 'magento_tax', 'message' => 'SQLSTATE[42S02]']]
        ));

        $this->assertStringContainsString('Logging is set to Disable on `us_en`', $blockers);
        $this->assertStringContainsString('TaxCloud log file does not exist', $blockers);
        $this->assertStringContainsString('TaxCloud is not the active tax collector on `us_en`: displaced by Vendor\\Avalara\\Model\\Tax', $blockers);
        $this->assertStringContainsString('Probe lookup failed for `us_en`', $blockers);
        $this->assertStringContainsString('Invalid indexers: catalog_product_attribute', $blockers);
        $this->assertStringContainsString('The `magento_tax` collector failed', $blockers);
        $this->assertStringContainsString('Customer details were masked', $blockers);
        $this->assertStringContainsString(PiiRedactor::MARKER, $blockers);
    }

    public function testCredentialProblemsAreBlockers()
    {
        $sections = $this->healthySections();
        $fp = new CredentialFingerprint();
        $sections['settings']['settings']['rest_api_key']['effective']['us_en']['fingerprint'] =
            $fp->fingerprint('8F1C2D3E-4A5B-6C7D-8E9F-0A1B2C3D4E5F ');
        $sections['settings']['settings']['rest_connection_id']['effective'] = $this->effective('');

        $blockers = $this->blockers($this->render($sections));

        $this->assertStringContainsString('`rest_api_key` on `us_en` contains trailing whitespace', $blockers);
        $this->assertStringContainsString('Connection ID is empty on `us_en`', $blockers);
    }

    public function testAnomaliesAreFlaggedInline()
    {
        $sections = $this->healthySections();
        $sections['settings']['settings']['default_tic']['effective'] = $this->effective('00000');
        $sections['settings']['settings']['logging']['effective']['us_en']['locked'] = true;
        $sections['settings']['locked'] = [['setting' => 'logging', 'scope' => 'default', 'locked_by' => 'app/etc/env.php']];
        $sections['magento_tax']['tax_rules'] = [['code' => 'US Retail']];
        $sections['magento_tax']['tax_rates']['count'] = 12;
        $sections['environment']['cron']['stuck_running_total'] = 2;
        $sections['environment']['cron']['stuck_running'] = ['indexer_update_all_views' => 2];

        $summary = $this->render($sections);

        $this->assertStringContainsString('`basic` 🔒', $summary);
        $this->assertStringContainsString('Setting `logging` is locked at default by app/etc/env.php', $summary);
        $this->assertStringContainsString('Default TIC is 00000', $summary);
        $this->assertStringContainsString('1 native Magento tax rule(s) and 12 rate(s)', $summary);
        $this->assertStringContainsString('Cron jobs stuck in "running": indexer_update_all_views 2', $summary);
    }

    public function testPerOrderSummaryShowsTicsAndSaysWhenNoLogLinesWereFound()
    {
        $sections = $this->healthySections();
        $sections['order'] = [
            'increment_id' => '100000123', 'entity_id' => 9, 'quote_id' => 77, 'store' => ['code' => 'us_en'],
            'created_at' => '2026-09-14 09:00:00', 'state' => 'processing', 'status' => 'processing',
            'customer' => ['is_guest' => true],
            'currency' => ['order' => 'USD'],
            'totals' => ['subtotal' => 100, 'discount' => 0, 'shipping' => 5, 'shipping_tax' => 0, 'tax' => 8.25,
                'grand_total' => 113.25, 'total_invoiced' => 0, 'total_refunded' => 0],
            'shipping_address' => ['street' => [PiiRedactor::MARKER], 'city' => 'Austin', 'region_code' => 'TX',
                'postcode' => '78701', 'country_id' => 'US'],
            'billing_address' => null,
            'shipping_method' => 'flatrate_flatrate',
            'taxcloud' => ['taxcloud_captured' => null, 'tax_source' => ['determination' => 'magento_fallback',
                'reason' => 'fallback']],
            'items' => ['lines' => [[
                'parent_item_id' => null, 'sku' => 'SKU|1', 'product_type' => 'simple', 'qty_ordered' => 1,
                'price' => 100, 'row_total' => 100, 'discount_amount' => 0, 'tax_amount' => 8.25,
                'tic' => '20010', 'tic_source' => 'category',
            ]]],
            'invoices' => [], 'credit_memos' => [], 'shipments' => [],
        ];
        $sections['logs']['order_correlation'] = ['correlated_records' => 0];

        $summary = $this->render($sections, ['order_increment_id' => '100000123', 'redact_pii' => true]);

        $this->assertStringContainsString('# TaxCloud diagnostics — order #100000123', $summary);
        $this->assertStringContainsString('| SKU\\|1 | simple | 1 | 100 | 100 | 0 | 8.25 | 20010 | category |', $summary);
        $this->assertStringContainsString('Ship to: ' . PiiRedactor::MARKER . ', Austin, TX 78701 US', $summary);
        $blockers = $this->blockers($summary);
        $this->assertStringContainsString('No TaxCloud log lines were found for this order', $blockers);
        $this->assertStringContainsString('tax came from the Magento fallback', $blockers);
    }

    public function testAPendingSetupUpgradeIsABlocker()
    {
        $sections = $this->healthySections();
        $sections['environment']['database_upgrade'] = [
            'up_to_date' => false,
            'module_versions' => [['module' => 'Taxcloud_Magento2', 'type' => 'data', 'database' => '1.4.0', 'code' => '1.5.0']],
            'schema_patches_pending' => false,
            'data_patches_pending' => true,
        ];

        $blockers = $this->blockers($this->render($sections));

        $this->assertStringContainsString('bin/magento setup:upgrade', $blockers);
        $this->assertStringContainsString('Taxcloud_Magento2 data 1.4.0→1.5.0', $blockers);
        $this->assertStringContainsString('data patches not applied', $blockers);

        $sections['environment']['database_upgrade'] = ['up_to_date' => true];
        $this->assertStringNotContainsString('setup:upgrade', $this->render($sections));
    }

    public function testLogProblemsAreListedWithCountAndAgeAndRecentErrorsAreFlagged()
    {
        $sections = $this->healthySections();
        $sections['logs']['digest'] = [
            'problems' => [
                'logs/system-taxcloud.log' => [[
                    'level' => 'CRITICAL', 'count' => 17,
                    'message' => 'Type Error occurred when creating object: Taxcloud\\Magento2\\Model\\Tax\\Interceptor',
                    'first_seen' => '2026-08-20T10:00:00+00:00', 'last_seen' => '2026-08-25T13:40:47+00:00',
                ]],
                'logs/taxcloud.log' => [[
                    'level' => 'ERROR', 'count' => 1, 'message' => 'Error encountered during lookupTaxes: HTTP 401',
                    'first_seen' => '2026-09-14T09:30:00+00:00', 'last_seen' => '2026-09-14T09:30:00+00:00',
                ]],
            ],
            'overflow' => [],
            'activity' => ['last_record_at' => '2026-09-14T09:30:00+00:00',
                'last_successful_lookup_at' => '2026-09-13T09:30:00+00:00'],
        ];

        $summary = $this->render($sections);

        $this->assertStringContainsString('## Log problems', $summary);
        $this->assertStringContainsString(
            '- CRITICAL ×17, last 2026-08-25 13:40 UTC (19 days ago), first 2026-08-20 10:00 UTC (25 days ago): Type Error',
            $summary
        );
        $this->assertStringContainsString('successful lookup 2026-09-13 09:30 UTC (24h ago)', $summary);
        $this->assertStringContainsString('capture none recorded', $summary);
        // Only the error from the last 24 hours is called out; the stale one is not.
        $this->assertStringContainsString('1 distinct error(s) in `logs/taxcloud.log` within the 24 hours', $summary);
        $this->assertStringNotContainsString('in `logs/system-taxcloud.log` within', $summary);
    }

    public function testSaysSoWhenTheExportedLogsHaveNoProblems()
    {
        $sections = $this->healthySections();
        $sections['logs']['digest'] = ['problems' => [], 'overflow' => [], 'activity' => []];

        $this->assertStringContainsString('No warnings or errors in the exported log records.', $this->render($sections));
    }

    public function testASilentLogIsCalledOutWhenLoggingIsOn()
    {
        $sections = $this->healthySections();
        $sections['logs']['taxcloud_log']['mtime_unix'] = strtotime('2026-09-09T10:00:00Z');

        $this->assertStringContainsString(
            'Nothing has been written to the TaxCloud log for 5 days (last write 2026-09-09 10:00 UTC)',
            $this->render($sections)
        );

        $sections['logs']['logging_modes'] = ['us_en' => 'disabled'];
        $this->assertStringNotContainsString('Nothing has been written', $this->render($sections));

        $sections['logs']['logging_modes'] = ['us_en' => 'basic'];
        $sections['logs']['taxcloud_log']['mtime_unix'] = strtotime('2026-09-14T09:00:00Z');
        $this->assertStringNotContainsString('Nothing has been written', $this->render($sections));
    }
}
