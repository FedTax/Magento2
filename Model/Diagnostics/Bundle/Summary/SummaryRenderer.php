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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Summary;

use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialFingerprint;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\LogsSection;

/**
 * summary.md: the bundle as one flat, self-contained report.
 *
 * Written for a reader with no access to the store — a support engineer
 * skimming for a minute, or an AI assistant given the file whole. It opens with
 * everything that limits the diagnosis, then walks environment, effective
 * settings, collector verdict, probe and (per-order) the order, flagging
 * anomalies inline instead of leaving them to be noticed. It repeats only what
 * it needs to stand alone; the JSON files hold the detail.
 */
class SummaryRenderer
{
    /**
     * Settings shown in the summary's effective-settings table, in order.
     * Everything else stays in settings.json.
     */
    private const SUMMARY_SETTINGS = [
        'enabled', 'api_type', 'logging', 'rest_connection_id', 'rest_api_key', 'api_id', 'api_key',
        'verify_address', 'fallback_to_magento', 'calculations_only', 'capture_trigger', 'default_tic',
        'shipping_tic', 'cache_lifetime', 'api_timeout', 'exemptions_enabled', 'co_rdf_enabled',
    ];

    /**
     * Stores shown as table columns before switching to one list per store.
     */
    private const MAX_STORE_COLUMNS = 4;

    /**
     * Distinct log problems listed per log file.
     */
    private const MAX_PROBLEMS_PER_LOG = 10;

    /**
     * Age after which log silence or a log problem is called out as such.
     */
    private const STALE_AFTER_SECONDS = 86400;

    /**
     * @var CredentialFingerprint
     */
    private $fingerprint;

    /**
     * @param CredentialFingerprint $fingerprint
     */
    public function __construct(CredentialFingerprint $fingerprint)
    {
        $this->fingerprint = $fingerprint;
    }

