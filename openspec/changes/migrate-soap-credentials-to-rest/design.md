# Design: migrate-soap-credentials-to-rest

## Context

See proposal.md — Why. What the previous change (`add-api-type-setting`) already provides: `TaxcloudConfig` as the single store-aware config read point (incl. `getApiId/getApiKey/getRestApiKey/getRestConnectionId/getRestEndpoint`), `Model\Gateway\Rest\RestClient` (Curl-based, ping-only, X-API-KEY), `RestCredentials`, `PingResult`, `ConnectionTester`, and the `PinSoapApiTypeForExistingInstalls` patch. Empirical facts (memory: v1-to-v3-credential-mapping): exchange endpoint `POST {auth_host}/api/v3/auth/token` accepts `{apiLoginID, apiKey}` and returns `{access_token, access_token_validTo (~24h), …}`; the v3 tax API accepts that token as `Authorization: Bearer`; the V1 apiKey UUID is the v3 connectionID; the numeric `connection_id` in the token response is NOT URL-valid.

## Goals / Non-Goals

**Goals:**
- Upgrade-time, per-scope credential migration with loud, actionable failure.
- Bearer auth as a transparent second mode inside the REST transport, invisible to callers of `RestClient`.
- Keep every credential/token read store-aware and secret-free in logs.

**Non-Goals:**
- REST tax operations; admin UI for auth mode; storing anything durable in `rest_api_key`; deleting V1 credentials.

## Decisions

### D1. `TokenExchange` service (`Model/Gateway/Rest/TokenExchange`)
One class owns the exchange HTTP call: `exchange(RestCredentials|apiId+apiKey, $store): BearerToken`. Returns a small `BearerToken` value object `{token, validTo}`; throws a typed `TokenExchangeException` carrying outcome (rejected vs unreachable) — the patch and the transport map it differently. Uses the same `CurlFactory` + `api_timeout` conventions as `RestClient`. Endpoint from new config `tax/taxcloud_settings/rest_auth_endpoint` (default `https://taxcloudapi-appservice-core-prod.azurewebsites.net`, config-only, trailing-slash-trimmed, `TaxcloudConfig::getRestAuthEndpoint()`); path `/api/v3/auth/token` is a class constant.

### D2. Token cache (`Model/Gateway/Rest/TokenCache`)
Backed by the module's existing cache type (`Model\Cache\Type\Taxcloud`) so *System → Cache Management* flushes tokens too. Key = sha256 of (auth endpoint + apiLoginID + apiKey) — scope changes that resolve to the same pair share a token, matching TaxCloud's semantics. Value = token + validTo; entry lifetime = `validTo - now - 300s` safety margin (min 0). Cache misses/disabled cache degrade to exchange-per-request — correct, just slower.

### D3. Auth-mode resolution inside the REST transport
`RestClient` gains an internal credential-resolution step (new collaborator `Model/Gateway/Rest/AuthProvider`): for a store, produce either `XApiKeyAuth(rest_api_key)` or `BearerAuth(token from cache/exchange)` per the spec's precedence (`rest_api_key` wins; else V1 pair; else throw configuration exception). `ping()` keeps its explicit-credentials signature for the test button (entered X-API-KEY), and gains a store-resolved variant `pingForScope($store)` used when no key was entered. 401-retry-once: on 401 with a cached Bearer token, `AuthProvider` invalidates and re-exchanges, the request retries once; implemented in `RestClient` around the send, mode-agnostic (X-API-KEY 401s don't retry — nothing to refresh).
- *Alternative considered:* a decorator client per auth mode. Rejected: the router/tester would need mode awareness; one client with an auth strategy keeps callers blind, matching the gateway-routing philosophy.

### D4. Migration patch (`Setup/Patch/Data/MigrateSoapCredentialsToRest`)
Depends on `PinSoapApiTypeForExistingInstalls`. Algorithm:
1. If env `TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1` → log + return (patch NOT recorded as applied — see below).
2. Query `core_config_data` for all rows of `api_id` and `api_key`, group by (scope, scope_id); keep groups with both values non-blank.
3. Skip groups whose (scope, scope_id) already has a `rest_connection_id` row.
4. For each remaining group: `TokenExchange::exchange()`. Success → insert `rest_connection_id = api_key` at that scope. `TokenExchangeException` → throw a `LocalizedException` naming scope type + id + code (resolved via store/website repositories when possible) and the fix; scopes already written stay written (each insert commits as it goes; the patch is re-runnable and step 3 makes re-runs skip them).
5. Escape hatch semantics: Magento records any non-throwing patch as applied, so "skip now, auto-run next upgrade" is not honestly achievable through `patch_list`. Resolution: the migration logic lives in a reusable service (`Model/CredentialMigrator`); the patch is a thin shell around it whose skip path logs and returns normally, and a console command `bin/magento taxcloud:migrate-credentials` runs the identical migrator on demand (also useful to support when a merchant fixes credentials after an aborted upgrade).
6. All queries use exact scope rows (no inheritance), mirroring `PinSoapApiTypeForExistingInstalls`.

### D5. ConnectionTester in Bearer mode
`testRest()` keeps its current explicit-key path; when both the entered key and the saved `rest_api_key` are absent, it asks `RestClient` for the scope-resolved ping (`pingForScope`), which lands in Bearer mode when V1 creds exist. New failure mapping: `TokenExchangeException(rejected)` → message pointing at the API ID / API Key pair.

### D6. Naming and secrecy
Exchange request/response bodies are never logged verbatim (they contain the pair / a live token). Errors carry outcome + HTTP status only. The `BearerToken` value object's `__toString`/`__debugInfo` do not expose the token.

## Risks / Trade-offs

- [Undocumented endpoint changes or disappears] → config-overridable host; X-API-KEY mode untouched as the manual fallback; failures surface loudly in the patch and as auth failures at runtime, never silently.
- [Patch blocks deploys when TaxCloud is unreachable] → chosen deliberately per requirement ("fail loudly"); `TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1` documents the deliberate bypass for offline/CI builds; reusable `CredentialMigrator` keeps a re-run path open.
- [Token in cache storage] → same trust level as the encrypted-at-rest key material the module already stores; entry expires with the token; flushing the TaxCloud cache type purges it.
- [Exchange host hardcodes an Azure URL that differs per environment] → both hosts shipped as defaults (prod default; staging via config override), matching the woo plugin's constants.

## Migration Plan

Ships after `add-api-type-setting` (depends on its fields and patch). `setup:upgrade` runs the migration; failure aborts the upgrade with the scope-naming message; fix credentials (or set the skip variable) and re-run. Rollback: module downgrade leaves `rest_connection_id` rows behind — inert for older code.

## Open Questions

None — the skip-vs-patch_list tension is resolved by the `CredentialMigrator` service + `taxcloud:migrate-credentials` console command (D4.5), and the spec's escape-hatch requirement was aligned to that resolution.
