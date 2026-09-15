## Why

Most merchants will not grant SSH or admin access when they report a bug, so diagnosing a store today is a series of questions ("which version?", "paste your settings", "send the log"), each a round trip measured in days and each answer partial. `bin/magento taxcloud:diagnose` proves the value of a self-service check but needs the SSH access we usually do not have. A merchant with only admin access should be able to produce, in one click, a single file that lets an engineer — or an AI assistant — diagnose the issue without touching the store.

## What Changes

- **Diagnostics bundle** (Stories 1, 2, 5, 6): a HTTP-independent collector service under `Model/Diagnostics/Bundle/` producing `taxcloud-diagnostics-{scope}-{YYYYMMDD-HHMMSS}.zip` with `summary.md`, `manifest.json`, `settings.json` (per-scope provenance and locks), `magento-tax.json`, `modules.json`, `environment.json`, `collector-diagnostics.json`, `probe.json` and `logs/`.
- **Admin entry points** (Stories 1, 4): **Download Diagnostics** in the TaxCloud settings group (scope = the config scope being edited) and **TaxCloud Diagnostics** on the order view (per-order bundle with `order.json`). A dialog sets masking, log window and probe before generating; generation is a form-key-guarded POST behind a new `Taxcloud_Magento2::diagnostics` ACL resource.
- **CLI** (Story 7): `bin/magento taxcloud:diagnostics:export [--order] [--redact] [--output]` (plus `--store`, `--website`, `--no-probe`, `--log-window`).
- **Credential safety** (Story 2): credentials are never written, under any option; each is fingerprinted. All output is scrubbed by pattern (`LogRedactor::redactText()`, extending the existing redactor) and by every known credential value. `app/etc/env.php` is read only through a whitelist.
- **PII controls** (Story 2): optional masking of names, street lines, emails and phones; city, state, ZIP and TIC always kept. Mode recorded in manifest and summary; exports audited.
- **Log correlation** (Story 3): `GatewayLogger::beginOperation()` binds `correlation_id`, `operation`, `quote_id`, `order_increment_id` at each operation entry point; rendered as JSON context at the end of each line.
- `ProductTicService::resolveTic()` exposes the TIC together with its source (product, category, default) — `getProductTic()` now delegates to it.
- `SoapGateway::createClient()` builds an uncached client with option overrides (the probe needs trace on); `getClient()` uses it.
- Docs: new *Sending diagnostics to support* page; `settings.md`, `logs.md`, `common-problems.md`, README and CHANGELOG updated.
- **Not a breaking change.** No setting changes; existing log lines gain a trailing JSON context only while an operation is bound.

## Capabilities

### New Capabilities
- `diagnostics-bundle`: generating, scoping, redacting and delivering the diagnostics ZIP from the admin and CLI.
- `log-correlation`: per-operation correlation context on TaxCloud log records.

### Modified Capabilities
None. `tax-collector-diagnostics` is consumed unchanged; `connection-test` is untouched.

## Non-goals

- Uploading the bundle to TaxCloud. The merchant downloads it and attaches it themselves.
- Any remote-access or support-login mechanism.
- Redacting a bundle after generation — masking is chosen at generation time.
- Surfacing the correlation id in the customer-facing error path. Deferred pending a team decision (Story 3 note).
- Recording the TIC actually sent at checkout. `order.json` resolves TICs from the catalog as it is now and says so; Advanced-mode log payloads show the TIC sent.

## Impact

- New: `Model/Diagnostics/Bundle/**`, `Controller/Adminhtml/Diagnostics/Export.php`, `Block/Adminhtml/System/Config/DownloadDiagnostics.php`, `Plugin/Adminhtml/OrderViewDiagnosticsButton.php`, `Console/Command/DiagnosticsExportCommand.php`, `view/adminhtml/templates/system/config/download_diagnostics.phtml`, `view/adminhtml/web/js/diagnostics/export.js`, `etc/logging.xml`.
- Changed: `Model/Logging/GatewayLogger.php`, `Model/Logging/LogRedactor.php`, `Model/Api.php`, `Model/Gateway/Rest/RestGateway.php`, `Model/Gateway/Soap/SoapGateway.php`, `Model/ProductTicService.php`, `Model/Order/CancellationProcessor.php`, `Observer/Sales/{Complete,Refund,Address}.php`, `etc/{acl,di}.xml`, `etc/adminhtml/{di,system}.xml`, `phpcs.xml.dist`.
- No database schema change, no new dependency.

### Store-scoping implications

Every store-scoped value in a bundle resolves against the stores the bundle covers — the config scope being edited, or the order's store — never the ambient store: settings are read per store and per scope from source, the probe groups stores by their own resolved configuration, logging modes are read per store, and `order.json` resolves TICs, RDF settings and methods against the order's store. The correlation context is bound next to the existing `setStore()` calls and does not change which store logging resolves against.
