# Design: add-rest-tax-operations

## Context

See proposal.md for motivation. The relevant current state:

- **The seam is ready.** All call sites depend on the four gateway interfaces; di.xml binds them to `Model/Gateway/Router`, whose `restTarget()` is an explicit SOAP placeholder. Flipping that one method activates REST for `api_type = rest` stores.
- **Auth is shipped.** `Rest/RestClient` (ping only today), `Rest/AuthProvider` → `TokenExchange`/`TokenCache` (X-API-KEY or v1→v3 Bearer exchange, cached, 401-invalidate-retry-once), `RestCredentials`, endpoint config, and the `rest-bearer-auth` spec all exist.
- **The SOAP gateway is not just a transport.** `Model/Api.php` interleaves orchestration (pre-flight gates, caching, fallback, events, exempt re-create, duplicate tolerance) with SOAP calls; `Gateway/RequestBuilder` emits v1 shapes with credentials embedded in the body; `Gateway/ExemptionValidator` calls SOAP directly. Reusable as-is across transports: `Cache/ResultCache` + `Gateway/CacheKeyBuilder`, `Fallback/MagentoTaxFallback`, `ProductTicService`, `Event/GatewayEventDispatcher`, `Logging/GatewayLogger` + `LogRedactor`, `Gateway/RetryPolicy` (partially — see Decisions).
- **v3 model** (researched, sources in the change log): resource-based — `POST /tax/connections/{id}/carts` (upsert by `cartId`), `POST .../orders` (accepts supplied per-line `tax {amount, rate}`; the pattern TaxCloud's own SimpleSalesTax plugin uses for capture), `POST .../orders/refunds/{orderId}` (itemId + fractional quantity, empty items = full refund), `GET .../orders/{orderId}?expand=refunds`, `POST /tax/verify-address` (not connection-scoped), `GET /tax/exemption-certificates?customerId=`. Errors are `ErrorModel` JSON with conventional HTTP statuses.

Constraints: PHPStan level 5; unit tests must run on PHPUnit 9.5/10.5/12.5; everything store-aware; SOAP path must not change behavior at all.

## Goals / Non-Goals

**Goals:**
- REST implementations of all seven operations behind the existing interfaces, orchestration parity with SOAP (gates, cache, fallback, events, special refund flows).
- Zero diff in SOAP behavior — no edits to `Model/Api.php`, `Gateway/RequestBuilder`, `Gateway/ResponseMapper`, `Gateway/ExemptionValidator`, or the SOAP event payloads.
- One generic, auth-aware HTTP client path for all v3 calls (today's ping logic generalized, not duplicated).

**Non-Goals:**
- No shared orchestrator extraction; duplication between `Api.php` and the REST gateway is accepted by decision.
- No v3 cart→order conversion endpoint usage.
- No admin UI/config changes; no changes to credential migration or connection testing.

## Decisions

### D1. Class layout: parallel gateway under `Model/Gateway/Rest/`

New classes (all store-scoped via constructor-injected `TaxcloudConfig` + explicit store arguments, logger = config-gated `GatewayLogger` via di.xml, same pattern as existing collaborators):

| Class | Responsibility |
|---|---|
| `RestGateway` | Implements `GatewayInterface`. Per-operation orchestration: gates → cache → events → API call → response application → cache save / fallback. Mirrors `Api.php`'s decisions, minus SOAP specifics. |
| `RestRequestBuilder` | Magento entities → v3 payload arrays (cart, order, refund, verify-address). No credentials in payloads (auth is headers). Reuses `ProductTicService`, `PostalCodeParser`, `RefundDistributor`. |
| `RestResponseMapper` | v3 responses → module shapes: applies per-line `tax.amount` to the `lookupTaxes` result array (keyed by line index ↔ quote item, like `ResponseMapper::applyCartItemResponses`), maps verify-address to the `Address1/…/Zip4` contract shape, maps the order resource to the `getOrderDetails` caller shape. |
| `RestApiCaller` (evolves `RestClient`) | Generic `request($method, $path, $body, $store, $connectionScoped)` returning a `RestResponse` value object (status + decoded body + raw body for logging). Owns auth headers via `AuthProvider`, the 401-invalidate-retry-once behavior (generalizing what `pingForScope()` does today), timeouts, and message scrubbing. `ping()`/`pingForScope()` become thin wrappers — connection-test behavior unchanged. |
| `RestResponse` | Immutable status/body value object; helpers `isSuccess()`, `isRetryable()` (429/5xx), `errorDetail()` (parses `ErrorModel`). |
| `RestExemptionValidator` | v3 counterpart of `ExemptionValidator`: fetches certificates by customer, checks `states[]` coverage and enabled flag, caches per store-account (same `Cache/Type/Taxcloud` cache, key includes connection ID so accounts never share entries). |

**Alternative rejected:** extracting a transport-agnostic orchestrator used by both gateways — cleaner long-term but touches the working SOAP path; explicitly rejected by maintainer in favor of zero SOAP regression risk.

### D2. Retry: reuse `RetryPolicy` timing, REST-specific retryability

`RetryPolicy` already encapsulates attempt counts/backoff and the "non-idempotent: only retry if the request never left the building" rule. The REST path reuses it with a status-code-based retryable check (transport exception or 429/5xx retryable for idempotent calls; refunds and order creation use the non-idempotent check — connect-stage failures only). No new retry framework.

### D3. Operation mappings

- **Lookup**: `cartId = (string) $quote->getId()` — stable per quote, so v3 upsert keeps one cart per quote (v1 used the same value as `cartID`). Line items: `index` (sequential, shipping last, as SOAP), `itemId = SKU` (`'shipping'` for shipping), `price` = discounted unit price, `quantity`, `tic` via `ProductTicService`. Pre-flight gates identical to SOAP (`PostalCodeParser`, US-only, region/city present, non-empty items). Cache via `ResultCache` keyed on the post-observer v3 payload + store (v3 payloads hash differently than SOAP params — no cross-transport cache pollution by construction). On failure: `MagentoTaxFallback` when enabled, else zero result.
- **Capture**: direct `POST /orders`. `orderId = $order->getIncrementId()`, `customerId` = customer ID or configured guest ID, `transactionDate` = order `created_at` (RFC3339 UTC), `completedDate` = now (capture time — `capture_trigger` already decides *when* this method runs), line items as in lookup but priced from order items with `tax: {amount: $item->getTaxAmount() per unit basis matching price×qty, rate: tax_percent/100}`; shipping line carries the order's shipping tax. Exemption object attached when the order was exempt. Duplicate handling: a 4xx whose `ErrorModel` detail indicates the `orderId` already exists → benign success (v1 parity); exact status/wording verified in sandbox (see Open Questions) with the check written tolerantly (status + detail substring).
- **Refunds**: `POST /orders/refunds/{orderId}`. Credit memo → items with `itemId` + `quantity` (`(float)` qty; shipping refund as `{'shipping', 1}` scaled fractionally when partial: `quantity = refundedShippingAmount / originalShippingAmount`). No prices sent. Adjustment-only: `RefundDistributor` output re-expressed as `{itemId, fractional qty}` (its distributed qtys already are fractional). Tax-only: full refund (empty `items`) then re-create as exempt — direct `POST /orders` with `orderId = incrementId . '-exempt'`, `exemption: {isExempt: true}`, no cart/lookup step (v3 simplification of the SOAP dance). Skip-cases (nothing meaningful to refund) return success without an API call, as today.
- **Cancellation**: full refund — empty `items` array (also the path that keeps a CO Retail Delivery Fee line included, since TaxCloud owns the fee line).
- **Order details**: `GET /orders/{orderId}?expand=refunds`; 404 → null; non-2xx → null + logged `ErrorModel` detail; response mapped to the shape `CancellationProcessor` consumes (it currently reads SOAP `OrderDetailsResult` keys — the mapper produces those keys so the caller stays untouched).
- **Verify address**: `POST /tax/verify-address` with `line1/line2/city/state/zip`; response mapped back to `Address1/Address2/City/State/Zip5/Zip4` (zip split on `-` when v3 returns a combined zip). Cached via `ResultCache::saveAddress` as today.
- **Exemption**: `GET /tax/exemption-certificates?customerId={id}` (+ `connectionId` filter), follow cursor pagination until the certificate is found or pages end; validate `states[]` contains destination state and certificate not disabled.

### D4. Events

Same `GatewayEventDispatcher`, new names, v3-shaped payloads:

| Event pair | Fired by | Context entities |
|---|---|---|
| `taxcloud_rest_lookup_before/_after` | `lookupTaxes` | customer, address, quote, itemsByType, shippingAssignment |
| `taxcloud_rest_capture_before/_after` | `authorizeCapture` (incl. exempt re-create) | order |
| `taxcloud_rest_refund_before/_after` | `returnOrder`, `returnOrderCancellation` | order, items, creditmemo (null for cancellation) |
| `taxcloud_rest_verify_address_before/_after` | `verifyAddress` | (params only, as SOAP) |

`Observer/Sales/Address` also subscribes to `taxcloud_rest_lookup_before` (events.xml). The observer inspects the payload shape (v3 `destination.line1` vs SOAP `destination.Address1`), calls `verifyAddress` through the gateway interface (router picks the transport), and writes the verified address back in the payload's own shape. Cache lookup happens *after* the before-event on both paths, so a verified destination changes the cache key consistently (SOAP parity).

### D5. Router swap + wiring

`Router::restTarget()` returns an injected `RestGateway` (constructor gains the dependency — a proxy in di.xml so SOAP-only installs never instantiate the REST stack). di.xml additions: `RestGateway`/`RestRequestBuilder`/`RestResponseMapper`/`RestExemptionValidator` get the config-gated `GatewayLogger`; `RestExemptionValidator` gets the TaxCloud cache type. No preference changes — the interfaces stay bound to `Router`.

## Testing

**Unit (written with the change, PHPUnit 9.5/10.5/12.5-safe — no `createStub` chaining newer than 9.5, data providers static):**
- `RestRequestBuilder`: cart/order/refund/verify payloads — discounts, TIC resolution, date formats, guest customer ID, exemption attachment, fractional shipping refund quantity, no credential keys present.
- `RestResponseMapper`: per-line tax application (product + shipping, keyed items), verify-address shape incl. zip splitting, order-details shape, `ErrorModel` parsing.
- `RestGateway`: per-operation orchestration with mocked caller — every spec scenario: gates short-circuit, cache hit/miss/save, fallback on/off, event mutation honored, duplicate-capture success, tax-only → full refund + exempt order, adjustment distribution, cancellation full refund, 404 → null, store scoping (entity store ≠ ambient store).
- `RestApiCaller`: auth header selection, 401-invalidate-retry-once, 429/5xx retryable vs 4xx terminal, scrubbing.
- `RestExemptionValidator`: state coverage, disabled certs, pagination, per-account cache keys.
- `Router`: `api_type=rest` dispatches every method to `RestGateway`; `soap` still hits SOAP.
- `Observer/Sales/Address`: v3-shaped payload handling alongside existing SOAP-shape tests.

**Integration (approved and implemented):** `Test/Integration/Rest/RestLiveApiTest.php` runs against the live API in the dockerized stack — ping under real-key X-API-KEY auth (the primary mode, per project policy; `TAXCLOUD_API_V3_KEY` is a required env var seeded as `rest_api_key`), one Bearer-via-exchange connectivity check, the cart→order→refund cycle with duplicate-orderId wording and fractional quantities, verify-address shape, and the exemption-listing envelope. Live finding folded in: a 201 refund occasionally echoes `[]` (async race on TaxCloud's side) — the test falls back to reading the recorded refund via `GET …?expand=refunds`; the gateway is unaffected (success keys on the 201, never the echo).

**E2E (approved and implemented):** Playwright project layering (`rest-setup` → `checkout-rest` → `rest-teardown`) re-runs the same checkout journeys plus the credit-memo refund with the store flipped to `api_type = rest` under the seeded real key — identical golden values across the SOAP and REST passes are the interchangeability claim itself. The Bearer path keeps one basic connectivity check: the admin Test Connection button with the saved key temporarily removed (restored from `TAXCLOUD_API_V3_KEY` afterwards).

## Risks / Trade-offs

- [Duplicate-capture response undocumented] → sandbox-verify the status/detail for a re-submitted `orderId`; until verified, code treats only an explicit "already exists" signal as success and logs everything else as failure (fail-safe: an order marked failed can be re-captured; double-filing cannot be undone silently).
- [Fractional refund quantities unverified] → sandbox-verify; SST sends floats in production, so risk is low; fallback is rounding to the v1 precision (4 dp) or refusing distribution with a clear log.
- [CO RDF on full refunds] → cancellation/tax-only paths use empty-items full refunds, which should include the TaxCloud-owned RDF line; sandbox-verify. Partial refunds never touched the RDF in v1 either.
- [Cross-transport orders] An order quoted/captured over SOAP (v1-filed), then refunded after the store switches to REST, gets a 404 from v3 refunds → logged failure, no double-booking. Documented as a migration note: switch transports at a quiet moment; refunds for pre-switch orders may need the store temporarily switched back. (Same class of issue exists in reverse.)
- [Duplicate SKUs across order lines] v3 refunds are `itemId`-keyed; two lines sharing a SKU are ambiguous on refund. v1 had the same SKU-keyed behavior (`ItemID = sku`), so this is not a regression — noted, not solved.
- [Payload-shape drift in events] Third-party observers must adopt the new events; release notes carry a mapping table (old event → new event + payload shape).
- [Orchestration duplication] `RestGateway` re-expresses gates/cache/fallback logic that also lives in `Api.php` — accepted cost of the parallel-gateway decision; unit tests pin both sides to the same scenarios.

## Migration Plan

1. Ship in a regular release. On upgrade, stores already set to "V3 REST" begin transacting over v3 immediately (proposal → gateway-routing REMOVED requirement's migration note); SOAP stores see zero change.
2. Rollback = set `api_type` back to `soap` (per store) — no data migration in either direction; both transports file into the same TaxCloud account.
3. Release notes: event migration table, cross-transport refund caveat, recommendation to switch during a low-order window.

## Open Questions

All four were verified against the live v3 API on 2026-08-06, using the account configured in the Warden dev instance (production endpoint, Bearer-via-exchange auth), driven through the new `RestClient::request()` itself:

1. **Duplicate `orderId`** — answered: `400` with `ErrorModel.detail = "completed order already exists for this store"`. The tolerant detector (400/409/422 + `/already exist|duplicate/i`) matches; the exact wording is pinned in `RestGatewayTest`.
2. **Fractional refund quantities** — answered: accepted (`201`), quantities echoed as sent (0.5 / 0.4), prices derived from the filed order.
3. **CO RDF in empty-items full refunds** — partially answered: the empty-items refund provably covers *every* filed line of the order, which would include an RDF line. The RDF line itself was not observable because the account has no Colorado nexus (CO carts return zero tax and no RDF line). Residual risk is low; re-check if a CO-nexus account becomes available.
4. **Verify-address zip** — answered: combined ZIP+4 (`"06851-5775"`), so the mapper's split-on-dash produces the correct `Zip5`/`Zip4`.

Bonus confirmations from the same run: cart-response `tax.amount` is the **line-total** (CA: rate 0.08625 × $10 × qty 2 → 1.73), matching how `applyCartTax` applies it and how the capture payload supplies it; and the exemption-certificates listing envelope is `{items, limit, nextCursor}`, exactly what `RestExemptionValidator` consumes.
