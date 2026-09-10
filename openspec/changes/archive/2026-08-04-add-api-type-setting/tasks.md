# Tasks: add-api-type-setting

## 1. API Type setting foundation

- [x] 1.1 Create `Model/Config/Source/ApiType` source model (`soap` → "V1 SOAP (legacy)", `rest` → "V3 REST") + unit test
- [x] 1.2 Add `api_type` select field to `etc/adminhtml/system.xml` (sortOrder between `verify_address` and `api_id`, depends on `enabled`, all three scopes)
- [x] 1.3 Add `<api_type>rest</api_type>` default to `etc/config.xml`
- [x] 1.4 Add `getApiType()` to `Model/Config/TaxcloudConfig` (the module's existing single config read point — supersedes the separate ApiTypeResolver from design D6) + unit tests covering store-B-vs-ambient resolution and unknown-value collapse to REST

## 2. Upgrade defaulting patch

- [x] 2.1 Create `Setup/Patch/Data/PinSoapApiTypeForExistingInstalls`: insert `api_type = soap` at default scope iff any `tax/taxcloud_settings/api_id` row exists and no `api_type` row exists (design D1)
- [x] 2.2 Unit-test the patch: pins on existing `api_id`, no-ops on fresh install, no-ops when `api_type` already saved (idempotent re-run)

## 3. Credential fields

- [x] 3.1 Gate `api_id` and `api_key` fields on `api_type = soap` via `<depends>` (keeping the `enabled` dependency)
- [x] 3.2 Add `rest_api_key` field with `Magento\Config\Model\Config\Backend\Encrypted` backend, required, comment pointing at Developer → API, depends on `api_type = rest`
- [x] 3.3 Add `rest_connection_id` field (text, required, UUID-format hint, comment pointing at Integrations → Custom API), depends on `api_type = rest`
- [x] 3.4 Add hidden `rest_endpoint` config default `https://api.v3.taxcloud.com` to `etc/config.xml` (no system.xml field; design D4) + `TaxcloudConfig` accessors for all three REST values (key decrypted via optional `EncryptorInterface`)

## 4. Minimal REST client

- [x] 4.1 Create `RestCredentials` value object and `PingResult` (success / auth-failed / connection-unknown / transport-error + reason)
- [x] 4.2 Create `Model/Gateway/Rest/RestClient` with `ping()`: Curl-based GET to `{endpoint}/tax/connections/{id}/ping`, `X-API-KEY` + `Accept: application/json` headers, `api_timeout` respected, status-code mapping per spec (200-ok/401/404/other)
- [x] 4.3 Unit-test `RestClient::ping` for all four outcome mappings and header/URL construction (no credential values in exceptions/logs)

## 5. SOAP ping

- [x] 5.1 Add a `ping(apiLoginID, apiKey)` capability on the SOAP side via the existing SOAP client provider (bypasses cache/retry; interprets `PingRsp` response envelope) — `Model/Gateway/Soap/SoapPing`, sharing a transport-neutral `Model/Gateway/PingResult`
- [x] 5.2 Unit-test SOAP ping success and failure envelope interpretation

## 6. Test Connection button

- [x] 6.1 Create `etc/adminhtml/routes.xml` for route `taxcloud` and button frontend-model block + template (button + inline result area, depends only on `enabled`)
- [x] 6.2 Button JS: collect current form values (`api_type`, both credential sets) + edited scope params, POST with form key, render success/failure message inline
- [x] 6.3 Create `Controller/Adminhtml/Connection/Test` (thin shell, ACL `Magento_Tax::config_tax`) delegating to new `Model/Gateway/ConnectionTester`: input validation short-circuit, saved-config fallback for blank/obscured values resolved for the edited scope, dispatch to REST or SOAP ping, JSON `{success, message}` per spec messages
- [x] 6.4 Unit-test the connection-test workflow (`ConnectionTesterTest`): validation short-circuit (no outbound call), obscured-key fallback, per-type dispatch, 401/404/transport message mapping, no credentials echoed, website/store scope resolution

## 7. Store-aware gateway router

- [x] 7.1 Create `Model/Gateway/Router` implementing `Api\GatewayInterface`: derive store from the passed entity/`$store` arg, resolve via `TaxcloudConfig::getApiType`, delegate — both branches target `Model\Api` for tax ops this release (design D3 transitional rule, marked as pending migration)
- [x] 7.2 Update `etc/di.xml`: point the aggregate + four narrow interface preferences at the router (REST side not injected yet — no REST gateway class exists until the migration adds operations)
- [x] 7.3 Unit-test the router: store-B-entity-under-ambient-store-A dispatch, `rest`-selected store still reaching SOAP for every operation, all `GatewayInterface` methods delegated + di.xml preference wiring

## 8. Quality gates & proposals

- [x] 8.1 Verify all new test code against the PHPUnit 9.5/10.5/12.5 matrix conventions (no 12.5-only APIs; local runner is 12.5) — dual `@dataProvider`/`#[DataProvider]`, static providers, existing double pattern; `make lint` clean
- [x] 8.2 Run `make phpstan` (level 5) and the full unit suite; fix or justify any findings (no new baseline entries) — phpstan OK, 517 unit tests green
- [x] 8.3 Propose to the maintainer: integration test for the data patch against a real DB, and an e2e admin test of the Test Connection button — both approved 2026-08-03
- [x] 8.4 Update CHANGELOG.md

## 9. Maintainer-approved integration & e2e coverage

- [x] 9.1 Integration test `Test/Integration/Setup/Patch/Data/PinSoapApiTypeForExistingInstallsTest`: pins on default- and store-scoped `api_id`, fresh-install no-op, blank-row no-op, saved-choice preserved — against real `core_config_data`; full integration suite green (49 tests; router added to the SOAP-mock eviction list)
- [x] 9.2 E2E spec `admin-api-type-setting.spec.ts` + `TaxConfigPage` extensions: API Type flips credential-field visibility; Test Connection returns SOAP sandbox success and REST validation short-circuit (green; fixes en route: seed `api_type=soap` in seed-test-data.php, `dev/template/allow_symlink 1` in install script for the module's first .phtml)
