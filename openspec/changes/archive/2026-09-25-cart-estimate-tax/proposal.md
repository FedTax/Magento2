## Why

Magento's cart-page shipping estimator collects only country, region and ZIP, so the cart never shows tax: both lookup paths refuse to call TaxCloud when the address has no city (`RestGateway::lookupTaxes`, `Api::lookupTaxes`). TaxCloud rejects such an address as sent (v3 requires non-empty `line1` and `city`; SOAP requires a city), but sandbox testing shows it prices by state + ZIP (+ ZIP+4) only and ignores the street and city text. A placeholder street/city therefore yields the correct ZIP-level rate on both transports, turning an empty cart estimate into a real one without touching the storefront UI.

## What Changes

- A quote address with a valid ZIP and region but no city or no street line is treated as an **estimate address**: the lookup is sent with the fixed placeholder `ESTIMATE` in whichever of the street and city fields is missing, instead of returning zero tax. Always on; no setting.
- Applies to both transports (SOAP and v3 REST) and to both US and Canadian destinations (Canada still gated by its own enablement setting).
- Address verification is skipped for estimate destinations — a placeholder can never verify, so the call would only be wasted.
- Estimate lookups are marked as such in the gateway log.
- Order-side operations (capture, exempt re-create, refunds, cancellation reversal, order details) never use the placeholder: they keep building their destination from the order's real address and keep failing rather than inventing one.
- A cart-page tax figure is an estimate at ZIP level; in ZIPs that cross jurisdiction lines it can differ from the final tax, which is recalculated from the full, verified address at checkout.
- Merchant docs updated: the cart page now shows estimated tax once the shopper enters a ZIP; the "no tax on the cart page" guidance is replaced.

## Non-goals

- No storefront UI change (no city field added to the estimator).
- No admin setting to turn estimates off.
- No attempt to improve accuracy in split ZIPs (would need ZIP+4, which the estimator does not collect).
- No change to how or when orders are filed with TaxCloud.
- No request to TaxCloud to relax the v3 schema is part of this change (may be pursued separately).

## Capabilities

### New Capabilities
- `cart-tax-estimate`: pricing a cart from a partial destination (region + ZIP, missing street and/or city) — when an address counts as an estimate, what is sent to TaxCloud, verification skip, logging, and the guarantee that estimates never reach order-side operations.

### Modified Capabilities
- `rest-tax-operations`: the lookup pre-flight gates no longer short-circuit on a missing city; a missing street/city routes to an estimate lookup instead.

## Impact

- Code: `Model/Api.php` and `Model/Gateway/Rest/RestGateway.php` (lookup gates), a new stateless estimate-address helper under `Model/Address/`, `Observer/Sales/Address.php` (verification skip).
- No new config, DB schema, or API endpoints. No new dependencies.
- Store scoping: no new settings are read. Existing store-scoped gates (enabled, Canada enablement, verify-address, cache, fallback) continue to resolve against the quote's store; the estimate decision depends only on the address itself.
- TaxCloud traffic: cart-page estimator views now produce lookups (cached per identical request as today).
- Docs: `docs/checkout.md`, `docs/common-problems.md`, `docs/testing-your-setup.md`.
