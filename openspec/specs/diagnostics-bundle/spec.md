## Purpose

Lets a merchant with only admin access hand support everything needed to diagnose a TaxCloud issue in one file — settings with their provenance, Magento's tax setup, extensions, environment, the collector verdict, a live API probe, the relevant logs and, for one order, that order's tax data — without ever exposing credentials, and with customer details masked when the merchant asks.
## Requirements
### Requirement: A diagnostics bundle can be generated from the admin and the CLI
The extension SHALL generate a single ZIP named `taxcloud-diagnostics-{scope-code}-{YYYYMMDD-HHMMSS}.zip` containing `summary.md`, `manifest.json`, `settings.json`, `magento-tax.json`, `modules.json`, `environment.json`, `collector-diagnostics.json`, `probe.json` and `logs/`, from a **Download Diagnostics** button in the TaxCloud settings group, a **TaxCloud Diagnostics** button on the admin order view, and `bin/magento taxcloud:diagnostics:export`. Every surface SHALL produce the same format from the same collector service.

#### Scenario: Generated at default scope
- **WHEN** an admin generates a bundle with the configuration scope switcher on Default Config
- **THEN** the bundle covers every store

#### Scenario: Generated at website or store scope
- **WHEN** an admin generates a bundle while editing a website or store view scope
- **THEN** the bundle covers only that scope's stores

#### Scenario: Generated from an order
- **WHEN** a bundle is generated for an order
- **THEN** it covers only the order's store, includes `order.json`, and its TaxCloud log holds only records correlated with the order plus surrounding context

### Requirement: Credentials never leave the store
No value of `api_id`, `api_key`, `rest_api_key`, or a Bearer token SHALL appear in any file of a bundle, under any option. Each credential SHALL be represented by a fingerprint recording whether it is set, its length, its last four characters (for values of eight characters or more), a SHA-256 prefix, and flags for leading or trailing whitespace, embedded line breaks and non-ASCII characters. `rest_connection_id` SHALL be emitted in full unless its value equals a credential. `app/etc/env.php` SHALL NOT be included; only its locked store-configuration values and a fixed whitelist of backend names SHALL be read.

#### Scenario: Credentials in settings and logs at several scopes
- **WHEN** credentials are configured at default, website and store scope, locked in a deployment file, cached as a Bearer token and present in log lines in any shape
- **THEN** no credential value appears in any file of the bundle

### Requirement: Customer details are masked on request
When the merchant chooses to mask customer details, names, street address lines, email addresses and phone numbers SHALL be replaced by an explicit marker throughout the bundle, including log payloads and order data, while city, state, ZIP and TIC SHALL be preserved. The mode SHALL be recorded in `manifest.json` and `summary.md`.

#### Scenario: Masked bundle
- **WHEN** a bundle is generated with customer details masked
- **THEN** a street address in a log payload is shown as the marker and its ZIP code is unchanged

#### Scenario: Probe test address is not customer data
- **WHEN** a bundle is generated with customer details masked
- **THEN** `probe.json` shows the probe's fixed test address unmasked, and credentials in it are still redacted

### Requirement: A failing part never fails the bundle
If a collector throws, the bundle SHALL still be generated, and the failure SHALL be recorded in `manifest.json` and as a blocker in `summary.md`. Temporary files SHALL be removed on success and on failure.

#### Scenario: One collector throws
- **WHEN** reading Magento's tax rules throws during generation
- **THEN** the bundle is delivered without `magento-tax.json` and its summary names the failed collector and the error

### Requirement: Logs are bounded and tailed
The TaxCloud log SHALL be located from the DI-configured log handler, read by seeking rather than loading, and limited by default to the last 10 MB or 7 days, whichever is smaller, with larger windows selectable. `system.log` and `exception.log` SHALL be included only as records mentioning TaxCloud or tax collection. When logging is disabled or the log is missing, empty, unreadable or has no records in the window, `summary.md` SHALL say so under Blockers.

#### Scenario: Relocated log
- **WHEN** the log handler's file name is overridden in DI
- **THEN** the bundle includes the log from the overridden path

### Requirement: Settings carry per-scope provenance
`settings.json` SHALL record every `tax/taxcloud_settings/*` setting at default and at each covered website and store: whether it is set there or inherited, its source, and whether it is locked by `app/etc/env.php`, `app/etc/config.php` or an environment variable; and each store's effective value with the scope it resolved from.

#### Scenario: Locked value
- **WHEN** a TaxCloud setting is locked in `app/etc/env.php`
- **THEN** `settings.json` marks it locked with that source and `summary.md` flags it

### Requirement: A live probe reports connectivity separately from credentials
When enabled (the default), generation SHALL run a read-only canned Lookup and VerifyAddress against a fixed test address for each distinct TaxCloud configuration in scope, recording per call the URL, HTTP status, duration, outcome and error, with DNS resolution and TLS handshake recorded separately and REST authentication mode and token acquisition recorded. For a configuration whose stores have Canadian tax in effect, the probe SHALL also run the Canada access check and record its outcome and message as a separate call, so a missing Canadian account entitlement is visible in the bundle. The store's API timeout SHALL apply and a probe failure SHALL NOT fail generation.

#### Scenario: Blocked outbound connection
- **WHEN** the TLS handshake to the TaxCloud endpoint fails
- **THEN** the API calls are recorded as skipped for that reason and the bundle is still generated

#### Scenario: Canadian tax on for an account without Canada
- **WHEN** a store with Canadian tax in effect is probed and its account refuses Canadian lookups
- **THEN** the probe records the Canada access check as failed with a message to contact TaxCloud support, and the summary lists it as a blocker

### Requirement: Access is separately granted and audited
Generation SHALL require the `Taxcloud_Magento2::diagnostics` ACL resource and a POST with the admin form key, and every generation SHALL be recorded with the user, time, scope and masking mode.

#### Scenario: Admin with tax configuration access only
- **WHEN** an admin role grants tax configuration but not TaxCloud Diagnostics Export
- **THEN** the role is not allowed to generate a bundle

### Requirement: The summary surfaces what the logs show
`summary.md` SHALL list, per included log file, each distinct warning-or-worse message with numbers and identifiers normalised, its count, and when it was first and last seen relative to generation, and SHALL flag errors seen within the 24 hours before generation. It SHALL state when TaxCloud last performed a successful lookup, a capture, a refund, a failed capture or refund, and a Magento fallback, as recorded in the exported logs, and SHALL warn when the TaxCloud log has not been written for more than 24 hours while logging is enabled.

#### Scenario: Recurring stale error
- **WHEN** the same error for different orders was last logged twenty days before generation
- **THEN** the summary lists it once with its count and a last-seen age of twenty days, and does not flag it as recent

#### Scenario: Silent log
- **WHEN** logging is enabled and the TaxCloud log was last written five days before generation
- **THEN** the summary warns that nothing has been logged for five days

### Requirement: A pending setup upgrade is a blocker
`environment.json` SHALL record whether module versions, schema patches or data patches are ahead of the database, and `summary.md` SHALL list a pending `bin/magento setup:upgrade` under Blockers, naming the modules concerned.

#### Scenario: Module deployed without setup:upgrade
- **WHEN** a module's code version is newer than the version recorded in the database
- **THEN** the summary's Blockers name the module and both versions and instruct to run `bin/magento setup:upgrade`

