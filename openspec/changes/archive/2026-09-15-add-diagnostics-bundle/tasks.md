## 1. Log correlation (Story 3)

- [x] 1.1 `GatewayLogger::beginOperation()/continueOperation()/getCorrelationContext()/clearCorrelation()`; merge context into records
- [x] 1.2 Bind at gateway entry points (both transports) and in the capture, refund, address and cancellation paths
- [x] 1.3 Unit tests: binding, replacement, same-entity reuse, caller precedence, formatted line

## 2. Redaction and provenance (Story 2)

- [x] 2.1 `LogRedactor::redactText()` with known-secret replacement
- [x] 2.2 `CredentialFingerprint`, `PiiRedactor`, `CredentialInventory`
- [x] 2.3 `ConfigSourceReader` with the env.php whitelist; `SettingsSection` provenance
- [x] 2.4 ACL resource `Taxcloud_Magento2::diagnostics`; `DiagnosticsAudit` + `etc/logging.xml`

## 3. Bundle core (Stories 1, 5, 6)

- [x] 3.1 `BundleRequest`, `ScopeResolver`, `BundleContext`, `BundleArchive`, `BundleWorkspace`, `BundleGenerator`
- [x] 3.2 Sections: settings, magento tax, modules, environment, collector, probe, logs, order
- [x] 3.3 `LogFileReader` (tail, time binary search, gzip, truncation) and `LogPathResolver`
- [x] 3.4 `ApiProbe` + `EndpointCheck`; `SoapGateway::createClient()`
- [x] 3.5 `SummaryRenderer`

## 4. Entry points (Stories 1, 4, 7)

- [x] 4.1 Config button + dialog JS; order view button plugin; `Export` controller
- [x] 4.2 `ProductTicService::resolveTic()`; `OrderSection`
- [x] 4.3 `taxcloud:diagnostics:export`

## 5. Tests

- [x] 5.1 Unit tests for every new class; no-credential-in-bundle test (mutation-checked); partial failure; DI log path; PHPUnit 9.5/10.5/12.5-safe APIs only
- [x] 5.2 Integration tests: end-to-end bundle, per-order narrowing, order store scope, correlation binding at checkout, ACL
- [x] 5.2a Integration tests: controller response streamed and deleted, CLI masked per-order bundle, CLI failure writes nothing, no scratch files left
- [x] 5.2b E2E: config-page download, masked order-view download, restricted admin (no buttons, route refused), POST without form key refused; seeded `tax-no-diagnostics` admin
- [x] 5.3 Full unit suite, phpcs, PHPStan level 5 (no baseline growth)

## 6. Documentation (Story 8)

- [x] 6.1 `docs/diagnostics.md` + nav; `settings.md`, `logs.md`, `common-problems.md`
- [x] 6.2 README (CLI, bundle layout, correlation format), CHANGELOG

## 7. Findings from reviewing real bundles

- [x] 7.1 Trim trailing line breaks in `GatewayLogger` so the correlation context stays on the record's line (raw REST response bodies end in a newline)
- [x] 7.2 Audit records a per-order export by order increment id, not entity id (`BundleResult::getOrderIncrementId()`)
- [x] 7.3 Refund log line names the order (the credit memo is not numbered when the observer runs), both transports
- [x] 7.4 Capture and refund outcome lines at INFO/WARNING in the observers
- [x] 7.5 `LogDigest`: distinct warnings/errors per log file with count, first/last seen; TaxCloud activity milestones; rendered as "Log problems" and "Last TaxCloud activity" in summary.md
- [x] 7.6 Warn when the TaxCloud log has been silent for over 24h while logging is on
- [x] 7.7 `DatabaseUpgradeCheck` (module versions, schema and data patches) in environment.json; blocker when `setup:upgrade` is pending
- [x] 7.8 Unit tests for all of the above; docs (`logs.md`, `diagnostics.md`) and CHANGELOG
- [x] 7.9 SOAP operations labelled `(v1 SOAP)`; SOAP params/responses logged as single-line JSON, wire traces collapsed onto one line; REST `PAYLOAD` label and body in one record
- [x] 7.10 `Logger\Processor\RequestIdentityProcessor`: `request` id and `pid` in every record's `extra`, degrading when `getmypid()`/`random_bytes()` are unavailable; digest strips the extra group
