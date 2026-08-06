# Add REST Tax Operations

## Why

The module can already select "V3 REST" per store (`api_type`), authenticate against the v3 API (X-API-KEY or exchanged Bearer token), and test the connection — but every tax operation still runs over the legacy SOAP v1 API, which TaxCloud is deprecating. The gateway seam built for this migration (`GatewayInterface` + store-aware `Router`) is in place with an explicit placeholder: `Router::restTarget()` returns the SOAP gateway. This change fills in the REST side so SOAP and REST become genuinely interchangeable transports and REST-selected stores actually transact over the v3 API.

## What Changes

- New `RestGateway` implementing all four gateway contracts (`LookupGatewayInterface`, `OrderGatewayInterface`, `AddressGatewayInterface`, `ExemptionGatewayInterface`) against the TaxCloud v3 REST API, as a **parallel implementation**: the SOAP path (`Model/Api.php`) is untouched, and transport-agnostic collaborators (result cache, Magento-rates fallback, TIC resolution, config-gated logging, retry semantics, existing REST auth stack) are reused.
- Operation mapping (v1 verb model → v3 resource model):
  - `lookupTaxes` → `POST /tax/connections/{id}/carts` (upsert keyed by `cartId` = quote ID; per-line `tax.amount`/`tax.rate` responses applied to Magento totals; result caching and Magento-fallback behavior preserved)
  - `authorizeCapture` → direct `POST /tax/connections/{id}/orders` built from the Magento order, supplying per-line tax from the order's stored amounts (the pattern proven by TaxCloud's SimpleSalesTax reference integration); `completedDate` set at capture time per `capture_trigger`; duplicate submissions treated as benign success
  - `returnOrder` / `returnOrderCancellation` → `POST /tax/connections/{id}/orders/refunds/{orderId}` with `itemId` + fractional `quantity` (full refund = empty items); adjustment-only distributions map amounts to fractional quantities; tax-only refunds become full refund + direct exempt order re-create (no lookup dance needed in v3)
  - `getOrderDetails` → `GET /tax/connections/{id}/orders/{orderId}?expand=refunds` (clean 404 = not found)
  - `verifyAddress` → `POST /tax/verify-address` (not connection-scoped; address caching preserved)
  - `getValidatedCertificateID` → `GET /tax/exemption-certificates?customerId=…`, validating state coverage from the certificate's `states[]`; validated certs applied to carts/orders via the v3 `exemption` object; cert-list caching preserved
- New `taxcloud_rest_*` before/after events with v3-shaped payloads on the REST path. Existing `taxcloud_*` events remain SOAP-only and unchanged; the in-module address-verification observer additionally subscribes to the new REST lookup-before event so in-quote address verification works on both transports.
- `Router::restTarget()` switched from the SOAP placeholder to the new `RestGateway` — REST-selected stores now transact over v3 end to end. This retires the "SOAP-only dispatch until REST operations exist" transition requirement.
- REST error/retry handling: v3 `ErrorModel` responses and HTTP statuses mapped onto the existing outcome semantics (retry timeouts/5xx/429, never retry 4xx, non-idempotent refunds only retried when the request never reached TaxCloud).

## Capabilities

### New Capabilities

- `rest-tax-operations`: the seven gateway operations executed over the v3 REST API — request construction, response mapping, error/retry behavior, caching, fallback, and parity guarantees with the SOAP implementations.
- `rest-gateway-events`: the `taxcloud_rest_*` before/after extension events dispatched around REST operations with v3-shaped payloads, including the address-verification hook into the REST lookup.

### Modified Capabilities

- `gateway-routing`: the "SOAP-only dispatch until REST operations exist" requirement is replaced — `api_type = rest` now dispatches every gateway operation to the REST implementation (still resolved against the entity's store, never the ambient one); `api_type = soap` behavior is unchanged.

## Impact

- **Code**: new `Model/Gateway/Rest/` classes (gateway, request builder, response mapper, error mapping); `Model/Gateway/Router.php` (target swap); `etc/di.xml` (wiring); `etc/events.xml` (address observer on the REST lookup event); `Observer/Sales/Address.php` (handle v3-shaped payload). SOAP classes untouched.
- **Store scoping**: every REST call resolves credentials, endpoint, auth mode, TICs, cache, fallback, and logging against the store of the entity in hand (quote/order/creditmemo store ID, or the explicit `$store` argument) — never the ambient store, matching module policy.
- **Behavioral differences on REST stores** (documented, opt-in via `api_type`): refund amounts are derived by TaxCloud from the filed order (we send quantities, not prices); existing `taxcloud_*` events do not fire (third-party observers must move to the `taxcloud_rest_*` events); order details come from the v3 order resource.
- **Dependencies**: no new packages — uses the existing Curl-based `RestClient`/`AuthProvider` stack.
- **Tests**: unit tests for all new classes and the router swap (PHPUnit 9.5/10.5/12.5-compatible). Integration/e2e coverage proposed separately for maintainer sign-off.
- **Open items to verify against the sandbox during implementation**: v3 behavior on duplicate `orderId` submission, fractional-quantity refunds, CO Retail Delivery Fee inclusion in full refunds, and `zip4` presence in verify-address responses.

## Non-goals

- No refactor of the SOAP gateway (`Model/Api.php`) or extraction of a shared orchestrator — parallel implementation by design.
- No partial capture: capture remains whole-order in both transports (`capture_trigger` controls when, not how much).
- No backward-compatible SOAP-shaped payloads on the new REST events, and no removal/deprecation of the existing SOAP events.
- No changes to credential migration, connection testing, or `api_type` configuration (already shipped).
- No new admin configuration.
- No use of the v3 cart→order conversion endpoint (`POST /carts/orders`) — capture always creates orders directly.
