# Proposal: add-api-type-setting

## Why

The module currently talks to TaxCloud exclusively through the legacy V1 SOAP API, authenticated with `api_id` + `api_key`. TaxCloud's v3 REST API is the strategic direction (migration already underway) and authenticates differently — an API Key sent as an `X-API-KEY` header plus a Connection ID (UUID) in the URL path. Before any calculation/capture traffic can move to REST, merchants need a way to choose the API generation and enter the right credentials, with a way to verify them before saving.

## What Changes

- New admin setting **API Type** (`tax/taxcloud_settings/api_type`) with two options: `soap` — "V1 SOAP (legacy)" and `rest` — "V3 REST".
- Defaulting rule: fresh installs default to **V3 REST** (config.xml default); existing installs (any saved `api_id` in `core_config_data`, in any scope) are pinned to **V1 SOAP** by a data patch so upgrades don't silently change behavior.
- Existing `api_id` and `api_key` fields become visible only when API Type = V1 SOAP.
- New V3 credential fields, visible only when API Type = V3 REST:
  - **API Key** (`rest_api_key`) — sent as `X-API-KEY`; generated in TaxCloud dashboard under Developer → API. Stored encrypted (obscure).
  - **Connection ID** (`rest_connection_id`) — UUID from Integrations → Custom API.
- New **Test Connection** button, available for both API types:
  - V3 REST: calls `GET /tax/connections/{connectionID}/ping` with the entered credentials and reports success / invalid key (401) / unknown connection (404) inline, without requiring a save first.
  - V1 SOAP: calls the V1 SOAP `Ping` operation with the entered `api_id`/`api_key` and reports success/failure inline the same way.
- A **minimal V3 REST client**: connection/ping only for now — no tax operations. It establishes the HTTP/auth plumbing (base URL, `X-API-KEY` header, connection-scoped paths) that later migration changes will extend.
- **Transport interchangeability**: a store-aware routing gateway becomes the di.xml preference for the existing gateway interfaces (`Lookup/Order/Address/ExemptionGatewayInterface`) and dispatches each call according to the `api_type` effective for the store of the entity being processed. Consumers remain transport-unaware. While REST tax operations don't exist yet, both branches dispatch to the SOAP implementation — zero behavior change in this release.

**Store-scoping implications:** all new fields are `showInDefault/Website/Store` like the existing credential fields. The test-connection endpoint must resolve credentials for the scope currently being edited in admin (including values inherited from website/default), not the ambient store. Any future consumer of `api_type` must resolve it against the store of the entity being processed.

## Capabilities

### New Capabilities
- `api-type-config`: selection of the TaxCloud API generation (V1 SOAP vs V3 REST), per-scope credential fields for each generation, conditional field visibility, and install/upgrade defaulting rules.
- `connection-test`: admin-initiated verification of the active API type's credentials — V3 REST via the ping endpoint, V1 SOAP via the SOAP Ping operation — scope-aware, with distinct outcomes per failure mode.
- `gateway-routing`: dispatch of TaxCloud gateway operations to the transport implementation selected by the store-scoped `api_type`, keeping all call sites transport-unaware; SOAP-only dispatch until REST operations are implemented.

### Modified Capabilities
<!-- none — no main specs exist yet; SOAP behavior is unchanged -->

## Impact

- `etc/adminhtml/system.xml` — new fields, `depends` visibility, button field with frontend model.
- `etc/config.xml` — default `api_type` = `rest`.
- `etc/di.xml` — possible new service class wiring for the ping client.
- New `Setup/Patch/Data` patch — pin `api_type` = `soap` where `api_id` exists (per scope).
- New source model (`Model/Config/Source/ApiType`), admin controller + `Block` for the test button, and a minimal REST client (ping only) built on Magento's HTTP client abstractions.
- New routing gateway class replacing `Model\Api` as the di.xml preference for the gateway interfaces — call sites in `Model/`, `Observer/`, `Plugin/` remain untouched.
- ACL: test-connection controller must sit under the existing config ACL.
- Tests: unit tests for source model, patch logic, ping client/controller (PHPUnit 9.5/10.5/12.5-compatible). Integration/e2e coverage to be proposed separately before writing.

## Non-goals

- No switching of Lookup/Capture/Returned/VerifyAddress traffic to REST — calculation and capture continue over SOAP regardless of this setting until later changes consume `api_type`.
- No removal or deprecation of `api_id`/`api_key`/`wsdl_url`.
- No V3 REST tax operations: the REST client ships with connection/ping capability only.
- No credential migration or mapping between V1 and V3 credentials.