    /**
     * @param array $meta     Manifest-level facts: schema_version, generated_at_utc, generated_at_store,
     *                        generated_by, origin, scope_label, redact_pii, probe_enabled, order_increment_id
     * @param array $sections Section data keyed by section code (absent when a section failed)
     * @param array $failures [['section' => ..., 'message' => ...], ...]
     * @return string
     */
    public function render(array $meta, array $sections, array $failures): string
    {
        $flags = [];
        $out = [];

        $title = !empty($meta['order_increment_id'])
            ? 'TaxCloud diagnostics — order #' . $meta['order_increment_id']
            : 'TaxCloud diagnostics — ' . $meta['scope_label'];
        $out[] = '# ' . $title;
        $out[] = '';
        $out[] = sprintf(
            'Bundle schema %s · generated %s (store time %s) by %s via %s · credentials: always redacted'
            . ' (fingerprints only) · customer details: %s',
            $meta['schema_version'],
            $meta['generated_at_utc'],
            $meta['generated_at_store'] ?? 'n/a',
            $meta['generated_by'] !== '' ? '`' . $meta['generated_by'] . '`' : 'unknown user',
            $meta['origin'],
            !empty($meta['redact_pii']) ? '**masked** (shown as `' . PiiRedactor::MARKER . '`)' : 'included as-is'
        );
        $out[] = '';

        $body = [];
        $body = array_merge($body, $this->environment($sections['environment'] ?? null, $flags));
        $body = array_merge(
            $body,
            $this->settings($sections['settings'] ?? null, $sections['magento_tax'] ?? null, $flags)
        );
        $body = array_merge($body, $this->collector($sections['collector'] ?? null, $flags));
        $body = array_merge($body, $this->probe($sections['probe'] ?? null, $flags));
        if (!empty($meta['order_increment_id'])) {
            $body = array_merge($body, $this->order($sections['order'] ?? null, $flags));
        }
        $now = strtotime((string) ($meta['generated_at_utc'] ?? '')) ?: time();
        $body = array_merge(
            $body,
            $this->logs($sections['logs'] ?? null, !empty($meta['order_increment_id']), $now, $flags)
        );

        // Blockers are computed while rendering the body, but must come first.
        $blockers = $flags['blockers'] ?? [];
        if (!empty($meta['redact_pii'])) {
            $blockers[] = 'Customer details were masked at generation time. A `' . PiiRedactor::MARKER
                . '` value means "present but masked", not "absent".';
        }
        foreach ($failures as $failure) {
            $blockers[] = sprintf(
                'The `%s` collector failed and its data is missing from this bundle: %s',
                $failure['section'],
                $failure['message']
            );
        }

        $out[] = '## Blockers';
        $out[] = '';
        if ($blockers === []) {
            $out[] = 'None. Nothing in this bundle limits the diagnosis.';
        } else {
            foreach (array_values(array_unique($blockers)) as $blocker) {
                $out[] = '- **' . $blocker . '**';
            }
        }
        $out[] = '';

        $out = array_merge($out, $body);

        $warnings = $flags['warnings'] ?? [];
        $out[] = '## Other findings';
        $out[] = '';
        if ($warnings === []) {
            $out[] = 'None.';
        } else {
            foreach (array_values(array_unique($warnings)) as $warning) {
                $out[] = '- ⚠ ' . $warning;
            }
        }
        $out[] = '';

        $out[] = '---';
        $out[] = 'Detail: settings.json (per-scope provenance), magento-tax.json, modules.json, environment.json,'
            . ' collector-diagnostics.json, probe.json' . (!empty($meta['order_increment_id']) ? ', order.json' : '')
            . ', logs/. manifest.json lists every file and anything that failed to collect.';

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array|null $env
     * @param array      $flags
     * @return string[]
     */
    private function environment(?array $env, array &$flags): array
    {
        $out = ['## Environment', ''];
        if ($env === null) {
            return array_merge($out, ['Not collected.', '']);
        }

        $magento = $env['magento'] ?? [];
        $php = $env['php'] ?? [];
        $infra = $env['infrastructure'] ?? [];
        $out[] = sprintf(
            'Magento %s %s · PHP %s (%s) · TaxCloud %s · %s mode · cache: %s · sessions: %s · DB %s',
            $magento['version'] ?? '?',
            $magento['edition'] ?? '',
            $php['version'] ?? '?',
            $php['sapi'] ?? '?',
            is_string($env['taxcloud_module_version'] ?? null) ? $env['taxcloud_module_version'] : '?',
            $magento['deploy_mode'] ?? '?',
            $this->shortClass((string) ($infra['cache_backend'] ?? '?')),
            (string) ($infra['session_storage'] ?? '?'),
            $env['database']['server_version'] ?? '?'
        );

        $missing = [];
        foreach ((array) ($php['extensions'] ?? []) as $name => $extension) {
            if (empty($extension['loaded'])) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            $flags['warnings'][] = 'PHP extensions missing: ' . implode(', ', $missing)
                . (in_array('soap', $missing, true) ? ' (V1 SOAP cannot work without soap)' : '');
        }
        $ini = $php['ini'] ?? [];
        $out[] = sprintf(
            'memory_limit %s · max_execution_time %s · default_socket_timeout %s · server TZ %s',
            $ini['memory_limit'] ?? '?',
            $ini['max_execution_time'] ?? '?',
            $ini['default_socket_timeout'] ?? '?',
            $env['timezones']['server'] ?? '?'
        );

        $cron = $env['cron'] ?? [];
        if (isset($cron['error'])) {
            $flags['warnings'][] = 'Cron state could not be read: ' . $cron['error'];
        } else {
            if (empty($cron['last_successful_run_at'])) {
                $flags['warnings'][] = 'Cron has no recorded successful run — cron may not be running.';
            } elseif (strtotime($cron['last_successful_run_at'] . ' UTC') < time() - 3600) {
                $flags['warnings'][] = 'Last successful cron run was ' . $cron['last_successful_run_at']
                    . ' UTC — cron may have stopped.';
            }
            if (!empty($cron['stuck_running_total'])) {
                $flags['warnings'][] = 'Cron jobs stuck in "running": ' . $this->pairs($cron['stuck_running'] ?? []);
            }
            if (!empty($cron['error_count_last_24h'])) {
                $jobs = [];
                foreach ((array) ($cron['errors_last_24h'] ?? []) as $row) {
                    $jobs[] = $row['job_code'] . ' ' . $row['status'] . '×' . $row['count'];
                }
                $flags['warnings'][] = 'Cron errors/missed runs in the last 24h: '
                    . implode(', ', array_slice($jobs, 0, 8));
            }
        }

        $upgrade = $env['database_upgrade'] ?? [];
        if (isset($upgrade['error'])) {
            $flags['warnings'][] = 'Could not check whether setup:upgrade is pending: ' . $upgrade['error'];
        } elseif (isset($upgrade['up_to_date']) && $upgrade['up_to_date'] === false) {
            $parts = [];
            foreach ((array) ($upgrade['module_versions'] ?? []) as $row) {
                $parts[] = sprintf('%s %s %s→%s', $row['module'], $row['type'], $row['database'], $row['code']);
            }
            if (!empty($upgrade['schema_patches_pending'])) {
                $parts[] = 'schema patches not applied';
            }
            if (!empty($upgrade['data_patches_pending'])) {
                $parts[] = 'data patches not applied';
            }
            $flags['blockers'][] = 'The database is not up to date with the installed code (' . implode('; ', $parts)
                . '). Run `bin/magento setup:upgrade`: until then extensions can fail to load or behave as their'
                . ' previous version.';
        }

        $invalid = $env['indexers']['invalid'] ?? [];
        if (!empty($invalid)) {
            $flags['blockers'][] = 'Invalid indexers: ' . implode(', ', $invalid)
                . ' — catalog data (including product TICs) may be stale until reindexed.';
        }

        $out[] = '';

        return $out;
    }

    /**
     * @param array|null $settings
     * @param array|null $magentoTax
     * @param array      $flags
     * @return string[]
     */
    private function settings(?array $settings, ?array $magentoTax, array &$flags): array
    {
        $out = ['## TaxCloud settings (effective per store)', ''];
        if ($settings === null) {
            return array_merge($out, ['Not collected.', '']);
        }

        $all = (array) ($settings['settings'] ?? []);
        $stores = [];
        foreach ((array) ($all['enabled']['effective'] ?? []) as $code => $unused) {
            $stores[] = (string) $code;
        }

        $enabledSomewhere = false;
        foreach ($stores as $code) {
            if (!empty($all['enabled']['effective'][$code]['value'])) {
                $enabledSomewhere = true;
            }
        }
        if ($stores !== [] && !$enabledSomewhere) {
            $flags['blockers'][] = 'TaxCloud is disabled for every store in this bundle.';
        }

        $rows = [];
        foreach (self::SUMMARY_SETTINGS as $key) {
            if (!isset($all[$key])) {
                continue;
            }
            $cells = [];
            foreach ($stores as $code) {
                $effective = $all[$key]['effective'][$code] ?? [];
                $cells[$code] = $this->settingCell($key, $effective, $code, $all, $flags);
            }
            $rows[$key] = $cells;
        }

        foreach ($stores as $code) {
            $this->credentialChecks($code, $all, $flags);
        }

        if (count($stores) <= self::MAX_STORE_COLUMNS) {
            $out[] = '| setting | ' . implode(' | ', $stores) . ' |';
            $out[] = '|---|' . str_repeat('---|', count($stores));
            foreach ($rows as $key => $cells) {
                $out[] = '| ' . $key . ' | ' . implode(' | ', $cells) . ' |';
            }
        } else {
            foreach ($stores as $code) {
                $parts = [];
                foreach ($rows as $key => $cells) {
                    $parts[] = $key . '=' . $cells[$code];
                }
                $out[] = '- **' . $code . '**: ' . implode(' · ', $parts);
            }
        }
        $out[] = '';
        $out[] = '🔒 = locked in app/etc/env.php, app/etc/config.php or an environment variable (cannot be changed'
            . ' in the admin) · ↑ = inherited from website/default.';

        foreach ((array) ($settings['locked'] ?? []) as $lock) {
            $flags['warnings'][] = sprintf(
                'Setting `%s` is locked at %s by %s — editing it in the admin has no effect.',
                $lock['setting'],
                $lock['scope'],
                $lock['locked_by']
            );
        }

        if ($magentoTax !== null) {
            $ruleCount = count((array) ($magentoTax['tax_rules'] ?? []));
            if ($ruleCount > 0) {
                $flags['warnings'][] = sprintf(
                    '%d native Magento tax rule(s) and %d rate(s) are defined. TaxCloud replaces native calculation'
                    . ' on enabled stores, but these rules still apply on stores with TaxCloud disabled and whenever'
                    . ' the Magento fallback runs.',
                    $ruleCount,
                    (int) ($magentoTax['tax_rates']['count'] ?? 0)
                );
            }
            if (array_key_exists('taxcloud_cache_type_enabled', $magentoTax)
                && $magentoTax['taxcloud_cache_type_enabled'] === false
            ) {
                $flags['warnings'][] = 'The `taxcloud` cache type is disabled: every cart change calls TaxCloud live.';
            }
        }

        $out[] = '';

        return $out;
    }

    /**
     * @param string $key
     * @param array  $effective
     * @param string $store
     * @param array  $all
     * @param array  $flags
     * @return string
     */
    private function settingCell(string $key, array $effective, string $store, array $all, array &$flags): string
    {
        if (isset($effective['fingerprint'])) {
            $cell = $this->describeFingerprint($effective['fingerprint'])
                . (isset($effective['withheld']) ? ' (withheld: ' . $effective['withheld'] . ')' : '');
        } else {
            $value = $effective['value'] ?? null;
            $cell = $value === null || $value === '' ? '(empty)' : '`' . $this->displayValue($key, $value) . '`';
            if ($key === 'default_tic' && (string) $value === TaxcloudConfig::DEFAULT_TIC
                && !empty($all['enabled']['effective'][$store]['value'])
            ) {
                $flags['warnings'][] = 'Default TIC is 00000 (general tangible goods) on `' . $store . '`: any'
                    . ' product without its own or a category TIC is taxed as a physical good. Wrong for stores'
                    . ' selling services or digital goods.';
            }
            if ($key === 'logging' && (string) $value === (string) TaxcloudConfig::LOGGING_DISABLED
                && !empty($all['enabled']['effective'][$store]['value'])
            ) {
                $flags['blockers'][] = 'Logging is set to Disable on `' . $store . '`: nothing TaxCloud does there'
                    . ' is logged, so this bundle cannot show what happened.';
            }
        }

        $markers = '';
        if (!empty($effective['locked'])) {
            $markers .= ' 🔒';
        }
        $resolvedFrom = (string) ($effective['resolved_from'] ?? '');
        if ($resolvedFrom !== '' && strpos($resolvedFrom, 'stores/') !== 0) {
            $markers .= ' ↑';
        }

        return $cell . $markers;
    }

    /**
     * @param string $store
     * @param array  $all
     * @param array  $flags
     * @return void
     */
    private function credentialChecks(string $store, array $all, array &$flags): void
    {
        if (empty($all['enabled']['effective'][$store]['value'])) {
            return;
        }

        $apiType = (string) ($all['api_type']['effective'][$store]['value'] ?? 'rest');
        $fp = static function ($key) use ($all, $store) {
            return $all[$key]['effective'][$store]['fingerprint'] ?? ['set' => false];
        };

        if ($apiType === 'soap') {
            foreach (['api_id', 'api_key'] as $key) {
                if (empty($fp($key)['set'])) {
                    $flags['blockers'][] = sprintf(
                        '`%s` is empty on `%s` (V1 SOAP) — every call will fail.',
                        $key,
                        $store
                    );
                }
            }
        } else {
            $connection = $all['rest_connection_id']['effective'][$store] ?? [];
            if (empty($connection['value']) && empty($connection['fingerprint']['set'])) {
                $flags['blockers'][] = 'Connection ID is empty on `' . $store . '` (V3 REST) — every call will fail.';
            }
            if (empty($fp('rest_api_key')['set']) && (empty($fp('api_id')['set']) || empty($fp('api_key')['set']))) {
                $flags['blockers'][] = 'No V3 API Key and no V1 API ID/Key pair to exchange on `' . $store
                    . '` (V3 REST) — authentication is impossible.';
            }
        }

        foreach (['api_id', 'api_key', 'rest_api_key'] as $key) {
            $anomalies = $this->fingerprint->anomalies($fp($key));
            if ($anomalies !== []) {
                $flags['blockers'][] = sprintf(
                    '`%s` on `%s` contains %s — almost always a copy-paste error that makes TaxCloud reject it.',
                    $key,
                    $store,
                    implode(', ', $anomalies)
                );
            }
        }
    }

    /**
     * @param array|null $collector
     * @param array      $flags
     * @return string[]
     */
    private function collector(?array $collector, array &$flags): array
    {
        $out = ['## Tax collector', ''];
        if ($collector === null) {
            return array_merge($out, ['Not collected.', '']);
        }

        foreach ((array) ($collector['stores'] ?? []) as $code => $store) {
            if (!empty($store['failure_reason'])) {
                $line = 'could not be checked: ' . $store['failure_reason'];
                $flags['blockers'][] = 'Collector check failed on `' . $code . '`: ' . $store['failure_reason'];
            } elseif (!empty($store['healthy'])) {
                $line = 'OK — TaxCloud collector runs (' . $store['active_collector_class'] . ')';
            } else {
                $line = 'TaxCloud will NOT calculate tax — active collector: '
                    . ($store['active_collector_class'] ?? 'none')
                    . (!empty($store['interceptors'])
                        ? '; intercepted by ' . implode(', ', $store['interceptors'])
                        : '');
                if (!empty($store['taxcloud_enabled'])) {
                    $flags['blockers'][] = 'TaxCloud is not the active tax collector on `' . $code . '`: '
                        . ($store['owned_by_taxcloud'] ? 'intercepted by ' . implode(', ', $store['interceptors'])
                            : 'displaced by ' . ($store['active_collector_class'] ?? 'nothing'))
                        . '. See https://fedtax.github.io/Magento2/extension-conflicts/';
                }
            }
            if (empty($store['taxcloud_enabled'])) {
                $line .= ' (TaxCloud disabled on this store)';
            }
            if (!empty($store['later_collectors'])) {
                $line .= ' · runs after tax (may overwrite): ' . implode(', ', $store['later_collectors']);
            }
            $out[] = '- `' . $code . '`: ' . $line;
        }
        $out[] = '';

        return $out;
    }

    /**
     * @param array|null $probe
     * @param array      $flags
     * @return string[]
     */
    private function probe(?array $probe, array &$flags): array
    {
        $out = ['## Live API probe', ''];
        if ($probe === null) {
            return array_merge($out, ['Not collected.', '']);
        }
        if (empty($probe['ran'])) {
            return array_merge($out, [(string) ($probe['reason'] ?? 'Not run.'), '']);
        }
        if (empty($probe['configurations'])) {
            return array_merge($out, ['No store in scope has TaxCloud enabled; nothing was probed.', '']);
        }

        foreach ((array) $probe['configurations'] as $config) {
            $stores = implode(', ', (array) ($config['stores'] ?? []));
            if (isset($config['error'])) {
                $out[] = '- `' . $stores . '`: ' . $config['error'];
                $flags['blockers'][] = 'Probe failed for `' . $stores . '`: ' . $config['error'];
                continue;
            }

            $network = $config['network'] ?? [];
            $dns = !empty($network['dns']['ok']) ? 'ok ' . ($network['dns']['duration_ms'] ?? '?') . 'ms'
                : 'FAILED (' . ($network['dns']['error'] ?? '?') . ')';
            $tls = !empty($network['tls']['ok'])
                ? 'ok ' . ($network['tls']['duration_ms'] ?? '?') . 'ms' . (isset($network['tls']['protocol'])
                    ? ' ' . $network['tls']['protocol'] : '')
                : 'FAILED (' . ($network['tls']['error'] ?? ($network['tls']['skipped'] ?? '?')) . ')';

            $auth = $config['authentication'] ?? [];
            $authText = ($auth['method'] ?? '?') . (!empty($auth['ok']) ? ' ok' : ' FAILED')
                . (isset($auth['token_source']) ? ' (token from ' . $auth['token_source'] . ')' : '')
                . (isset($auth['error']) ? ': ' . $auth['error'] : '');

            $out[] = sprintf(
                '- `%s` — %s %s%s · DNS %s · TLS %s · auth %s',
                $stores,
                strtoupper((string) ($config['api_type'] ?? '?')),
                $config['endpoint'] ?? '',
                isset($config['connection_id']) ? ' (connection ' . $config['connection_id'] . ')' : '',
                $dns,
                $tls,
                $authText
            );

            foreach ((array) ($config['calls'] ?? []) as $name => $call) {
                if (isset($call['skipped'])) {
                    $text = 'skipped — ' . $call['skipped'];
                } elseif (!empty($call['success'])) {
                    $text = 'OK (HTTP ' . ($call['http_status'] ?? '?') . ', ' . ($call['duration_ms'] ?? '?') . 'ms)';
                } else {
                    $text = 'FAILED (HTTP ' . ($call['http_status'] ?? '—') . ', '
                        . ($call['duration_ms'] ?? '?') . 'ms)'
                        . (isset($call['error_code']) ? ' code ' . $call['error_code'] : '')
                        . ': ' . ($call['error_message'] ?? '?');
                }
                $out[] = '  - ' . $name . ': ' . $text;
                if (empty($call['success'])) {
                    $flags['blockers'][] = sprintf('Probe %s failed for `%s`: %s', $name, $stores, $text);
                }
            }
        }
        $out[] = '';

        return $out;
    }

    /**
     * @param array|null $order
     * @param array      $flags
     * @return string[]
     */
    private function order(?array $order, array &$flags): array
    {
        $out = ['## Order', ''];
        if ($order === null) {
            return array_merge($out, ['Not collected.', '']);
        }

        $totals = $order['totals'] ?? [];
        $currency = $order['currency']['order'] ?? '';
        $out[] = sprintf(
            '#%s (entity %s, quote %s) · store `%s` · created %s UTC · %s/%s · %s',
            $order['increment_id'] ?? '?',
            $order['entity_id'] ?? '?',
            $order['quote_id'] ?? '?',
            $order['store']['code'] ?? '?',
            $order['created_at'] ?? '?',
            $order['state'] ?? '?',
            $order['status'] ?? '?',
            !empty($order['customer']['is_guest']) ? 'guest' : 'customer #' . ($order['customer']['customer_id'] ?? '?')
        );
        $out[] = sprintf(
            'Subtotal %s · discount %s · shipping %s (tax %s) · **tax %s** · grand total %s %s'
            . ' · invoiced %s · refunded %s',
            $totals['subtotal'] ?? '?',
            $totals['discount'] ?? '?',
            $totals['shipping'] ?? '?',
            $totals['shipping_tax'] ?? '?',
            $totals['tax'] ?? '?',
            $totals['grand_total'] ?? '?',
            $currency,
            $totals['total_invoiced'] ?? '?',
            $totals['total_refunded'] ?? '?'
        );

        $shipping = $order['shipping_address'] ?? null;
        $billing = $order['billing_address'] ?? null;
        $out[] = 'Ship to: ' . $this->addressLine($shipping) . ' · Bill to: ' . $this->addressLine($billing)
            . ' · method `' . ($order['shipping_method'] ?? 'none') . '`';

        $tc = $order['taxcloud'] ?? [];
        $out[] = sprintf(
            'taxcloud_captured=%s · certificate=%s · RDF %s (applied: %s; method in motor-vehicle list: %s)',
            $this->scalar($tc['taxcloud_captured'] ?? null),
            $this->scalar($tc['taxcloud_certificate_id'] ?? null),
            $this->scalar($tc['taxcloud_rdf_amount'] ?? null),
            !empty($tc['colorado_rdf']['applied']) ? 'yes' : 'no',
            !empty($tc['colorado_rdf']['shipping_method_in_motor_vehicle_list']) ? 'yes' : 'no'
        );
        $source = $tc['tax_source'] ?? [];
        $out[] = 'Tax source: **' . ($source['determination'] ?? 'unknown') . '** — ' . ($source['reason'] ?? '');
        if (($source['determination'] ?? '') === 'magento_fallback') {
            $flags['blockers'][] = 'This order\'s tax came from the Magento fallback, not TaxCloud — the TaxCloud'
                . ' lookup failed at checkout (see logs/taxcloud.log).';
        }
        $out[] = '';

        $out[] = '| sku | type | qty | price | row total | discount | tax | TIC | TIC source |';
        $out[] = '|---|---|---|---|---|---|---|---|---|';
        foreach ((array) ($order['items']['lines'] ?? []) as $line) {
            $out[] = sprintf(
                '| %s%s | %s | %s | %s | %s | %s | %s | %s | %s |',
                $line['parent_item_id'] !== null ? '↳ ' : '',
                $this->cell((string) $line['sku']),
                $line['product_type'],
                $line['qty_ordered'],
                $line['price'],
                $line['row_total'],
                $line['discount_amount'],
                $line['tax_amount'],
                $line['tic'] ?? '?',
                $line['tic_source']
            );
        }
        $out[] = '';
        $out[] = '_TICs are resolved from the catalog as it is now; a TIC changed since checkout will differ._';

        $documents = [];
        foreach (['invoices' => 'invoice', 'credit_memos' => 'credit memo'] as $key => $label) {
            foreach ((array) ($order[$key] ?? []) as $document) {
                $documents[] = sprintf(
                    '%s #%s %s (tax %s, total %s, RDF %s)',
                    $label,
                    $document['increment_id'],
                    $document['created_at'],
                    $document['tax'],
                    $document['grand_total'],
                    $this->scalar($document['taxcloud_rdf_amount'] ?? null)
                );
            }
        }
        foreach ((array) ($order['shipments'] ?? []) as $shipment) {
            $documents[] = 'shipment #' . $shipment['increment_id'] . ' ' . $shipment['created_at'];
        }
        $out[] = 'Documents: ' . ($documents === [] ? 'none' : implode(' · ', $documents));
        $out[] = '';

        return $out;
    }

    /**
     * @param array|null $logs
     * @param bool       $forOrder
     * @param int        $now      Generation time, for ages
     * @param array      $flags
     * @return string[]
     */
    private function logs(?array $logs, bool $forOrder, int $now, array &$flags): array
    {
        $out = ['## Logs', ''];
        if ($logs === null) {
            return array_merge($out, ['Not collected.', '']);
        }

        $log = $logs['taxcloud_log'] ?? [];
        $status = $log['status'] ?? LogsSection::STATUS_MISSING;
        $out[] = 'TaxCloud log: `' . ($log['path'] ?? '?') . '` — ' . $status
            . (isset($log['size'])
                ? ', ' . $this->bytes((int) $log['size']) . ', last written ' . ($log['modified_at'] ?? '?')
                : '');
        $out[] = 'Logging mode: ' . $this->pairs((array) ($logs['logging_modes'] ?? []));
        $window = $logs['window'] ?? [];
        $out[] = sprintf(
            'Window: %s (last %s or %d days, whichever is smaller)',
            $window['name'] ?? '?',
            $this->bytes((int) ($window['max_bytes'] ?? 0)),
            (int) ($window['max_days'] ?? 0)
        );

        if ($status === LogsSection::STATUS_MISSING) {
            $flags['blockers'][] = 'The TaxCloud log file does not exist at ' . ($log['path'] ?? '?')
                . ' — nothing has been logged there.';
        } elseif ($status === LogsSection::STATUS_EMPTY) {
            $flags['blockers'][] = 'The TaxCloud log file is empty.';
        } elseif ($status === LogsSection::STATUS_UNREADABLE) {
            $flags['blockers'][] = 'The TaxCloud log file exists but could not be read (file permissions).';
        }

        $loggingOn = array_diff((array) ($logs['logging_modes'] ?? []), ['disabled']) !== [];
        $lastWritten = isset($log['mtime_unix']) ? (int) $log['mtime_unix'] : null;
        if ($status === LogsSection::STATUS_OK && $loggingOn && $lastWritten !== null
            && $now - $lastWritten > self::STALE_AFTER_SECONDS
        ) {
            $flags['warnings'][] = sprintf(
                'Nothing has been written to the TaxCloud log for %s (last write %s) although logging is on. If'
                . ' the store has had checkouts since, TaxCloud is not running for them.',
                $this->age($now - $lastWritten),
                gmdate('Y-m-d H:i', $lastWritten) . ' UTC'
            );
        }

        foreach ((array) ($logs['files'] ?? []) as $entry => $file) {
            $out[] = sprintf(
                '- %s: %s',
                $entry,
                !empty($file['included'])
                    ? $this->bytes((int) ($file['bytes'] ?? 0)) . ', '
                        . ($file['records'] ?? $file['matching_records'] ?? '?')
                        . ' records' . (!empty($file['truncated_by_size']) ? ' (truncated by size cap)' : '')
                        . (isset($file['first_record_at'])
                            ? ', ' . $file['first_record_at'] . ' → ' . $file['last_record_at']
                            : '')
                    : 'not included — ' . ($file['reason'] ?? (($file['status'] ?? '') === LogsSection::STATUS_OK
                        ? 'no matching records in the log window'
                        : ($file['status'] ?? 'no records')))
            );
        }

        $out = array_merge($out, $this->activity($logs['digest']['activity'] ?? [], $forOrder, $now));

        if ($forOrder) {
            $correlation = $logs['order_correlation'] ?? [];
            $count = (int) ($correlation['correlated_records'] ?? 0);
            if ($count === 0) {
                $flags['blockers'][] = 'No TaxCloud log lines were found for this order — logging was off when it was'
                    . ' placed, or the log has since rotated away. logs/taxcloud.log is omitted.';
            } else {
                $out[] = sprintf(
                    'Order correlation: %d matching record(s), ±%d records of context, correlation id(s): %s',
                    $count,
                    (int) ($correlation['context_lines'] ?? 0),
                    implode(', ', (array) ($correlation['correlation_ids'] ?? []))
                        ?: 'none (lines predate correlation ids)'
                );
            }
        } elseif (!empty($log['status']) && $log['status'] === LogsSection::STATUS_OK
            && empty($logs['files']['logs/taxcloud.log']['included'])
        ) {
            $flags['blockers'][] = 'The TaxCloud log has no records inside the log window — widen the window or'
                . ' reproduce the problem and generate the bundle again.';
        }
        $out[] = '';

        return array_merge($out, $this->problems($logs['digest'] ?? [], $now, $flags));
    }

    /**
     * When the extension last did the things that matter, from the exported records.
     *
     * @param array $activity
     * @param bool  $forOrder
     * @param int   $now
     * @return string[]
     */
    private function activity(array $activity, bool $forOrder, int $now): array
    {
        if ($activity === [] || empty($activity['last_record_at'])) {
            return [];
        }

        $labels = [
            'last_successful_lookup_at' => 'successful lookup',
            'last_capture_success_at' => 'capture',
            'last_capture_failure_at' => 'FAILED capture',
            'last_refund_success_at' => 'refund',
            'last_refund_failure_at' => 'FAILED refund',
            'last_fallback_at' => 'Magento fallback used',
        ];
        $parts = [];
        foreach ($labels as $key => $label) {
            $parts[] = $label . ' ' . $this->when($activity[$key] ?? null, $now);
        }

        return [
            ($forOrder ? 'This order\'s activity' : 'Last TaxCloud activity in the exported log') . ': last record '
                . $this->when($activity['last_record_at'], $now) . ' · ' . implode(' · ', $parts),
        ];
    }

    /**
     * Distinct warnings and errors per log file, newest first.
     *
     * @param array $digest
     * @param int   $now
     * @param array $flags
     * @return string[]
     */
    private function problems(array $digest, int $now, array &$flags): array
    {
        $out = ['## Log problems', ''];
        $problems = (array) ($digest['problems'] ?? []);
        if ($problems === []) {
            return array_merge($out, ['No warnings or errors in the exported log records.', '']);
        }

        $out[] = 'Distinct warnings and errors (numbers and ids normalised), newest first. Ages are relative to when'
            . ' the bundle was generated.';
        foreach ($problems as $source => $entries) {
            $out[] = '';
            $out[] = '`' . $source . '`:';
            $recentErrors = 0;
            foreach (array_slice($entries, 0, self::MAX_PROBLEMS_PER_LOG) as $entry) {
                $lastSeen = strtotime((string) $entry['last_seen']) ?: null;
                $recent = $lastSeen !== null && $now - $lastSeen <= self::STALE_AFTER_SECONDS;
                if ($recent && $entry['level'] !== 'WARNING') {
                    $recentErrors++;
                }
                $out[] = sprintf(
                    '- %s ×%d, last %s%s: %s',
                    $entry['level'],
                    $entry['count'],
                    $this->when($entry['last_seen'], $now),
                    $entry['count'] > 1 && $entry['first_seen'] !== $entry['last_seen']
                        ? ', first ' . $this->when($entry['first_seen'], $now)
                        : '',
                    $this->cell((string) $entry['message'])
                );
            }
            $hidden = count($entries) - self::MAX_PROBLEMS_PER_LOG + (int) ($digest['overflow'][$source] ?? 0);
            if ($hidden > 0) {
                $out[] = '- … and ' . $hidden . ' more distinct message(s); see the file.';
            }
            if ($recentErrors > 0) {
                $flags['warnings'][] = sprintf(
                    '%d distinct error(s) in `%s` within the 24 hours before export — see Log problems.',
                    $recentErrors,
                    $source
                );
            }
        }
        $out[] = '';

        return $out;
    }

    /**
     * "2026-09-15 11:19 UTC (2h ago)", or "none recorded" when absent.
     *
     * @param string|null $iso
     * @param int         $now
     * @return string
     */
    private function when(?string $iso, int $now): string
    {
        $ts = $iso !== null ? strtotime($iso) : false;
        if ($ts === false) {
            // Not "never": the export only covers the log window, and logs
            // written by older versions lack some of these markers.
            return 'none recorded';
        }

        return gmdate('Y-m-d H:i', $ts) . ' UTC (' . $this->age(max(0, $now - $ts)) . ' ago)';
    }

    /**
     * @param int $seconds
     * @return string
     */
    private function age(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, intdiv($seconds, 60)) . 'm';
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . 'h';
        }

