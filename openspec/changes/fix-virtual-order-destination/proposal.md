## Why

A Magento order made entirely of virtual, downloadable or virtual gift-card items has no shipping address at all — `QuoteManagement::submitQuote` converts one only when the quote is not virtual. The module reads the order's shipping address without a fallback when it builds a destination, so for those orders no destination can be built and every order-side TaxCloud operation that needs one silently gives up. On a REST-selected store this means a digital-only order is taxed at checkout, the customer pays, and the order is never filed with TaxCloud — it does not reach the merchant's return.

The quote side is already correct and needs no behaviour change: Magento assigns a wholly-virtual cart's items to the billing address, so the lookup is already sourced there. That rule is load-bearing for what the merchant is charged and is currently guaranteed only by Magento's own code, so this change pins it as a requirement rather than leaving it implicit.

## What Changes

- Order-side destination resolution SHALL fall back to the order's billing address when the order has no shipping address, in a single place shared by every caller. Today `RequestBuilder::buildDestinationFromOrder()` reads the shipping address only and returns `null` for every digital-only order.
- The three order-address call sites converge on that one resolver, so they cannot drift again: `RequestBuilder::buildDestinationFromOrder()`, `Observer\Sales\RecordCertificate` and `Observer\Sales\PersistRetailDeliveryFee` (the latter two already fall back, informally and separately).
- The following stop failing for digital-only orders, with no changes of their own — they all consume the resolved destination: v3 REST order capture, v3 exempt re-create, v3 refunds, and the SOAP exempt re-lookup used by tax-only refunds and cancellation reversal.
- The destination a sale is filed under at capture is required to equal the destination it was quoted under at checkout, for both cart shapes.
- Existing quote-side behaviour is specified, not changed: a cart of only non-shippable items sources to the billing address; a cart with any shippable line sources every line, digital ones included, to the shipping address.
- Logging names which address type a destination came from, so a support reader can tell a billing-sourced digital order from a mis-sourced physical one.
- Merchant documentation gains an explicit statement of the sourcing rule; `docs/checkout.md` currently states the destination is "the customer's shipping address", which is wrong for digital-only carts.
- **Not a breaking change.** No configuration is added, renamed or removed; no admin-visible setting changes; orders that previously failed to file now file.

## Capabilities

### New Capabilities
- `tax-address-sourcing`: which address a sale is sourced to — the destination sent to TaxCloud for a quote and for an order, the fallback when no shipping address exists, and the requirement that the two agree. Transport-neutral: it constrains SOAP and v3 REST alike.

### Modified Capabilities
None. `rest-tax-operations` and `order-capture-lifecycle` describe *what* is filed and *when*; neither states where a sale is sourced, so neither requirement text changes. Both consume `tax-address-sourcing` and stop failing for digital-only orders as a consequence.

## Non-goals

- **No new admin setting.** WooCommerce's `disable_virtual_split` exists to switch off a defect Magento never had; the equivalent here would be a setting that turns a bug on.
- **No per-item splitting by shippability.** A split is warranted by multiple destinations, never by item type. A mixed cart stays one lookup and one filed order.
- **No honouring of Magento's `tax/calculation/based_on`.** The module continues to source by the address Magento assigned the items to, ignoring the native "Tax Calculation Based On" select. Honouring "Billing Address" there would mis-source every physical order.
- **No in-store-pickup work.** Magento already replaces the quote's shipping address with the pickup location's address (`InventoryInStorePickupQuote\Model\ToQuoteAddress`), so pickup sales already source to the pickup location.
- **No Colorado Retail Delivery Fee change.** `FeeService` already excludes virtual items, so a digital-only Colorado order correctly carries no fee.
- **No backfill of orders already unfiled.** Whether previously failed digital-only captures are re-driven is a separate decision for the maintainer; a failed capture leaves `taxcloud_captured` unset, so the existing retry-at-next-fulfillment-document path will pick up any order not yet invoiced.

## Impact

- `Model/Gateway/RequestBuilder.php` — `buildDestinationFromOrder()` gains the fallback; the shipping-or-billing choice moves into one resolver.
- `Observer/Sales/RecordCertificate.php`, `Observer/Sales/PersistRetailDeliveryFee.php` — inline fallbacks replaced by the shared resolver.
- Downstream consumers unchanged but newly functional for digital-only orders: `Model/Gateway/Rest/RestRequestBuilder.php` (`buildOrderPayload`), `Model/Api.php` (`lookupForOrderExempt`), `Observer/Sales/Address.php`.
- `docs/checkout.md`, `docs/capture.md`, `docs/common-problems.md`. `README.md` is unaffected — no settings, attributes or install steps change.
- No database schema, no API surface, no dependency changes.

### Store-scoping implications

The resolver reads no configuration, so it introduces no new scope-resolution risk. Every consumer already resolves its configuration against `$order->getStoreId()` and continues to; the destination is order data, not store data. The one store-scoped read in the neighbourhood — the CO RDF TIC in `PersistRetailDeliveryFee` — keeps resolving against the quote's store, unchanged by this work.
