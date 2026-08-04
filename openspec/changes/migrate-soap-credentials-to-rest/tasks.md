# Tasks: migrate-soap-credentials-to-rest

## 1. Exchange foundation

- [x] 1.1 Add `rest_auth_endpoint` default (`https://taxcloudapi-appservice-core-prod.azurewebsites.net`) to `etc/config.xml` and `TaxcloudConfig::getRestAuthEndpoint()` (config-only, trim like `rest_endpoint`) + unit tests (store forwarding, fallback, trailing slash)
- [x] 1.2 Create `Model/Gateway/Rest/BearerToken` value object (`token`, `validTo`; no token leakage via `__toString`/`__debugInfo`) + unit test
- [x] 1.3 Create `Model/Gateway/Rest/TokenExchangeException` (outcome: rejected | unreachable) and `Model/Gateway/Rest/TokenExchange::exchange($apiId, $apiKey, $store): BearerToken` — POST `{auth_host}/api/v3/auth/token`, JSON body, `api_timeout`, maps 4xx → rejected, network/missing-token → unreachable/invalid + unit tests (success parse incl. validTo, 400, network error, no credentials in exception messages)

## 2. Token cache

- [x] 2.1 Create `Model/Gateway/Rest/TokenCache` on `Model\Cache\Type\Taxcloud`: key = sha256(endpoint + apiId + apiKey), lifetime = validTo − now − 300s (min 0), `get`/`save`/`invalidate` + unit tests (hit, expiry cutoff, invalidate, disabled-cache miss)

## 3. Auth-mode resolution in the REST transport

- [x] 3.1 Create `Model/Gateway/Rest/AuthProvider`: per-store resolution — `rest_api_key` set → X-API-KEY auth; else V1 pair present → Bearer auth via TokenCache/TokenExchange; else typed configuration exception; exposes `invalidate($store)` for the 401 retry + unit tests covering the precedence table and the two-stores-two-modes scenario
- [x] 3.2 Extend `RestClient`: extract request sending so headers come from an auth strategy; add `pingForScope($store): PingResult` (auth via AuthProvider); keep explicit-credentials `ping()` unchanged; Bearer 401 → invalidate + re-exchange + single retry, second 401 → AUTH_FAILED; X-API-KEY 401 unchanged (no retry) + unit tests (Bearer header sent, retry-once flow, no infinite retry, exchange rejection → AUTH_FAILED, no-credentials → local failure without HTTP)

## 4. Credential migrator + patch + CLI

- [x] 4.1 Create `Model/CredentialMigrator::migrate()` (returns migrated/skipped scope labels) — enumerate exact-scope (scope, scope_id) groups with non-blank `api_id`+`api_key`, skip groups with existing `rest_connection_id`, exchange-validate each remaining pair, insert `rest_connection_id = api_key` at that scope; on `TokenExchangeException` throw `LocalizedException` naming scope type/id/code + fix, keeping prior writes; never log credential values
- [x] 4.2 Unit-test the migrator: multi-scope migration, inheritance rows untouched, skip-existing (no HTTP for skipped), rejected pair aborts naming the scope, unreachable aborts with distinct message, prior writes kept on abort
- [x] 4.3 Create `Setup/Patch/Data/MigrateSoapCredentialsToRest` (depends on `PinSoapApiTypeForExistingInstalls`): `TAXCLOUD_SKIP_CREDENTIAL_MIGRATION=1` → log + return; else run the migrator + unit tests (skip path writes nothing and logs, normal path delegates, dependency declared)
- [x] 4.4 Create console command `taxcloud:migrate-credentials` (`Console/Command/`, di.xml command list entry) running the same migrator with per-scope output and non-zero exit on failure + unit test (delegation, output, exit codes). Note: `Console/` is a new top-level dir — running stacks need a manual `ln -s` (fresh installs pick it up automatically)

## 5. Connection test in Bearer mode

- [x] 5.1 Extend `ConnectionTester::testRest()`: entered key → explicit X-API-KEY path (unchanged); no entered key and no saved key → `RestClient::pingForScope()` (entered Connection ID passed as override); map exchange-rejected → "check API ID / API Key pair" message + unit tests (Bearer-mode success, exchange-rejected message, entered-key precedence, config-error surfacing)

## 6. Quality gates & proposals

- [x] 6.1 Verify all new test code against the PHPUnit 9.5/10.5/12.5 matrix conventions; run `make lint` — clean (one annotated phpcs ignore for the deliberate getenv hatch)
- [x] 6.2 Run `make phpstan` and the full unit suite; no new baseline entries — phpstan OK, 567 unit tests green
- [ ] 6.3 Propose to the maintainer: integration test for `CredentialMigrator` against real `core_config_data` (mock exchange via configurable `rest_auth_endpoint` pointed at a local stub), and an e2e admin test of Bearer-mode Test Connection — await confirmation before writing either
- [x] 6.4 Update CHANGELOG.md (Unreleased: credential migration, Bearer auth mode, CLI command, skip variable)
