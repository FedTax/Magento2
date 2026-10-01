<img src="docs/images/tax-cloud-logo.png" align="right" />

# TaxCloud for Magento 2
> Sales Tax at the Speed of Commerce

The TaxCloud sales tax extension for Magento Open Source and Adobe Commerce 2.4.x.

## Documentation

**📖 [fedtax.github.io/Magento2](https://fedtax.github.io/Magento2/)**: installing,
configuring, and how every feature behaves. Start there if you run a store.

- [Installing the extension](https://fedtax.github.io/Magento2/installing/): supported versions, Composer, upgrading, uninstalling
- [Settings reference](https://fedtax.github.io/Magento2/settings/): every admin setting and its configuration path
- [Common problems](https://fedtax.github.io/Magento2/common-problems/) and [sending diagnostics to support](https://fedtax.github.io/Magento2/diagnostics/)
- [Extending the extension](https://fedtax.github.io/Magento2/extending/): events, log location, settings with no admin field

The rest of this README is for developers working on the extension itself.

## Development

### Contributing

1. Fork the repository and create a feature branch
2. Make your changes and test them in a Magento installation
3. Submit a pull request

**Note:** Tests run automatically on pull requests via GitHub Actions.

### Running Tests

Unit tests run with the PHPUnit and Magento classes of the Magento
installation this module lives inside — the module has no `vendor/` of its
own. With the module checked out at `app/code/Taxcloud/Magento2/`:

```bash
make test-unit                          # or: make test
make test-unit MAGENTO_ROOT=/path/to/magento   # explicit install root
```

In CI, unit tests run on every push/PR against a matrix of Magento
versions (see `.github/workflows/test.yml`).

Integration tests live in their own pipeline — they boot the full Magento
application against a real database and run via PHPUnit. They are expensive
and run on demand (or on release tags), not on every push. See
[Integration tests](https://fedtax.github.io/Magento2/INTEGRATION_TESTS/) for the 5-minute
local setup and the matrix of supported editions/versions. The suite
includes live v3 REST API contract checks (`Test/Integration/Rest/`), which
is why `TAXCLOUD_API_V3_KEY` (a real key from Developer → API) is required
alongside the V1 sandbox pair; the E2E suite additionally re-runs every
checkout journey with the store switched to V3 REST (see
[E2E tests](https://fedtax.github.io/Magento2/E2E_TESTS/)).

### Coding standard

This module is linted against the **`Magento2`** ruleset from
[magento/magento-coding-standard](https://github.com/magento/magento-coding-standard),
pinned to `^40`. On top of the PSR-1/PSR-2 basics it adds the Magento-specific
checks that a generic PHP standard has no opinion on: short array syntax,
constant visibility, discouraged functions, proxies/interceptors requested in
constructors, legacy `Mage` entities, and Magento's PHPDoc conventions.

The ruleset, the scanned paths and the deferrals all live in
[`phpcs.xml.dist`](phpcs.xml.dist), which both CI and `make lint` run with no
arguments, so the two cannot drift:

```bash
make lint       # run the standard (uses the Magento install's phpcs)
make lint-fix   # auto-fix what phpcbf can (short arrays, whitespace, imports)
```

Two things are worth knowing before changing this setup:

- **The `Magento2` standard reports findings as warnings, not errors.** Nothing it
  reports on this module is an error, so the gate deliberately fails on *any*
  finding, via `phpcs`'s exit code. Grepping the output for `"ERROR"` could never
  fail against this ruleset.
- **`Magento2` is not a superset of PSR-12.** It cherry-picks PSR-1/PSR-2 sniffs and
  exactly one PSR-12 sniff (`PSR12.Properties.ConstantVisibility`). It is used
  on its own here for the Magento-specific coverage; that choice trades away
  PSR-12's stricter whitespace, import and control-structure sniffs. Adding
  `<rule ref="PSR12"/>` alongside it is viable but not free — the two standards
  configure some shared Squiz sniffs with conflicting properties
  (`ignoreBlankLines`, `equalsSpacing`), so whichever is included last silently
  wins.

Some sniffs are deferred rather than satisfied, in the same tactical spirit as
[`phpstan-baseline.neon`](phpstan-baseline.neon), to burn down during the REST
migration. Each carries its rationale inline in `phpcs.xml.dist`; the largest is
`Magento2.Annotation` (PHPDoc formatting, redundant with the native PHP 8.2 types
already on the signatures). Deferrals are scoped to a specific file wherever the
findings are confined to one, so new code is still held to the rule.

Static analysis runs separately, via PHPStan at level 5 — see
[`phpstan.neon`](phpstan.neon) and `make phpstan`.

### Documentation

The documentation site is built from `docs/` with MkDocs (Material theme) by
`.github/workflows/docs.yml`: pull requests get a `mkdocs build --strict` check,
and pushes to `main` publish to GitHub Pages. Navigation lives in `mkdocs.yml`.

```bash
make docs         # live-reloading preview at http://127.0.0.1:8000
make docs-build   # one-off build into site/
```

The site is written for store owners and admins, not developers — read
[Writing documentation](docs/writing-documentation.md) before editing it.
Merchant-facing behavior (settings, install steps, features) is documented
there only; this README covers developer material.

## Internals

### Tax collector detection

Magento's tax total is winner-take-all: exactly one class occupies the `tax` entry of the quote total collectors, and this module claims it with a `preference` on `Magento\Tax\Model\Sales\Total\Quote\Tax` in `etc/di.xml`. If another tax extension wins that slot — via its own preference, its own `sales.xml` entry, or an `around` plugin on `collect()` that skips `$proceed` — `Taxcloud\Magento2\Model\Tax::collect()` never runs. There is no exception and no error: tax is simply not calculated and orders are not filed, while the extension still reports as installed, enabled, and connected.

```
bin/magento taxcloud:diagnose
```

reports, per store where TaxCloud is enabled, which class occupies the tax collector slot, any `around` plugins wrapping `collect()`, and any non-core collector ordered after `tax` that could overwrite the result. It exits non-zero when TaxCloud is not the active collector, so it can be used as a monitoring check. Its output is unaffected by an admin dismissing the notification described below.

Three surfaces report the same verdict:

* **CLI** — the command above.
* **Admin** — a critical system message naming the class that won, with a link that acknowledges it. The acknowledgement stores a fingerprint of the conflict rather than a permanent flag, so a different responsible class, or the same conflict reaching another store view, re-raises the message on its own.
* **Log** — `Taxcloud\Magento2\Model\Tax::collect()` marks the quote when it runs, and an observer on `sales_model_service_quote_submit_before` writes a warning (rate limited to one per store per hour) when an order is placed on an enabled store without that mark. This is the only surface that fires on the storefront path, and it deliberately computes no verdict there.

A clean verdict means only that this module's collector runs — it says nothing about credentials or calculation correctness. Resolving a conflict, including the `<sequence>` recipe for coexisting with a specific named module, is covered in [Another extension is calculating tax](https://fedtax.github.io/Magento2/extension-conflicts/).

### Diagnostics bundle

A single ZIP with everything needed to diagnose a store without access to it, designed to be attached to a support ticket and read by an engineer or an AI assistant. Merchant-facing documentation: [Sending diagnostics to support](https://fedtax.github.io/Magento2/diagnostics/).

```
bin/magento taxcloud:diagnostics:export [--order=INCREMENT_ID] [--redact] [--output=PATH]
                                        [--store=ID | --website=ID] [--no-probe]
                                        [--log-window=standard|extended|maximum]
```

Writes `var/taxcloud-diagnostics-{scope-code}-{YYYYMMDD-HHMMSS}.zip` (UTC timestamp) unless `--output` names a file or directory, and prints the path. Without `--order` the bundle covers every store (or the `--store`/`--website` given); with it, the bundle is narrowed to that order at its store scope. Increment IDs are sequenced per store, so when the same one exists in several stores the command stops and asks for `--store` to pick one. `--redact` masks customer names, street lines, emails and phones; credentials are redacted unconditionally. Exit code is non-zero only when no bundle could be written — a bundle whose sections partly failed is still a success, and lists the failures.

The same bundle comes from **Download Diagnostics** in the TaxCloud settings group (scoped to the config scope being edited) and **TaxCloud Diagnostics** on the admin order view. Both POST to `taxcloud/diagnostics/export`, guarded by the `Taxcloud_Magento2::diagnostics` ACL resource.

| File | Contents |
|---|---|
| `summary.md` | Flattened report: blockers first, then environment, effective settings, collector verdict, probe, order |
| `manifest.json` | Schema version (`BundleGenerator::SCHEMA_VERSION`), module version, generated-at (UTC and store TZ), generating user, redaction mode, files with byte counts, failed sections |
| `settings.json` | Every `tax/taxcloud_settings/*` value at default/website/store scope, inherited vs explicit, source (database, `config.xml`, `env.php`, `config.php`, environment variable) and lock state, plus the effective value per store |
| `magento-tax.json` | Native `tax/*` config, tax rules, rates (capped at 2,000), tax classes, `taxcloud` cache type state |
| `modules.json` | Every module with composer version, `setup_version` and enabled state |
| `environment.json` | Magento/PHP/extension versions, PHP ini limits, deploy mode, cache and session backend names, cron health, indexer states, time zones |
| `collector-diagnostics.json` | `TaxCollectorDiagnostics` verdict per store |
| `probe.json` | Live Lookup + VerifyAddress per distinct configuration, with DNS and TLS measured separately from the API result; plus the Canada access check (`canada_access`) for configurations with Canadian tax on |
| `order.json` | Per-order only: totals, items with TIC and TIC source, addresses, TaxCloud order columns, RDF state, invoices/credit memos/shipments |
| `logs/` | `taxcloud.log` (and rotations) within the window, or the order's correlated records; TaxCloud-related records of `system.log` and `exception.log` |

Implementation notes:

* Collection lives in `Model/Diagnostics/Bundle/`, independent of HTTP. Sections implement `Section\SectionInterface` and are registered, in order, on `BundleGenerator` in `etc/di.xml`; a section that throws is recorded in the manifest and the rest still run.
* Every byte written passes through `BundleContext::scrub()`: `LogRedactor::redactText()` (credential patterns in XML, JSON, print_r, headers, Bearer tokens) plus every credential value configured at any scope, locked in a deployment file, or cached as a Bearer token (`Redaction\CredentialInventory`). `app/etc/env.php` is read only through `ConfigSourceReader`, which keeps the `system` subtree and a hardcoded key whitelist.
* The log path is read from the `Taxcloud\Magento2\Logger\Handler` the object manager builds, so a relocated log (see [Changing the log file location](https://fedtax.github.io/Magento2/extending/#changing-the-log-file-location)) is still found. Logs are tailed with seeks and a timestamp binary search, never loaded whole; the ZIP is written to `var/tmp/taxcloud-diagnostics/` and streamed from disk, then deleted.
* Generating a bundle is audited to `system.log`, and on Adobe Commerce to the Admin Actions Log (`etc/logging.xml`).

### Log correlation

`Model\Logging\GatewayLogger::beginOperation($operation, $quoteId, $orderIncrementId)` binds a correlation context at each operation entry point (the gateways' lookup, verify-address, capture, refund, cancel and order-details methods, and the capture/refund/cancel observers), alongside the `setStore()` binding. Every record forwarded while it is bound carries `correlation_id`, `operation`, `quote_id` and `order_increment_id` in its context array, which Monolog's line formatter renders as JSON at the end of the line. The line format, as operators read it, is described in [Reading the log](https://fedtax.github.io/Magento2/logs/#what-a-line-looks-like).

Beginning an operation replaces the previous context, so a long-running process never attributes one order's lines to another; beginning one for the same order (an observer, then the gateway call it makes) keeps the correlation id. Calls made with no context bound carry none of these keys, and keys a call site passes explicitly win over the bound ones.

Every record also carries the request that wrote it in Monolog's `extra` (the second JSON group): `request`, a random id fixed per logger instance (one HTTP request, CLI run, or cron/consumer process), and `pid` when the host allows `getmypid()`. `Logger\Processor\RequestIdentityProcessor` adds it, registered on the channel in `etc/di.xml`; on hosts with `getmypid` in `disable_functions` it omits `pid` rather than failing. Messages are one line each — trailing line breaks are trimmed, SOAP params and responses are written as JSON, and SOAP wire traces are collapsed with ` | ` — so a line-oriented search finds every record of an order. SOAP operations are labelled `(v1 SOAP)` and REST operations `(v3 REST)`.

### Data patches and uninstalling

Installing the module adds four EAV attributes via data patches:

- `taxcloud_tic` on products (the TaxCloud TIC), from `InstallTaxcloudData`;
- `taxcloud_tic` on categories (the inherited TIC), from
  `AddCategoryTicAttribute`;
- `taxcloud_certificate_id` on customers (the attached exemption
  certificate), from `AddCertificateAttachmentAttribute`;
- `taxcloud_customer_id` on customers (the TaxCloud identity certificates are
  filed under), from `AddTaxcloudCustomerIdAttribute`.

(The legacy `taxcloud_cert` attribute is created and immediately removed
again during a fresh install, so the migration patch always has a source
attribute to read values from.)

These patches are revertable (they implement `PatchRevertableInterface`), so
`bin/magento module:uninstall Taxcloud_Magento2` — which applies to
Composer-installed modules — runs each patch's `revert()` before removing the
module's files.

Reverting drops all four attributes (and, because EAV value storage cascades on
the attribute, any TIC/certificate values stored against products, categories
and customers). Re-installing and running `bin/magento setup:upgrade` re-applies
the patches and recreates the attribute definitions — but not the previously
stored values — so revert is a destructive, one-way cleanup. It only runs on
uninstall. The merchant-facing warning is in
[Installing the extension](https://fedtax.github.io/Magento2/installing/#uninstalling).

## Automated Deployment

This extension includes an automated deployment pipeline for sandbox
environments via GitHub Actions. The pipeline runs itself end to end — tests,
file transfer, and Magento setup — but you start it by hand; see **Deployment**
below.

### Setup

1. **Configure GitHub Secrets** in your repository settings:
   - `SFTP_HOST`: Your sandbox server IP
   - `SFTP_USERNAME`: SSH username (e.g., `root` or `deploy`)
   - `SFTP_PORT`: SSH port (default: `22`)
   - `MAGENTO_ROOT_PATH`: Magento root directory (e.g., `/var/www/html`)
   - `WEB_USER`: Web server user (e.g., `www-data`)
   - `WEB_GROUP`: Web server group (e.g., `www-data`)
   - `SSH_PRIVATE_KEY`: Private SSH key for server access

2. **Generate SSH Key** (if needed):
   ```bash
   ./scripts/setup-ssh-deployment.sh
   ```

### Deployment

Sandbox deployment is manual only: go to Actions → Deploy to Sandbox → Run
workflow. No branch triggers a deployment on push.

The deployment process will:
- Run all integration tests
- Deploy module files via SFTP
- Execute Magento setup commands
- Verify deployment success

## Releasing to the Adobe Commerce Marketplace

New versions of the extension are distributed through the [Adobe Commerce Marketplace](https://commercedeveloper.adobe.com/extensions/versions/taxcloud-magento2).

Each GitHub release automatically produces a Marketplace-ready zip named `taxcloud_magento2-<version>.zip` via the `Build Marketplace Release Package` workflow. This zip respects `.gitattributes` `export-ignore` rules, so dev/CI files (`Test/`, `Makefile`, `scripts/`, `.github/`, `phpunit.xml.dist`, etc.) are excluded.

**To cut a new release:**

1. **Make sure `composer.json` `version` matches** the version you're about to tag (e.g. `1.2.0`). If it doesn't, bump it on `main` first — the Marketplace's EQP validation will reject a submission whose `composer.json` version doesn't match the tag.
2. **Bump `etc/module.xml` `setup_version` to the same value.** Magento 2.3+ no longer uses `setup_version` for schema migration (data patches drive that now), so behavior does not change either way — but keeping it in sync with `composer.json` is the canary that catches a forgotten version bump. The two should never drift.
3. **Create a tag** on `main`:
   ```bash
   git tag -a v1.2.0 -m "v1.2.0"
   git push origin v1.2.0
   ```
4. **Create a GitHub release** from the tag at [Releases → Draft a new release](https://github.com/FedTax/Magento2/releases/new). Auto-generate release notes or write them manually.
5. The `Build Marketplace Release Package` workflow runs automatically on publish and attaches `taxcloud_magento2-<version>.zip` to the release. (If it ever fails, you can re-run it manually from *Actions → Build Marketplace Release Package → Run workflow* and pass the tag.)
6. Go to the [Marketplace extension page](https://commercedeveloper.adobe.com/extensions/versions/taxcloud-magento2) and start a new version submission.
7. **Attach the zip** from the GitHub release under *Attach package*, and **paste the release notes** from the GitHub release body into the submission form.
8. **Submit for review.** Once Adobe approves the submission, the new version is published to the Marketplace automatically.

## License

[![OSL 3.0](docs/images/osl-3.0.svg)](https://opensource.org/licenses/OSL-3.0)

This project is distributed under the Open Software License ("OSL") v. 3.0 (see the LICENSE file in the project root).
