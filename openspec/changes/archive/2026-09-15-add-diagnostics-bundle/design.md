## Context

See proposal.md. The bundle must be safe to email (no credential, ever), useful cold (a reader with no store access), and cheap on the stores that need it most (multi-gigabyte Advanced-mode logs, blocked outbound networks, broken third-party modules).

## Decisions

### D1: Sections behind one generator, isolated from each other
`BundleGenerator` runs an ordered list of `Section\SectionInterface` implementations wired in `etc/di.xml`. Each returns data (for the summary) and adds its own file(s). Any `Throwable` is recorded (`section`, scrubbed `message`, `exception`) in `manifest.json` and as a summary blocker; the other sections still run. The admin controller and CLI both call `generate(BundleRequest)`, so the surfaces cannot diverge.

### D2: Redaction at the archive boundary, two layers
All text reaches the ZIP through `BundleArchive::addString()/addJson()` or a staged file written record-by-record, and every path calls `BundleContext::scrub()`: (1) `LogRedactor::redactText()` — credential shapes any version could have logged (XML, JSON, print_r, `X-API-KEY`, `Bearer`, query strings); (2) exact replacement of every credential value from `CredentialInventory` — database rows at all scopes, deployment-locked values, effective per-store values, their decrypted plaintexts and cached Bearer tokens, including JSON-escaped variants. Collected across all scopes, not just the exported one. A setting that is not a credential but equals one (the V1→V3 Connection ID equals the V1 API Key) is fingerprinted with an explanation instead of being printed.

### D3: Provenance from sources, not from resolved config
`ConfigSourceReader` reads `core_config_data`, the `system` subtree of `app/etc/env.php` and `app/etc/config.php`, and environment-variable placeholders (via Magento's `SettingChecker`). Other deployment keys are reachable only through a hardcoded whitelist (backend names only). Per setting: each scope's explicit/inherited state, source and lock, plus each store's effective value and the scope it resolved from.

### D4: Logs are sought, not read
`LogFileReader` positions by byte offset — the tail (`size − budget`) or the first record at a time, found by binary search over Monolog timestamps — groups continuation lines into records, truncates records over 1 MB, and streams to a sink. Rotations are discovered next to the DI-resolved log path (read from the `Handler` the object manager built) and skipped when last written before the window; `.gz` rotations are decompressed forward. Per-order bundles scan from three days before the order, bounded to 20× the window, keeping records matching `"order_increment_id"`, `"quote_id"` or the increment id in prose, with N records of context.

### D5: ZIP on disk, streamed and deleted
The archive and staged files live under `var/tmp/taxcloud-diagnostics/`; the work directory is removed in `finally`, the ZIP on failure. The controller hands the ZIP to `FileFactory` with `rm => true` (chunked read, deleted after send); the CLI renames it to the output path.

### D6: Probe reuses transports, fails fast
`ApiProbe` groups stores by resolved API configuration, measures DNS and TLS first (`EndpointCheck`) and skips API calls behind a network failure, records REST auth mode (API key vs V1 token exchange and whether a token was acquired), then makes a canned Lookup (fixed cart id, TaxCloud's business address) and VerifyAddress with the store's timeout and no retries. SOAP uses `SoapGateway::createClient()` with trace on to read the HTTP status.

### D7: Correlation rides the existing singleton binding
`GatewayLogger` already carries per-operation store state. `beginOperation()` replaces the context (new id) unless the bound context names the same order — or, with no order, the same quote — so an observer and the gateway call it makes share one id. `continueOperation()` keeps a bound context for nested calls (address verification inside a lookup). The context is merged into the record's context array with caller keys winning; Monolog's `LineFormatter` renders it as trailing JSON.

### D8: Audit on every edition
`DiagnosticsAudit` writes to `system.log` always. `etc/logging.xml` registers the export with Adobe Commerce's Admin Actions Log, with `ActionLogHandler` attaching scope, order, masking and status; Open Source ignores the file.

## Risks / Trade-offs

- A per-order scan of a very old order on a huge log is bounded, not complete — the summary says when the scan budget was exhausted or no lines were found.
- The probe's REST Lookup upserts a cart under a fixed diagnostics id; nothing is captured.
- `last4` is withheld for values under eight characters.