        return intdiv($seconds, 86400) . ' days';
    }

    /**
     * @param array $fp
     * @return string
     */
    private function describeFingerprint(array $fp): string
    {
        if (empty($fp['set'])) {
            return '(empty)';
        }

        return sprintf(
            '[redacted: %d chars%s, sha256 %s%s]',
            (int) $fp['length'],
            isset($fp['last4']) ? ', …' . $fp['last4'] : '',
            $fp['sha256_prefix'],
            $this->fingerprint->anomalies($fp) !== [] ? ', ⚠ ' . implode('/', $this->fingerprint->anomalies($fp)) : ''
        );
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return string
     */
    private function displayValue(string $key, $value): string
    {
        if ($key === 'logging') {
            $labels = [
                (string) TaxcloudConfig::LOGGING_DISABLED => 'disabled',
                (string) TaxcloudConfig::LOGGING_BASIC => 'basic',
                (string) TaxcloudConfig::LOGGING_ADVANCED => 'advanced',
            ];
            return $labels[(string) $value] ?? (string) $value;
        }

        return $this->cell((string) $value);
    }

    /**
     * @param array|null $address
     * @return string
     */
    private function addressLine(?array $address): string
    {
        if ($address === null) {
            return 'none';
        }
        $street = $address['street'] ?? '';
        if (is_array($street)) {
            $street = implode(', ', $street);
        }

        return trim(sprintf(
            '%s, %s, %s %s %s',
            $street,
            $address['city'] ?? '',
            $address['region_code'] ?? ($address['region'] ?? ''),
            $address['postcode'] ?? '',
            $address['country_id'] ?? ''
        ));
    }

    /**
     * @param array $pairs
     * @return string
     */
    private function pairs(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = $key . ' ' . $value;
        }

        return $parts === [] ? 'none' : implode(', ', $parts);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function scalar($value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /**
     * @param string $value
     * @return string
     */
    private function cell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
    }

    /**
     * @param string $class
     * @return string
     */
    private function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * @param int $bytes
     * @return string
     */
    private function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
