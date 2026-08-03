# Changelog

All notable changes to the TaxCloud Magento 2 extension are documented here.

## Unreleased

Run `bin/magento setup:upgrade` after updating: this release pins existing
installs to their current API via a data patch.

### Added

- **New "API Type" setting** (*Stores → Configuration → Sales → Tax → TaxCloud
  Settings*) choosing between **V1 SOAP (legacy)** and **V3 REST**. Fresh
  installs default to V3 REST; installs upgrading with saved V1 credentials
  are pinned to V1 SOAP, so nothing changes until an admin switches. The
  setting is store-scoped, like every other TaxCloud setting.
- **V3 REST credentials.** With V3 REST selected, the form asks for the API
  Key (*Developer → API* in TaxCloud, stored encrypted) and the Connection ID
  (*Integrations → Custom API*) instead of the V1 API ID / API Key pair.
- **"Test Connection" button** for both API types. Verifies the credentials
  as currently entered (even unsaved) against TaxCloud — the v3 ping endpoint
  for REST, the SOAP `Ping` operation for V1 — and reports success, a wrong
  key, an unknown Connection ID, or a transport problem, per the scope being
  edited.
- **Store-aware gateway routing (internal).** All TaxCloud operations now
  dispatch through a router keyed on the store's API Type, preparing the REST
  migration. Until REST tax operations ship, both selections transact over
  SOAP, so this release changes no tax behavior.

## 1.3.0

Run `bin/magento setup:upgrade` after updating: this release adds a category
attribute, an order column and a new cache type.

### Added

- **Category-level TICs.** Categories now carry their own TIC (*Catalog →
  Categories → TaxCloud*), so a merchant can tag a category once instead of
  every product in it. A product's TIC is taken from the product, then the
  nearest category above it, then the store's Default TIC. TICs inherit down
  the category tree and the most specific category wins. The attribute is
  store-view scoped, and stores that set no category TIC behave exactly as
  before.
- **Multi-store and multi-website support.** Every TaxCloud setting is now
  resolved against the store the order or cart belongs to, so store views can
  run their own TaxCloud account, or turn the extension off entirely, without
  affecting the others. Previously admin-side work (capture, refund,
  cancellation) read the default store's configuration, so a store view with
  its own credentials reported sales to the wrong TaxCloud account, and one
  enabled only at store scope collected tax from shoppers that was never
  reported at all.
- **New "Only do tax calculations without further Taxcloud integration"
  setting.** Keeps tax calculation and address verification running while
  suppressing everything that records or reverses a sale in TaxCloud. For
  merchants who push orders to another system (QuickBooks, for example) that
  is itself connected to TaxCloud, where a second push from Magento would
  report the same sale twice. Store-scoped and off by default.
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
  the rest of Magento's caches. A cached rate never outlives the store's
  current day, so a rate change or a sales-tax holiday that starts at midnight
  is picked up on the day it takes effect, and setting the cache lifetime to 0
  stops the extension writing to the cache at all.
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

- A single character that is not valid UTF-8 anywhere in a TaxCloud response —
  an accented letter in a customer name or address, typically — used to discard
  the entire response, so the call was treated as failed and checkout fell back
  to Magento's own tax rates. Those responses are now read correctly.
- "Using default TIC" messages were written to the log even with logging
  disabled; they now respect the logging setting.
- A refund that failed after the request reached TaxCloud is no longer retried
  automatically. TaxCloud's SOAP API does not deduplicate returns, so a retry
  could book the refund twice; only a failure that never left the store — no
  connection, no DNS — is repeated now.
- A single cart line the tax fallback could not price used to zero out tax for
  the whole cart. That line is skipped instead, and every path that leaves a
  cart untaxed is now logged at critical.

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
