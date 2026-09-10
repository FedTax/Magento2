# Tasks: add-rest-tax-operations

## 1. HTTP foundation

- [x] 1.1 Add `RestResponse` value object (status, decoded body, raw body; `isSuccess()`, `isRetryable()`, `errorDetail()` parsing `ErrorModel`) with unit tests
- [x] 1.2 Generalize `RestClient` into the `RestApiCaller` role: `request($method, $path, $body, $store, $connectionScoped)` returning `RestResponse`, auth headers via `AuthProvider`, 401-invalidate-retry-once, timeout from store config, scrubbed messages; keep `ping()`/`pingForScope()` as thin wrappers with existing behavior and tests green
- [x] 1.3 Wire REST retryability into `RetryPolicy` usage (status/transport-based check for idempotent calls; non-idempotent check reused for order/refund submission) with unit tests

## 2. Request building and response mapping

- [x] 2.1 `RestRequestBuilder::buildCartPayload()` — quote line items (index/itemId/price/quantity/tic, shipping line), origin/destination, customerId (guest fallback), cartId = quote ID, exemption attachment; unit tests incl. discounts, TIC resolution, no credential keys
- [x] 2.2 `RestRequestBuilder::buildOrderPayload()` — capture payload from a Magento order: per-line tax {amount, rate}, transactionDate from created_at, completedDate now, exemption for exempt re-create (`-exempt` orderId, isExempt); unit tests incl. date formats (RFC3339 UTC)
- [x] 2.3 `RestRequestBuilder::buildRefundItems()` — credit memo items + fractional shipping quantity, `RefundDistributor` mapping to fractional quantities, tax-only detection, skip-cases; unit tests for each branch
- [x] 2.4 `RestRequestBuilder::buildVerifyAddressPayload()` + `RestResponseMapper::mapVerifiedAddress()` (Address1/../Zip5/Zip4 contract shape, zip splitting); unit tests
- [x] 2.5 `RestResponseMapper::applyCartTax()` — per-line tax.amount application to product/shipping result keyed to quote items (parity with `ResponseMapper::applyCartItemResponses`); unit tests
- [x] 2.6 `RestResponseMapper::mapOrderDetails()` — v3 order resource (+refunds) → `OrderDetailsResult`-shaped array consumed by `CancellationProcessor`; unit tests

## 3. Gateway operations

- [x] 3.1 `RestGateway` skeleton implementing `GatewayInterface`; store-scoped logger setup; di.xml wiring (config-gated logger, cache type for validator)
- [x] 3.2 `lookupTaxes()`: pre-flight gates → exemption → before-event → cache → API call → after-event → tax application → cache save; Magento fallback on failure; unit tests for every spec scenario (gates, cache hit/miss, fallback on/off, event mutation)
- [x] 3.3 `authorizeCapture()`: order payload → before/after events → duplicate-as-success detection (tolerant status+detail check) → boolean result; unit tests incl. duplicate and failure paths
- [x] 3.4 `returnOrder()`: refund items branches (items+shipping, adjustment-only distribution, tax-only → full refund + exempt re-create via `authorizeCapture` internals, skip-case success); non-idempotent retry rule; unit tests per branch
- [x] 3.5 `returnOrderCancellation()`: empty-items full refund; unit tests
- [x] 3.6 `getOrderDetails()`: GET with expand=refunds; 404 → null, errors logged+null; unit tests
- [x] 3.7 `verifyAddress()`: cache → before/after events → API call → contract-shape result / false; unit tests
- [x] 3.8 `RestExemptionValidator` + `getValidatedCertificateID()`: fetch by customer (cursor pagination), states[] coverage, disabled filter, per-account cache keys; unit tests

## 4. Events and routing

- [x] 4.1 Dispatch `taxcloud_rest_{lookup,capture,refund,verify_address}_before/_after` with v3 payloads and SOAP-parity context entities; unit tests assert REST path fires no `taxcloud_*` SOAP events and vice versa
- [x] 4.2 Subscribe `Observer/Sales/Address` to `taxcloud_rest_lookup_before` (events.xml); observer handles v3 destination shape (line1/zip) alongside SOAP shape; unit tests for both shapes
- [x] 4.3 Swap `Router::restTarget()` to injected `RestGateway` (di.xml proxy); unit tests: `api_type=rest` dispatches all seven operations to REST, `soap` unchanged, entity-store scoping (store B entity under ambient store A)

## 5. Quality gates

- [x] 5.1 Full unit suite green locally; confirm added tests use only PHPUnit 9.5/10.5/12.5-compatible APIs (static data providers, no newer-only assertions)
- [x] 5.2 PHPStan level 5 clean (`make phpstan`) with no new baseline entries
- [x] 5.3 Update README/docs: REST operation coverage, event migration table (old `taxcloud_*` → new `taxcloud_rest_*` + payload shapes), cross-transport refund caveat, switch-timing recommendation

## 6. Sandbox verification and proposed suites

- [x] 6.1 Verify against TaxCloud sandbox: duplicate `orderId` response (pin the detection check), fractional refund quantities, CO RDF inclusion in empty-items full refunds, verify-address zip+4 shape; fold findings into code + design.md Open Questions
- [x] 6.2 Propose integration suite to maintainer (sandbox lookup→capture→refund cycle + the 6.1 checks as automated tests); implement only on confirmation
- [x] 6.3 Propose e2e run to maintainer (existing docker checkout flow with `api_type=rest` store); implement only on confirmation
