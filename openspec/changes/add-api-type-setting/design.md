# Design: add-api-type-setting

## Context

See proposal.md — Why. Relevant current state:

- All TaxCloud traffic is V1 SOAP via `Model\Api`, which implements the aggregate `Api\GatewayInterface`; di.xml sets it as the preference for all four narrow gateway interfaces (`Lookup/Order/Address/ExemptionGatewayInterface`). Call sites already depend only on the narrow interfaces and receive the entity (quote/order/creditmemo/address + optional `$store`) whose store determines scope.
- Config lives under `tax/taxcloud_settings/*`; fields are default/website/store scoped. `wsdl_url` already models "endpoint with a shipped default" (`canRestore="1"`).
- V3 REST: base URL `https://api.v3.taxcloud.com`; auth = `X-API-KEY` header + Connection ID in the path; no separate sandbox (test vs production is a property of the Connection ID). Ping: `GET /tax/connections/{connectionID}/ping`.
- V1 SOAP WSDL exposes `Ping(apiLoginID, apiKey)` returning a `ResponseBase`-style payload — same envelope handling `Model\Api` already uses.



## Goals / Non-Goals

**Goals:**

- Introduce `api_type` with correct upgrade/fresh-install defaulting.
- Per-type credential fields with conditional visibility and a working Test Connection button for both types.
- Establish the store-aware routing gateway and the minimal REST client (ping only) as the foundation later REST changes extend.

**Non-Goals:**

- REST implementations of tax operations (lookup/capture/return/verify/exemption).
- Any change to SOAP behavior, retries, caching, or fallback logic.
- Credential migration between V1 and V3.



## Decisions



### D1. Defaulting via config.xml + one-shot data patch

`etc/config.xml` ships `<api_type>rest</api_type>`. A `Setup\Patch\Data\PinSoapApiTypeForExistingInstalls` patch checks `core_config_data` for any saved `tax/taxcloud_settings/api_id` (any scope); if found and no `api_type` row exists, it inserts `api_type = soap` at default scope. Data patches run once and are recorded in `patch_list`, which satisfies the run-exactly-once requirement; the "no api_type row exists" guard makes it safe even if re-applied (e.g. after `patch_list` truncation).

- *Alternative considered:* defaulting dynamically in a config backend/source at read time ("if api_id set → soap"). Rejected: implicit, surprises admins (value shown in UI depends on other fields), and every consumer would need the same logic.



### D2. Scope model for new fields

`rest_api_key` uses backend model `Magento\Config\Model\Config\Backend\Encrypted` (encrypted at rest, obscured in form) — an upgrade over the plain-text `api_key`, and cheap since the field is new. `rest_connection_id` is plain text with a UUID-format frontend validation hint (not hard-enforced, in case TaxCloud changes format). Field visibility uses `<depends><field id="api_type">…` alongside the existing `enabled` dependency; Magento's `depends` also lifts `required-entry` on hidden fields, which is exactly the behavior the spec requires.

### D3. Store-aware routing gateway (`Model\Gateway\Router`)

New class implements `Api\GatewayInterface` and becomes the di preference for the aggregate and all four narrow interfaces. It receives the SOAP implementation (`Model\Api`) and the future REST gateway as constructor dependencies (REST via proxy to avoid eager instantiation). Per call it derives the store the same way `Model\Api` does today (from the passed entity / explicit `$store` argument), resolves `api_type` for that store via a new `Model\Config\ApiTypeResolver` (thin wrapper over `ScopeConfigInterface` with explicit store scope), and delegates.

- **Transitional rule (this change):** the routing table maps *both* types to SOAP for all tax operations, because no REST operations exist. The REST branch is expressed in code (routing decision + tests) but its target for tax ops is the SOAP implementation, with a comment marking it as pending migration. This gives zero behavior change while proving the seam.
- *Alternative considered:* swap di preference per deployment. Rejected: global and compile-time; cannot honor store-scoped `api_type`.
- *Alternative considered:* factory injected into each call site. Rejected: touches every consumer; leaks transport awareness.



