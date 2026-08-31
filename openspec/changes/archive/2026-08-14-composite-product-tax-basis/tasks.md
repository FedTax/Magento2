## 1. The composite rule

- [x] 1.1 Add `Model/CompositeItemResolver` — a static helper in the style of `PostalCodeParser`, so no constructor wiring changes and nothing for a plugin to intercept (add the phpcs `StaticFunction` exclusion alongside the existing two)
- [x] 1.2 `isQuoteParentPricedByChildren()` — children present **and** `isChildrenCalculated()`, in that order: a childless line is never suppressed whatever it claims, which is what keeps a bundle whose children failed to load from being silently untaxed
- [x] 1.3 `quoteQty()` — child quantity multiplied by the parent's, mirroring `TaxCalculation::getTotalQuantity()`
- [x] 1.4 `isOrderParentPricedByChildren()` — reads `product_options['product_calculations']` persisted at placement, so an order stays correct after the product's price type is edited in the catalog
- [x] 1.5 `isOrderChildWithoutBasis()` — the mirror case: a child of a parent-priced composite, which the lookup never sent
- [x] 1.6 `orderBasisItems()` — a top-level order item resolved to the lines carrying its basis, falling back to the item itself when a children-priced parent has no children linked (an over-report is recoverable; a silently untaxed line is not)

## 2. Apply it to every payload

- [x] 2.1 `RequestBuilder::buildLookupCartItems()` — skip the children-priced wrapper, report selections at their effective quantity, apportion the discount over that same quantity (also removes an unguarded division by zero)
- [x] 2.2 `RequestBuilder::buildCartItemsFromOrder()` — expand a children-priced parent into its selections for cancel and exempt re-create
- [x] 2.3 `RequestBuilder::buildReturnCartItems()` — skip both the children-priced wrapper and the zero-basis children of a parent-priced composite; do **not** multiply order-side quantities
- [x] 2.4 `RestRequestBuilder::buildOrderPayload()` — resolve composites the same way, so a v3 order is filed under the identifiers its cart used (the v3 cart and refund builders delegate to the v1 builder and inherit the rule)
- [x] 2.5 Confirm the v3 refund's duplicate-reference grouping from `2026-08-07-fix-rest-refund-duplicate-line-items` still holds — it remains necessary for genuinely distinct order lines sharing a SKU

## 3. Tax collector and fallback

- [x] 3.1 `Model\Tax::collect()` — derive the per-unit tax from the effective quantity, and key the pre-tax snapshot on it too
- [x] 3.2 A children-priced wrapper's tax is the sum of its selections', and is excluded from the product tax total the defensive safeguard adds (core excludes it from address totals for the same reason)
- [x] 3.3 Annotate `$keyedAddressItems` as `AbstractItem[]` the way core does, so the composite accessors type-check; retire the 15 PHPStan baseline entries this makes obsolete
- [x] 3.4 `MagentoTaxFallback` — same skip and same quantity resolution; its detail list carries no parent codes, so Magento's own reconciliation cannot apply for it

## 4. Unit tests

- [x] 4.1 `CompositeItemResolverTest` — the rule itself, including the guard-order case that protects grouped, virtual, downloadable and gift card lines from ever being suppressed
- [x] 4.2 `CartLineShapesTest` — one table row per catalog type (simple, virtual, downloadable, configurable, grouped, dynamic bundle, fixed bundle, gift card), asserted on the quote side, the order side and the refund side, plus an explicit assertion that the quote and order descriptions agree
- [x] 4.3 Extend `RequestBuilderTest`, `TaxTest` and `MagentoTaxFallbackTest` for the bundle cases and the zero-quantity guard
- [x] 4.4 `RestRequestBuilderTest` — a dynamic bundle files its selections; a parent-priced composite still files its parent
- [x] 4.5 Verify every new test fails against the unfixed code — a regression table that passes either way is worthless

## 5. Integration and E2E coverage

- [x] 5.1 Propose the integration/e2e shape to the maintainer before writing it (config rule: never assume) — proposed as a five-tier plan, confirmed in full
- [x] 5.2 `SeededCatalogTrait` — per-type buy requests and a flat-rate lookup responder, so a test exercising a type is not also a test of whether its author knew how to add it, and so no assertion depends on a live rate table
- [x] 5.3 `CatalogTypeLookupTest` — per-type lookup lines, the reported-basis invariant, tax write-back, and the billing-address routing of a virtual-only cart
- [x] 5.4 `CatalogTypeLifecycleTest` — capture, cancel, full refund and partial refund across seven shapes
- [x] 5.5 `TaxInvariantsTest` — refund names what the lookup reported; an order's tax equals its lines'; the basis matches the order subtotal; a quantity change restates the report
- [x] 5.6 `RestCompositePayloadAgreementTest` — cart, order and refund name the same items over v3, tax filed equals tax charged, refund references unique
- [x] 5.7 E2E journeys for the bundle, a mixed cart, configurable, grouped and a virtual-only cart — placed in `specs/checkout/` so the existing `checkout-rest` project re-runs all of them over v3 without further wiring

## 6. Test catalog and tooling

- [x] 6.1 Seed one product per remaining catalog type, including a gift card guarded on Adobe Commerce being present
- [x] 6.2 Register the downloadable link's domain via `DomainManagerInterface`, make the link explicitly shareable, and allow guest checkout for downloadables — without all three the seeded downloadable is unreachable through the guest flow every other type uses
- [x] 6.3 `--products-only` — seed the catalog while writing no configuration, admin user, customer or scope tree, so the seed can be pointed at a working development store
- [x] 6.4 `--recreate[=sku,…]` — the seed is idempotent by SKU, so a correction to how a product is built never reaches a store that has the old one; refuse SKUs the seed does not own
- [x] 6.5 Remove a product from every cart before deleting it in `--recreate`: Magento drops a quote line whose product vanished but keeps its children, and the orphan surfaces only at checkout as a type error
- [x] 6.6 Give seeded products a URL key that steps aside when taken, so the seed does not abort partway on a store with its own catalog
- [x] 6.7 `scripts/verify-test-products.php` — every seeded product loads, is enabled, appears in the category listing and adds to a cart; wired into the installer so a bad catalog fails the install rather than a later suite

## 7. CI

- [x] 7.1 Run integration and E2E on every pull request, not only those into `main` — work here merges into a release branch first, so a change reached `main` already merged and only then got its first integration run

## 8. Documentation

- [x] 8.1 CHANGELOG entries under 1.3.1 (the hotfix) and 1.4.0 (which carries them plus the v3 order-filing fix), including that orders placed before the fix are not corrected by updating and need adjusting in TaxCloud
