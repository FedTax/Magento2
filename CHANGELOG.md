# Changelog

All notable changes to the TaxCloud Magento 2 extension are documented here.

## 1.4.0

This release makes TaxCloud's v3 REST API a fully supported transport alongside
V1 SOAP, adds exemption certificate management, and adds TIC search to the
admin. It includes every fix listed under 1.3.1 below.

Run `bin/magento setup:upgrade` after updating: existing installs are pinned to
their current API and exemption certificate data is migrated. In production
mode also run `bin/magento setup:di:compile` — this release adds
dependency-injection preferences and removes classes.

**This release contains a breaking change**: the `taxcloud_cert` customer
attribute is removed and its values migrated. Existing exemptions keep working
with nothing to re-enter, but anything reading that attribute directly needs
updating — see *Changed*, below.

### Added

- **Full support for TaxCloud's v3 REST API.** A new store-scoped **API Type**
  setting (*Stores → Configuration → Sales → Tax → TaxCloud Settings*) chooses
  between **V1 SOAP (legacy)** and **V3 REST**, with the matching credential
  fields for each (the V3 API Key is stored encrypted). Every TaxCloud
  operation — tax calculation, order capture, refunds, cancellations, address
  verification and exemption certificates — runs over whichever API the store
  selects. Fresh installs default to V3 REST; installs upgrading with saved V1
  credentials are pinned to V1 SOAP, so nothing changes until an admin
  switches. A **Test Connection** button verifies the credentials as entered,
  for either API, before saving.
- **Automatic V1 → V3 credential migration.** At `setup:upgrade`, every scope
  with its own V1 credentials is validated against TaxCloud and its V3
  Connection ID filled in automatically. Until a V3 API Key is saved, REST
  calls for migrated scopes authenticate with short-lived tokens exchanged
  from the V1 credentials — so migrated merchants get a working REST
  connection with no portal action. If validation fails, the upgrade stops
  naming the scope to fix; set `TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1` to defer
  and run `bin/magento taxcloud:migrate-credentials` later.
- **TIC search.** Every field that takes a Taxability Information Code — the
  product TIC, the category TIC, the Default TIC and the Shipping TIC — now
  offers autocomplete: type what you sell and pick from matching codes, each
  shown with its description. A code already saved is displayed with its
  meaning. The fields stay free-text: a code TaxCloud doesn't recognise is
  kept exactly as entered, and saving is never blocked.
- **Exemption certificate management.** Exempt customers can hold multiple
  TaxCloud exemption certificates, managed without copying identifiers out of
  the TaxCloud portal by hand: a certificate panel on the customer admin page
  lists, creates, attaches and deletes certificates (including a diagnostic
  showing what a customer's TaxCloud ID actually resolves to — previously a
  mismatch meant a silently taxed customer), and customers can review and
  delete their own certificates from My Account. The certificate an
  administrator attaches to a customer applies automatically at checkout
  whenever it covers the destination state, and orders record the certificate
  that untaxed them. Off by default — enable via *Enable Exemption
  Certificates* in TaxCloud Settings. Works identically on both APIs.
- **Requests identify the extension.** Every call to TaxCloud carries a
  `User-Agent` naming the extension, Magento and PHP versions, so TaxCloud
  support can tell which versions produced a request without asking. It
  contains no credentials or customer data.

### Changed

- **BREAKING: the `taxcloud_cert` customer attribute has been removed.** Its
  values are migrated automatically at `setup:upgrade` to a new attribute
  supporting more than one certificate per customer, so customers exempt
  before this release stay exempt with nothing to re-enter. Integrations, data
  imports or custom code reading `taxcloud_cert` directly must be updated to
  read `taxcloud_certificate_id`.
- Composite products (bundles, configurables) are now described identically in
  every payload sent to TaxCloud — calculation, capture and refund — on both
  APIs, so a composite order can always be cleanly refunded.

## 1.3.1

### Fixed

- **Bundle products with dynamic pricing were taxed on a fraction of their
  value.** A bundle's selections store their quantity per bundle — one unit
  inside a qty-2 bundle is stored as 1, against a row total for 2 — and the
  tax lookup sent that stored number. A qty-2 bundle was therefore taxed as
  one, a qty-3 bundle as one of three, and the shopper was undercharged by the
  difference. Bundle lines now carry the quantity their row total was built
  from. Configurable, grouped, simple and fixed-price bundle products were
  never affected.
- **A bundle's value was reported to TaxCloud twice.** The lookup sent both the
  bundle line and each of its selections, so the transaction recorded against
  the order — and captured with it — covered more than the order was worth,
  while Magento (which excludes the bundle wrapper from its own totals by
  design) charged less. Returns and cancellations described the same order
  differently again. All three now describe a bundle the same way: by its
  selections, which are the lines that carry its price.

  Orders placed with a dynamic-price bundle before this release were charged
  and reported incorrectly and are not corrected by updating; they need
  adjusting in TaxCloud.
- **Refunds named items the order never reported.** A credit memo lists a
  composite's children alongside their parent, so refunding a configurable or a
  fixed-price bundle returned its selections as extra $0 lines — item IDs that
  were never in the original lookup. No tax rode on them, but the three payloads
  for one order each described it differently, which is the condition the bundle
  defect above hid inside. All three now name the same lines.
- A cart line at quantity zero no longer raises a division-by-zero error while
  its discount is being apportioned.

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
