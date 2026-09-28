## Context

See proposal.md for motivation and specs for required behavior. Current state that shapes the approach:

- Addresses flow through the module as **v1-shaped arrays** (`Address1/Address2/City/State/Zip5/Zip4`) built by `RequestBuilder` and shared by both transports. `RestRequestBuilder::toV3Address()` reshapes them for v3 and currently emits no `countryCode`.
- The US-only gate lives in three places: `RestGateway::lookupTaxes()` (quote), `RequestBuilder::buildDestinationFromOrder()` (orders — shared with SOAP's `Api.php`), and `Api::lookupTaxes()` (SOAP). `PostalCodeParser` only understands 5(+4)-digit ZIPs.
- Verify-address runs inside the lookup's before-event (`Observer/Sales/Address::verifyRestCarts`), on the v3 payload.
- Certificates are resolved by `CertificateResolver::resolve($customer, $state, $store)` in the lookup and again by `Observer/Sales/RecordCertificate` at order placement.
- Admin credential test: `ConnectionTester` + `Controller/Adminhtml/Connection/Test` + `Block/.../TestConnection` + `test_connection.phtml`, ACL `Magento_Tax::config_tax`.
- Diagnostics probe (`ApiProbe`) groups stores by credential fingerprint and records calls generically; `SettingsSection` picks up every `TaxcloudConfig::XML_PATH_*` constant automatically.
- Live API facts (probed 2026-09-16 on the dev connection): Canadian carts need `countryCode: CA`; rate follows the **province** (postal code not validated against it); `verify-address` returns 400 "unsupported country code" for CA; `isExempt` works for CA; USD and CAD both accepted. No endpoint exposes account features.

## Goals / Non-Goals

**Goals:**
- Canadian support confined to the REST path, with SOAP code untouched.
- One definition of "Canadian tax in effect for this store", used by every gate.
- A single access-check service shared by the admin button, the save-time observer and the diagnostics probe.
- With the setting off, request payloads differ from today only by the added `countryCode: US`.

**Non-Goals:**
- Refactoring the v1-shaped address arrays into value objects.
- Extracting `ConnectionTester::resolveScopeStore()` into a shared service (the new checker gets its own small resolver; consolidating is a follow-up).
- Canadian origin addresses.

## Decisions

### D1. Effective gate: `TaxcloudConfig::isCanadaTaxEnabled($store)`
Returns true only when `tax/taxcloud_settings/canada_tax_enabled` is set **and** `getApiType($store) === rest`, resolved at store scope like every accessor. Every call site passes the entity's store.
- *Alternative:* check the API type at each call site — rejected: easy to forget one, and SOAP's `Api.php` would need to learn about Canada.

### D2. Canadian addresses as v1-shaped arrays with a `Country` key
A Canadian destination is `['Address1','Address2','City','State'=>province,'Zip5'=>'','Zip4'=>'','Country'=>'CA','PostalCode'=>'A1A 1A1']`. US arrays stay exactly as they are (no `Country` key), so SOAP params and SOAP cache keys are unchanged. `toV3Address()` emits `countryCode` = `Country ?? 'US'`, and `zip` = `PostalCode` for CA, `Zip5[-Zip4]` otherwise.
- *Alternative:* put the postal code in `Zip5` — rejected: `Zip5` means "5 digits" everywhere it is read, and a Canadian code there is a latent bug for any future reader.
- *Alternative:* introduce an Address value object — rejected as out of scope (see Non-Goals).

### D3. Builders
- `PostalCodeParser::parseCanadian($postcode): ?string` — uppercase, strip whitespace, match `^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z]\d[ABCEGHJ-NPRSTV-Z]\d$`, return `A1A 1A1` or null.
- `RequestBuilder::buildCanadianDestination($address, string $postalCode): array` — the D2 shape, province via the existing `resolveRegionCode()`.
- `RequestBuilder::buildDestinationFromOrder($order, bool $allowCanada = false)` — CA branch only when `$allowCanada`; a CA address with no resolvable province or an invalid postal code is unresolved (existing logging). The default keeps SOAP's `Api.php` call byte-for-byte unchanged; `RestRequestBuilder::buildOrderPayload()` passes `$this->config->isCanadaTaxEnabled($store)`. Log wording changes from "valid US destination" to "usable destination".

### D4. Lookup gate in `RestGateway::lookupTaxes()`
Country dispatch replaces the current ZIP-then-country order: US → existing ZIP parse/validate; CA with the gate on → `parseCanadian`, warning and zero result if invalid; anything else → zero result ("Not US" log, or "Canada tax not enabled for this store" for CA). Region and city checks apply to both. For CA the certificate resolver is **not called** (no certificate, no API call). On a failed CA lookup the error log appends the account-access hint before the fallback runs.

### D5. Verification skip in the observer
`Observer/Sales/Address::verifyRestCarts()` skips any cart whose destination `countryCode` is set and not `US`. The gateway's `verifyAddress()` is left transport-generic. The SOAP branch of the observer never sees CA (SOAP never builds CA destinations).
- *Alternative:* skip in `RestGateway::verifyAddress()` — rejected: the v1-shaped array passed to it has already lost the country in `toV1Address()`.

### D6. `RecordCertificate` skips non-US orders
The observer resolves the destination address's country from the order (same shipping-then-billing resolution) and returns early when it is not `US`. This is cheaper and more explicit than relying on "ON is not a US state".

### D7. Access check service: `Model/Canada/CanadaAccessChecker`
`check($store): CanadaAccessResult` (immutable: `outcome` ∈ `enabled|not_enabled|unavailable`, `message` Phrase/string, `rate` ?float, `httpStatus` ?int, `durationMs` int).
1. Not REST → `unavailable` ("requires V3 REST").
2. No connection ID → `unavailable`.
3. `RequestBuilder::buildOrigin($store)` null → `unavailable` ("set a valid US shipping origin").
4. `RestClient::pingForScope($store)`; any outcome other than OK → `unavailable` with the credential / connection / transport reason (thrown exceptions likewise).
5. POST `/carts` via `RestClient::request()` (no retry — an admin check should fail fast) with fixed cart ID `taxcloud-canada-access-check` (also the customer id), CAD, one line TIC 0 price 100 qty 1, destination Toronto City Hall `100 Queen St W, Toronto, ON M5H 2N2, CA`.
6. Classify: exception → `unavailable`; 2xx → rate of line 0 > 0 → `enabled` with rate, else `not_enabled`; 401 / 404 / 429 / 5xx → `unavailable` (the ping just succeeded, so these are transient or misconfiguration); any other 4xx (including 403) → `not_enabled` with `errorDetail()`.
- *Why a lookup and not a feature endpoint:* none exists; a cart is the only observable signal. Fixed cart ID makes it an upsert.
- *Why ping first:* it separates "credentials/connection broken" from "account refuses Canada" without guessing what a 403 means. With a known-good connection, a refusal of the Canadian cart can only be about the destination. Cost: one extra lightweight GET per check.

### D8. Admin surfaces
- `system.xml`: `canada_tax_enabled` (select Yes/No, sortOrder 115, all scopes, depends `enabled=1` and `api_type=rest`, comment per spec) and `check_canada_access` (button, sortOrder 116, depends `enabled=1`, `api_type=rest`, `canada_tax_enabled=1`). `config.xml` default `0`.
- `Controller/Adminhtml/Canada/Check` (POST, `Magento_Tax::config_tax`) → JSON `{success, outcome, message}`; scope from `website`/`store` params via `Model/Canada/ConfigScopeStore` (same rules as ConnectionTester: store param, else website default store, else null).
- `Block/Adminhtml/System/Config/CheckCanadaAccess` + `check_canada_access.phtml`, modelled on TestConnection; text notes that the check uses saved settings.
- Save-time: `Observer/Adminhtml/CheckCanadaAccessOnSave` on `admin_system_config_changed_section_tax` in `etc/adminhtml/events.xml`. Runs when `isCanadaTaxEnabled(scopeStore)` and (`changed_paths` absent, or it intersects {`canada_tax_enabled`, `api_type`, `rest_api_key`, `rest_connection_id`, `api_id`, `api_key`}). `api_id`/`api_key` are included because Bearer auth exchanges them. Adds a success or warning message; catches `\Throwable` and logs.

### D9. Diagnostics
`ApiProbe::groupKey()` adds `isCanadaTaxEnabled`; `probeRest()` adds a `canada_access` call entry when on (skipped when network/auth is blocked), mapped from `CanadaAccessResult` into the existing call shape (`success`, `http_status`, `duration_ms`, `error_message`), so `SummaryRenderer` renders and flags it with no special-casing. `SummaryRenderer::SUMMARY_SETTINGS` gains `canada_tax_enabled`. `settings.json` picks the setting up via the new `XML_PATH_` constant.

### D10. Test harnesses (added with the approved integration/e2e coverage)
- **Integration:** a `RecordingRestClient` seeded as the shared `RestClient` by `IntegrationTestCase::installRestMock()`, mirroring the SOAP harness. Two non-obvious requirements, both found by failing runs: the router holds a **`RestGateway\Proxy`** whose cached subject must be evicted too (otherwise the tests silently talk to the real API), and the double must be **uninstalled in `tearDown`** (otherwise later classes — `RestLiveApiTest`, the probe, `UserAgentWiringTest` — resolve it instead of the real client). Admin-config saves run in the **adminhtml config scope**, or `system.xml` is not read and values land under the group path instead of their `config_path`.
- **E2E:** a `canada-on` setup/specs/teardown project trio, chained after `colorado-on`. The setup asserts account access via the button, so an account without Canada fails once with a clear message rather than as a golden-value mismatch.

## Risks / Trade-offs

- [Account without Canada returns something unanticipated, e.g. 200 with a non-zero US-style rate] → The check would read "confirmed". Mitigated by using a Toronto address (a non-Canadian computation would not return 13%); asking TaxCloud support to confirm is listed in proposal Impact. Classification is isolated in one method.
- [Every v3 lookup cache key changes once (payload gains `countryCode`)] → One cache miss per cart after deploy; acceptable.
- [Setting turned off between order placement and capture drops the filing] → Logged as unresolved destination; documented on the merchant page. Deciding from the order's charged tax instead was rejected: it would file orders from stores that explicitly turned Canada off.
- [Save-time check adds an API round-trip to config save] → Only when the relevant paths changed and the setting is on; timeouts use the store's API timeout (default 10s).
- [Sample cart in the merchant's TaxCloud account] → One fixed-ID cart, never converted to an order; mentioned in docs.
- [`changed_paths` missing on some Magento versions] → Treated as "unknown", so the check runs whenever the setting is effectively on.

## Migration Plan

No data migration: new config path with default `0`. Deploy with `setup:upgrade` (no schema change) and cache flush for `system.xml`. Rollback: set Calculate Canadian Tax = No, or revert the release; Canadian orders already filed remain valid TaxCloud orders.

## Open Questions

- Exact TaxCloud response for a Canadian lookup on an account without Canada (status and detail text) — affects only the wording shown, not the classification (D7).
