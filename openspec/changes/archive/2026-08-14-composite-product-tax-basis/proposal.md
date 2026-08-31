# Composite Product Tax Basis

## Why

A dynamic-price bundle is taxed on a fraction of its value, and reported to
TaxCloud at more than its value — at the same time.

Magento stores a bundle selection's quantity **per bundle** (one unit inside a
qty-2 bundle is stored as `1`) while its row total is already parent-multiplied
(`$20` for a `$10` selection). Core reconciles the two in
`TaxCalculation::getTotalQuantity()`. The lookup request never did: it sent the
stored `1`, so TaxCloud priced one unit of goods the shopper was charged for two
of. A qty-3 bundle was taxed as one of three.

Separately, the lookup sent both the bundle wrapper *and* its selections, while
Magento deliberately excludes children-calculated parents from its own address
totals. Observed on order `SO000000039`: TaxCloud was told about `$70` of goods
in a `$50` cart, `$7.03` of tax was recorded against it, and the shopper paid
`$3.73`. `AuthorizedWithCapture` books the whole lookup by cart id, so the
over-report is what gets filed.

The three payloads for one order disagreed in a third way, too. The lookup, the
capture and the refund each build their line list from a different source — the
quote, the order's visible items, the credit memo's items — and each stores a
composite differently. On v3 that disagreement is not a rounding difference:
refunds reference an order's `itemId`s by name, so a bundle filed under the
wrapper's id can be sold and then never cleanly refunded.

## What Changes

- One rule, in one place: a children-calculated parent is represented by its
  selections, never by both; a parent-priced composite by its parent, never by
  its zero-priced children. Quote-side child quantities are multiplied by the
  parent's; order-side ones already are and must not be multiplied again.
- The rule is applied to every payload builder so all of them describe the same
  goods: v1 lookup, v1 return, v1 cancel/exempt re-create, and the v3 order
  payload (the v3 cart and refund builders already delegate to the v1 ones).
- The tax collector spreads a line's tax over the quantity the row total was
  built from, and shows a wrapper's tax as the sum of its selections' — the only
  amount it can honestly carry, since it is never sent.
- The Magento tax fallback gets the same treatment: it builds a flat detail list
  with no parent codes, so core's own quantity rule cannot apply for it.
- The seeded test catalog gains one product per remaining Magento type
  (virtual, downloadable, grouped, dynamic bundle, fixed bundle, and gift card
  on Adobe Commerce), plus a script that fails an install when any seeded
  product cannot be added to a cart.
- Integration and E2E run on **every** pull request, not only those into `main`.

## Capabilities

### New Capabilities

- `composite-product-tax`: how a composite product's quote and order lines map
  onto the goods reported to TaxCloud — which line carries the taxable basis,
  what quantity it carries, and the requirement that the lookup, capture and
  refund payloads for one order name the same items.

### Modified Capabilities

- `rest-tax-operations`: the order-filing requirement gains the constraint that
  a v3 order is filed under the same item references its cart was, so refunds
  (which address an order by `itemId`) can resolve every line.

## Impact

- **Code**: new `Model/CompositeItemResolver`; `Model/Gateway/RequestBuilder`
  (lookup, return, cancel builders); `Model/Tax`; `Model/Fallback/
  MagentoTaxFallback`; `Model/Gateway/Rest/RestRequestBuilder::buildOrderPayload()`.
  No interface or wiring changes; no new dependencies (the resolver is a static
  helper, following `PostalCodeParser`).
- **Store scoping**: unchanged. Every value already resolves from the entity
  being processed — the quote for a lookup, the order for a capture, the credit
  memo's order for a refund — and the resolver reads only item structure, no
  configuration.
- **Behaviour change on existing data**: orders placed with a dynamic-price
  bundle before this change were charged and reported incorrectly. Updating does
  not correct them; they need adjusting in TaxCloud.
- **Tests**: unit throughout (per-type cart-line tables for all eight catalog
  shapes, on both the quote and order sides, plus the v3 order payload).
  Integration and E2E coverage was proposed to and confirmed by the maintainer
  before being written: per-type lookup coverage, a lifecycle matrix (capture,
  cancel, full and partial refund), cross-cutting invariants, v3 payload
  agreement, and five storefront journeys that run over both transports.
- **Tooling**: `scripts/seed-test-data.php` gains `--products-only` and
  `--recreate[=sku,…]`; new `scripts/verify-test-products.php` runs as an
  install gate.

## Non-goals

- **Gift wrapping and fixed product tax (FPT) are still not sent to TaxCloud.**
  They arrive as Magento "extra taxables" and `processExtraTaxables()` fills
  them from Magento's native tax tables — usually `$0`. Deliberately left
  uncovered rather than pinned by a test, because a test here would encode
  behaviour that looks wrong. Worth its own change.
- No gift card lifecycle coverage. The module only ships with Adobe Commerce, so
  a row would skip on every Open Source run; its tax behaviour remains verified
  against mocks and a manual catalog check only.
- No change to partial capture — capture stays whole-order on both transports.
- No change to how TICs are resolved for composite children.
- No attempt to reconcile already-filed orders automatically.

## Notes

This supersedes a Non-goal recorded in
`2026-08-07-fix-rest-refund-duplicate-line-items`, which stated that capture-side
line building needed no change because "`getAllVisibleItems` already files
composite parents only, one line per SKU". Filing composite parents is exactly
what diverges from the cart for a dynamic-price bundle; that change's grouping
workaround remains correct and necessary for genuinely distinct order lines that
share a SKU.