### D4. Minimal REST client (`Model\Gateway\Rest\RestClient`)

Thin HTTP client over `Magento\Framework\HTTP\Client\Curl` (or `GuzzleHttp` if already a module dependency — it is not, so Curl). Responsibilities: build URLs from a base endpoint + connection id, set `X-API-KEY`/`Accept` headers, JSON-decode, map status codes to typed results. Only public operation for now: `ping(RestCredentials $credentials): PingResult`. Base URL default `https://api.v3.taxcloud.com` stored at `tax/taxcloud_settings/rest_endpoint` (hidden from admin UI for now, mirroring how `wsdl_url` supports staging overrides; can be surfaced later). Timeout reuses the existing `api_timeout` setting.

- Credentials are passed in as a value object, not read inside the client — keeps the client scope-agnostic and lets the test button inject unsaved form values.



### D5. Test Connection button

One `frontend_model` block (extends `Magento\Config\Block\System\Config\Form\Field`) rendering a button + result area, shown for both API types (its own `depends` only on `enabled`). Its JS posts the *currently entered* form values (api_type, api_id, api_key, rest_api_key, rest_connection_id, plus the scope params `website`/`store` from the URL) to a new adminhtml controller `Controller\Adminhtml\Connection\Test` (route `taxcloud/connection/test`, `_isAllowed()` = the ACL resource of the tax config section, form key validated). The controller:

1. Validates required inputs for the selected type; short-circuits with a validation message if incomplete.
2. Resolves fallbacks for blank/obscured values from saved config *for the scope being edited* (website/store params → fall back through store → website → default; `rest_api_key` decrypted via `EncryptorInterface`).
3. Dispatches: `rest` → `RestClient::ping`; `soap` → a new `ping()` on the SOAP side (thin call through the existing SOAP client provider with the given credentials, bypassing cache/retry).
4. Returns JSON `{success, message}` with the spec's per-failure-mode messages; never echoes credential values.

- *Alternative considered:* Magento's built-in "Validate VAT"-style button per type. One shared button + type dispatch keeps system.xml smaller and the UX single-purpose.



### D6. TaxcloudConfig as the single read point

*(Revised during implementation.)* The module already centralizes every store-scoped config read in `Model\Config\TaxcloudConfig`; a separate `ApiTypeResolver` would duplicate that seam. All reads of `api_type` (router, button controller, future consumers) go through `TaxcloudConfig::getApiType($store)`, alongside new accessors for the REST credentials. Same intent — one store-aware read point, one test seam — expressed in the codebase's existing pattern.

## Risks / Trade-offs

- [Router adds a delegation hop on every tax call] → Pure in-memory dispatch, no I/O; REST target injected as proxy so nothing new is instantiated on SOAP paths.
- [Data patch scans `core_config_data` directly] → Uses the standard patch pattern with `ModuleDataSetupInterface` connection; guarded to be idempotent; unit-tested with a mocked connection.
- [`depends` on `api_type` only reacts to the *form* value] → That is the desired UX (fields flip as the admin changes the select before saving); persistence is unaffected.
- [Button posts unsaved credentials over the admin session] → Same trust boundary as saving them; HTTPS admin assumed; values never logged.
- [TaxCloud could change V3 host/paths] → Endpoint stored in config with shipped default (like `wsdl_url`), overridable without a release.



## Migration Plan

Ships in a regular release. Upgrade path: `setup:upgrade` runs the data patch → existing installs pinned to `soap`, nothing else changes (router dispatches everything to SOAP anyway). Rollback: module downgrade leaves the extra config rows behind; they are inert for older code. No schema changes.

## Open Questions

- Exact ACL resource id of the tax config section (`Magento_Tax::manage_tax` vs a config-specific resource) — read from the section declaration during implementation; does not affect specs or tasks.
- Whether TaxCloud V1 `Ping` treats blank credentials as success (some legacy endpoints do) — if so, the controller's required-input validation already prevents a false positive.

