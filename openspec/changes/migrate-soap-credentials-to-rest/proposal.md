# Proposal: migrate-soap-credentials-to-rest

## Why

Merchants upgrading with working V1 SOAP credentials should come out the other side with a working V3 REST configuration, not a half-filled form. Research against TaxCloud's live API (2026-08-03) established what is derivable: the V1 `api_key` UUID **is** the v3 Connection ID (verified via ping and the mgmt API), but a durable v3 `X-API-KEY` cannot be created programmatically — only expiring (~24 h) Bearer tokens are obtainable, via the same undocumented exchange endpoint TaxCloud's own WooCommerce plugin uses in production. Filling the Connection ID at upgrade and adding a Bearer-token auth mode gives migrated merchants a fully functional REST connection with zero portal action.

## What Changes

- **Credential-migration data patch** (multi-store): for every `core_config_data` scope (default / website / store) holding a V1 `api_id` + `api_key` pair, validate the pair against the exchange endpoint (`POST {auth_host}/api/v3/auth/token`) and persist `rest_connection_id` = the V1 `api_key` **at the same scope**. An existing `rest_connection_id` is never overwritten.
- **Fail loudly**: an exchange rejection (invalid pair) or an unreachable endpoint aborts `setup:upgrade` with a message naming the scope and the fix, so the merchant corrects credentials rather than discovering a dead integration later. An environment-variable escape hatch lets an operator skip the migration deliberately, and a `bin/magento taxcloud:migrate-credentials` console command re-runs the identical migration on demand.
- **Bearer-token auth mode** for the REST transport: when a scope has no `rest_api_key` but has V1 credentials, REST requests authenticate with `Authorization: Bearer <token>` obtained from the exchange endpoint (token cached until its `access_token_validTo`, refreshed on expiry, retried once on 401). When `rest_api_key` is saved, `X-API-KEY` mode wins. Nothing durable is written into `rest_api_key`.
- **Test Connection in Bearer mode**: with V3 REST selected and only migrated credentials present, the button verifies via Bearer auth and succeeds.
- New config-only endpoints (like `rest_endpoint`): `rest_auth_endpoint` defaulting to the production exchange host, overridable for staging.

**Store-scoping implications:** the patch operates per scope row and writes at that exact scope; auth-mode selection (X-API-KEY vs Bearer) and token caching resolve against the store of the entity being processed, never the ambient store. Two stores may run different auth modes side by side.

## Capabilities

### New Capabilities
- `credential-migration`: upgrade-time transform of V1 SOAP credentials into a validated V3 REST configuration, per scope, with loud, actionable failure.
- `rest-bearer-auth`: runtime Bearer-token authentication for the v3 REST transport — exchange, per-scope caching, expiry/401 refresh, and store-aware selection between X-API-KEY and Bearer modes.

### Modified Capabilities
- `connection-test` *(delta on the capability introduced by the in-flight `add-api-type-setting` change — ADDED requirement, since no main spec exists yet)*: the Test Connection button must also verify scopes that authenticate in Bearer mode.

## Impact

- New `Setup/Patch/Data` migration patch (depends on `PinSoapApiTypeForExistingInstalls`).
- `Model/Gateway/Rest/`: token exchange client, token cache (module cache type), auth-mode resolution feeding `RestClient`; `RestClient` request signing gains Bearer support.
- `Model/Config/TaxcloudConfig`: accessor for `rest_auth_endpoint`; `etc/config.xml` default.
- `Model/Gateway/ConnectionTester`: Bearer-capable dispatch for the REST test.
- Depends on the undocumented `taxcloudapi-appservice-core-prod.azurewebsites.net` exchange endpoint (risk handled in design).
- Tests: unit throughout (9.5/10.5/12.5 matrix); integration/e2e proposed separately per test policy.

## Non-goals

- No REST tax operations (lookup/capture/return) — the gateway router still dispatches tax traffic to SOAP; Bearer mode serves the connection test now and future REST operations later.
- No automatic generation of a durable `X-API-KEY` (impossible without portal action — verified).
- No removal of V1 credentials after migration; SOAP keeps working unchanged.
- No UI for the auth mode — it is derived from which credentials exist, not chosen by the admin.
