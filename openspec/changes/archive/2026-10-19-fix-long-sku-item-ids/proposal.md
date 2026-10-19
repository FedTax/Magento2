## Why

Every cart line the extension sends to TaxCloud names its goods by the Magento SKU, sent unchanged as the item ID. Both TaxCloud APIs cap that ID at 50 characters and reject the **whole** request when any line exceeds it. v1 SOAP answers `ItemID execeeds 50 characters (...) for Cart Item N`, and v3 REST answers HTTP 422 `itemId: expected length <= 50`. Both were reproduced live against the sandbox on 2026-10-19 with the merchant's SKU. v3's published OpenAPI document sets `maxLength: 50` on cart and refund items.

Magento builds long SKUs on its own. A bundle with **Dynamic SKU** appends every selection's SKU to its own, and a product with custom options appends each option's SKU. A merchant whose shelf planner generates bundles (`planner_bundle-clos-it-217000-850573-…`, 90 characters) cannot calculate tax for any cart holding one. Order and refund testing for their US launch is blocked.

Separately, an adjustment-only credit memo distributes its amount over the order's remaining **top-level** items. For a dynamic-price bundle, that names the bundle wrapper, which neither the lookup, the capture nor any other refund ever reports. This breaks the `composite-product-tax` rule that every payload for an order names the same lines. It is also one more place a long dynamic-bundle SKU reaches TaxCloud.

## What Changes

- **One rule decides a line's item ID.** A SKU of 50 characters or fewer is sent unchanged. A longer SKU is sent as its first 39 characters, `~`, and the first 10 hex characters of the SHA-1 of the SKU, which is exactly 50 characters. The ID is derived from the SKU alone, which Magento copies verbatim from the quote item to the order item, so lookup, capture, return, distributed refund and exempt re-create all compute the same ID without storing anything.
- All five places that name a product line use that rule:
  - the lookup cart
  - the credit-memo return
  - the full-order return / exempt re-create cart
  - the adjustment-only distribution
  - the v3 order payload

  The `shipping` and Colorado Retail Delivery Fee sentinels are unaffected.
- **The adjustment-only distribution names the lines the order filed.** It resolves each top-level item through `CompositeItemResolver::orderBasisItems()`, so a dynamic-price bundle is distributed over its selections, like every other payload.
- Merchant docs: `docs/checkout.md` states how SKUs over 50 characters appear in TaxCloud.
- Released as **1.6.1** (from `v1.6.0`).

## Non-goals

- Raising or removing TaxCloud's 50-character limit. It is enforced by the API on both transports.
- Shortening or otherwise changing SKUs in Magento.
- Storing a mapping between SKUs and item IDs on quote, order or credit-memo items. The derivation is deterministic, so there is nothing to store.
- Correcting orders placed before the release. A line with a long SKU never reached TaxCloud (its lookup was rejected), so no TaxCloud record carries an ID that could now fail to match.
- Disambiguating two lines with the **same** SKU in one cart. This is unchanged from today, where both APIs pair the item ID with the line index.

## Capabilities

### New Capabilities

- `cart-item-ids`: how a cart line's TaxCloud item ID is derived from its SKU, and the guarantee that every payload for one order derives it the same way.

### Modified Capabilities

- `composite-product-tax`: the "Lookup, capture and refund describe the same order" requirement extends explicitly to adjustment-only refunds.

## Store scoping

No settings are added or read. The item ID is a pure function of the line's SKU, so it is identical in every store and scope.

## Impact

- New `Model/ItemIdResolver.php`, a static pure function in the style of `CompositeItemResolver`, with no DI wiring.
- `Model/Gateway/RequestBuilder.php`: `buildLookupCartItems()`, `buildReturnCartItems()`, `buildCartItemsFromOrder()`.
- `Model/Gateway/Rest/RestRequestBuilder.php`: `buildOrderPayload()`.
- `Model/RefundDistributor.php`: `buildRemainingItems()`, for both the item ID and composite resolution.
- Unit tests for the resolver and every builder, plus an integration test placing an order for a long-SKU bundle.
- `docs/checkout.md`, `CHANGELOG.md` (1.6.1), and the version in `composer.json` and `etc/module.xml`.
- No API, schema or configuration changes. Only the usual `setup:upgrade` after a version bump is needed.
