# Changelog

All notable changes to the TaxCloud Magento 2 extension are documented here.

## 1.3.0

### Added

- **Reliable tax reversal on order cancellation.** Canceling an unpaid order
  now reverses the sale in TaxCloud: the reversal hooks `Order::cancel`
  directly (instead of a broad order-save fallback), and the module tracks its
  own `taxcloud_captured` flag on each order, so cancellation works without
  the license-gated OrderDetails API (kept only as a fallback for orders
  captured before this release).
- **Three-mode Logging setting.** *Enable - Basic*: lifecycle events,
  decisions and errors — enough to confirm the extension works, suitable for
  day-to-day use. *Enable - Advanced*: adds full API requests/responses
  including the raw SOAP XML (credentials redacted) and per-call timing — for
  debugging, too verbose for production. *Disable*: no logs. Upgrading
  installs keep their behavior: the old *Enabled* becomes Basic.
- **Dedicated TaxCloud cache type.** API responses are cached under their own
  entry in System → Cache Management, so they can be flushed without touching
  the rest of Magento's caches.
- **Configurable endpoint and log location.** The TaxCloud WSDL endpoint is
  now a store setting (point staging at a sandbox without a code change), and
  the log file path can be overridden via DI.

### Changed

- Log entries now carry real severities (error / warning / info / debug), so
  failures can be grepped and alerted on. Previously everything was logged as
  info.
- Internal restructuring: the monolithic gateway class was split into
  service-contract interfaces and focused, individually tested collaborators,
  creating the seam for a future REST transport. No behavior change.
- CI now enforces the Magento coding standard and PHPStan (level 5) alongside
  the unit, integration and E2E suites.

### Fixed

- "Using default TIC" messages were written to the log even with logging
  disabled; they now respect the logging setting.

## 1.2.0

### Added

- New **API Timeout** admin setting (`tax/taxcloud_settings/api_timeout`,
  default 10 seconds) that caps how long a TaxCloud API call may hang before
  failing over to Magento's tax fallback, instead of stalling checkout for the
  ~60-second socket default.

### Fixed

- Tax no longer compounds across repeated total-collection passes. Pre-tax
  price and row totals are snapshotted on the first pass and reused, so
  third-party collectors that mutate item prices between passes can no longer
  inflate the tax-inclusive amount. The snapshot invalidates on quantity change
  so genuine cart edits are still picked up.
- A TaxCloud failure no longer breaks checkout or refunds. Address
  verification exceptions leave the address unchanged instead of blocking
  checkout, and a failed `returnOrder` during a credit memo no longer surfaces
  an error to the admin (the refund is already committed by Magento).
- Prevented duplicate order cancellations when both the order-cancel and
  order-save events fire for the same order within a single request.
- Fixed adding a configurable product to the cart: composite child lines with
  no tax-calculation id are now skipped instead of using `null` as an array
  key, which is a PHP 8 deprecation (fatal in developer mode).
- Fixed `verifyAddress` returning an undefined value on its error paths; it now
  returns `false` consistently.
- Added SOAP connection and read timeouts plus a single bounded retry on the
  tax-calculation path, replacing the previous blind immediate retry at every
  call site. Retries are skipped on timeouts so checkout does not wait through
  a second stall.
- The `InstallTaxcloudData` setup patch now implements `revert()`, so
  uninstalling the module removes the `taxcloud_tic` and `taxcloud_cert`
  attributes instead of leaving orphaned EAV metadata behind.
- Synced the module version across `composer.json`, `etc/module.xml` and the
  README so all reported versions match.

### Security

- Redact TaxCloud API credentials (`apiLoginID`, `apiKey`) from
  `var/log/taxcloud.log`. Before this release, every SOAP request payload —
  lookupTaxes, authorizedWithCapture, returnOrder, returnOrderCancellation
  and verifyAddress — was written to disk with both credentials in cleartext.
  Logging is enabled by default, so any merchant who had not explicitly
  disabled it was persisting their store's API credentials on every
  checkout, refund, cancellation and address verification, exposing them to
  anyone with file-system or log-aggregation access (sysadmins, hosting
  staff, backup snapshots, log shippers such as Datadog/Splunk, SIEM
  exports). The field names still appear in the log so operators can
  confirm the params were sent; their values are replaced with
  `***REDACTED***`.
- Scope the exempt-states cache for exemption certificates per
  `(customer, certificate)` instead of per certificate alone. The previous
  key allowed a customer who learned another customer's certificate UUID
  (the `taxcloud_cert` Magento attribute is user-editable) to inherit the
  rightful holder's cached state list at checkout. Each customer now gets
  their own cache slot, validated against TaxCloud independently.

### Documentation

- Document the 1-hour propagation window for exemption-certificate changes.
  At checkout the extension caches the list of states covered by a
  certificate for 1 hour, so revocations or covered-state edits made in the
  TaxCloud dashboard may take up to an hour to be reflected in this
  extension's checkout decisions. Operators who need a change reflected
  immediately can flush Magento's cache.

### Testing & CI

- Expanded and hardened the unit test suite, including PHPUnit 12
  compatibility and coverage for previously untested paths.
- Added integration test support against an installed Magento instance.
- Added end-to-end (E2E) test infrastructure covering checkout, refunds and
  configuration flows.
- Unified the CI pipeline into a single workflow that runs across multiple
  Magento and PHP versions, and upgraded the GitHub Actions and CodeQL setup.
