## Context

See proposal.md — Why. Relevant current state:

- Two lookup entry points, `Model/Api.php::lookupTaxes` (SOAP) and `Model/Gateway/Rest/RestGateway.php::lookupTaxes` (v3), each run a chain of pre-flight gates (postcode → country/ZIP → region → city) and return a zero-tax result on the first failure.
- Both build a v1-shaped destination array (`Address1`/`Address2`/`City`/`State`/`Zip5`/`Zip4`, plus `Country`/`PostalCode` for Canada) via `RequestBuilder::buildLookupDestination()` / `buildCanadianDestination()`. The REST path converts it to v3 (`line1`/`city`/…) in `RestRequestBuilder::toV3Address()`.
- `buildCanadianDestination()` is shared with the order-side path (`buildDestinationFromOrder()`), so it must stay free of estimate behavior.
- `Observer/Sales/Address` listens on both `taxcloud_lookup_before` (v1 `destination`) and `taxcloud_rest_lookup_before` (v3 `items[].destination`) and verifies the destination when Verify Address is on.
- Verified against the sandbox (2026-09-25): v3 returns 422 for empty/missing `line1` or `city`; SOAP errors on a missing city but accepts an empty street; both accept `ESTIMATE`/`ESTIMATE` and return the same rate as the full address (state + ZIP5, refined by ZIP+4). Verify-address returns 400 for a placeholder address.

## Goals / Non-Goals

**Goals:**
- One definition of "estimate address" and one placeholder, shared by both transports and the verify observer.
- Order-side builders untouched.

**Non-Goals:**
- No new DI-wired collaborator (see Decision 1).
- No change to the event payload contract beyond the placeholder values appearing in it.

## Decisions

### 1. A stateless static helper, `Model/Address/EstimateAddress`

Holds `PLACEHOLDER = 'ESTIMATE'` and three static functions:
- `isPartial($address): bool` — the quote address lacks a city or a first street line (empty after trim).
- `fill(array $destination): array` — sets the v1 `Address1` and/or `City` to the placeholder where empty; everything else untouched.
- `isEstimate(array $destination): bool` — true when either the v1 (`Address1`/`City`) or v3 (`line1`/`city`) street or city equals the placeholder.

Same style as `PostalCodeParser`. *Alternative:* an injected service. Rejected — three callers (two gateways, one observer) would each need a new constructor argument, and optional constructor args are never auto-wired by Magento's OM (they silently keep the default), which is a known trap in this codebase; the helper has no state or config to inject.

### 2. Apply the placeholder in the lookup, after the destination is built

In both `lookupTaxes` methods, the "No city" gate is replaced by: build the destination as today, then if `EstimateAddress::isPartial($address)`, log and `fill()` it. *Alternative:* fill inside `buildLookupDestination()`/`buildCanadianDestination()`. Rejected — `buildCanadianDestination()` also serves orders, and keeping builders pure is what guarantees the "never reaches order-side operations" requirement structurally rather than by convention.

The remaining gates (postcode, country, Canada enablement, ZIP format, region) run first and unchanged, so an estimate is only ever attempted for an address that would otherwise be priced.

### 3. The observer recognizes estimates by the placeholder in the payload

`Observer/Sales/Address` skips verification when `EstimateAddress::isEstimate()` is true for the destination (per cart on the REST shape). *Alternative:* a side-channel flag passed in the event data. Rejected — the payload is what is actually sent; checking it directly stays correct even if another before-observer rewrites the destination, and needs no event-contract change. A real address whose street or city is literally "ESTIMATE" would merely skip verification — harmless.

### 4. Logging

An `info` entry in the lookup's operation context: "Estimate address (no street and/or city): sending a ZIP-level lookup". The observer logs its skip at `debug`.

### 5. Nothing else changes

Cache keys are derived from the post-observer payload, so estimate and full-address lookups never collide. Certificate resolution uses the state, the CO delivery fee uses the destination state, the fallback path takes the quote address directly — none depend on street/city.

## Risks / Trade-offs

- [TaxCloud behavior is undocumented; it could start validating or geocoding street/city] → the lookup would fail and take the existing failure path (fallback per store setting), affecting only cart-page estimates, never orders. Sandbox probe results are recorded above for re-checking.
- [ZIP-level estimate can differ from the final tax in ZIPs spanning jurisdictions — observed 8.15% vs 7.15% in 80020] → documented in merchant docs as an estimate; final tax comes from the verified full address at checkout.
- [More TaxCloud traffic from cart-page views] → one lookup per distinct request, cached as today.
- [Third-party `*_lookup_before` observers now see `ESTIMATE` in the destination] → noted in the developer docs (`docs/extending.md`).

## Migration Plan

None — no data or config. Deploying enables estimates; rolling back restores the zero-tax cart page.
