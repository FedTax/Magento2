# Design — add-co-retail-delivery-fee

## Context

See proposal.md — Why. The verified TaxCloud contract (both transports, confirmed by TaxCloud support): TIC 11098 marks a zero-rated fee line; the integration supplies the amount; TaxCloud echoes it untaxed, excludes it from discount distribution, and files it from the captured cart. There is no rate endpoint and no server-side validation of the amount.

Relevant current-state facts the approach builds on:

- `Model\Tax` (extends core quote Tax collector) drives lookups from `collect()`; `Model\Api::lookupTaxes` / `RestGateway::lookupTaxes` route by store-scoped `api_type`.
- **Single line-building point**: `Model/Gateway/RequestBuilder::buildLookupCartItems()` builds v1 cart items, and `RestRequestBuilder::buildCartLineItems()` delegates to it and maps to v3 shape (`toV3LineItem`). One insertion covers both transports' lookups.
- `Model/CartItemResponseHandler::applyProcessedItemsToResult()` routes response lines by `ItemID`: `'shipping'` → shipping bucket, **anything else → product bucket via `$indexedItems[$index]`**. An unrecognized sentinel would hit an undefined index — the handler needs an explicit fee branch, not just "ignore".
- v1 `Returned` params already carry `returnCoDeliveryFeeWhenNoCartItems`, hardcoded `false` in `Model/Api.php` (two sites).
- The module has no `etc/sales.xml`, no `etc/fieldset.xml`, no `etc/pdf.xml` and no checkout-totals UI components today — the fee is the module's first custom total.
- `etc/db_schema.xml` exists and already adds columns to `sales_order`.

## Goals / Non-Goals

**Goals (design level):**
- One eligibility/amount authority consumed by every site (collector, lookup builders, capture, refunds) — no re-derived logic.
- The fee line inserted once, in the shared v1 builder, so SOAP and REST cannot drift.
- Zero behavior change for stores with the toggle off (default): no new lines, no new totals, byte-identical requests.

**Non-Goals (design level):**
- No generic "fees framework"; this is one fee. The MN follow-up would add a second eligibility rule + config group, not an abstraction now.
- No attempt to make the fee a Magento "tax"; it is a custom total.

## Decisions

### D1: A dedicated `RetailDeliveryFeeService` is the single authority

New `Model/RetailDeliveryFee/FeeService` (name indicative) exposing `isEligible(Quote, ShippingAssignment): bool` and `getAmount($store): float` plus the sentinel item id constant (`co-rdf`) and `getTic($store)`. Consumed by the total collector, `RequestBuilder`, the capture payload builders, and the refund path.

*Why:* four call sites need the same answers; the eligibility rules (enabled + CO + taxable tangible + mapped method) must not be re-implemented per site. *Alternative considered:* folding eligibility into `Model\Tax` — rejected: the lookup builders and refund path don't run through the collector.

### D2: The fee is charged by a new total collector, not by `Model\Tax`

New quote total collector registered in a new `etc/sales.xml` (section `quote`), running after `tax`, writing `taxcloud_rdf_amount`/`base_taxcloud_rdf_amount` onto the address/total and adding to the grand total; plus invoice and creditmemo total collectors (sections `order_invoice`, `order_creditmemo`).

*Why:* Magento's custom-total mechanism gives grand-total inclusion, payment-amount correctness, and per-document totals idiomatically; `Model\Tax` already carries core tax responsibilities and its result contract is "tax buckets", not order fees. *Alternative:* charging via `Model\Tax::collect` and stuffing the amount into the total — rejected as concern-mixing and harder to keep out of `getTaxAmount()`.

Multi-address safety: the collector charges only on the shipping address being collected; a quote is charged once per delivery to CO (Magento multi-address checkout collects per address, which matches the per-delivery statute).

### D3: Fee line insertion lives in `RequestBuilder::buildLookupCartItems`

When `FeeService::isEligible()` holds, append `{ItemID: 'co-rdf', Index: next, TIC: getTic(), Price: getAmount(), Qty: 1}` after product and shipping lines. The REST path inherits it through its existing delegation; `toV3LineItem` maps it unchanged.

*Why:* single point, transports cannot drift, and the line participates in existing cache keys automatically (the cache key derives from the request, so eligibility flips invalidate correctly). Discount immunity holds by construction: only product lines net `discountPerUnit`; the fee line is built from config, not from a quote item.

### D4: Response routing gets an explicit fee branch that discards

`CartItemResponseHandler::applyProcessedItemsToResult()` gets a branch: `ItemID === 'co-rdf'` → skip (optionally log if TaxAmount ≠ 0). Nothing is routed to product/shipping buckets and no `$indexedItems` access occurs for the fee index.

*Why:* today's fallthrough would raise an undefined-index on the sentinel and misroute into the product bucket keyed by `null`. TaxCloud always returns 0 tax for the line (verified), so the branch is defensive, but it must exist for the sentinel not to corrupt the result.

### D5: Persistence via `db_schema.xml` columns + a quote→order observer

Columns `taxcloud_rdf_amount` and `base_taxcloud_rdf_amount` (decimal 20,4, nullable) on `quote_address`, `sales_order`, `sales_invoice`, `sales_creditmemo`; whitelist regenerated. Amount is snapshotted at collect time from config — later config edits never mutate placed orders; capture and refunds read the order's stored amount, not config.

Quote→order copy is done by an observer on `sales_model_service_quote_submit_before` (`Observer\Sales\PersistRetailDeliveryFee`), **not** a fieldset. A `sales_convert_quote_address` fieldset entry does not survive: core's `Quote\Address\ToOrder::convert()` funnels the fieldset through `populateWithArray($order, …, OrderInterface::class)`, which keeps only interface-declared keys and silently drops a plain custom column (verified in the integration container — the column landed on `quote_address` but stayed NULL on `sales_order`). The observer fires on the same conversion with both entities in hand and writes the column directly. Invoice and creditmemo columns are written by their own total collectors and persist through the normal resource save.

*Why decimal on documents rather than recompute:* R-11 — displayed = captured = remitted; a July-1 config change must not re-price an in-flight order.

### D5a: The display segment reads address data, not just the collect registry

The quote total collector's `fetch()` must read the amount from **both** the collect-time total-amounts registry (`getTotalAmount(CODE)`) and the address column (`getTaxcloudRdfAmount()`). Magento's `TotalsReader` — which builds the cart/checkout `total_segments` — hands `fetch()` a fresh `Total` hydrated from address *data*, where the registry is empty and only the column carries the amount. Reading the registry alone renders the fee into the grand total (computed during collect) but shows no line — the exact "fee charged, no row" symptom. Covered by a unit test and the storefront e2e assertion.

### D6: Capture and refund payloads read the order's stored fee

- v1 capture (`AuthorizedWithCapture` path) and v3 `buildOrderPayload` append the fee line (`co-rdf`, stored amount, order-store TIC, qty 1, tax 0) when `taxcloud_rdf_amount > 0`.
- Full return (all items returned): the creditmemo collector credits the fee; the v1 `Returned` cart items include the fee line; the no-cart-items case (adjustment-style full return) drives `returnCoDeliveryFeeWhenNoCartItems = true` instead of today's hardcoded `false`. v3 refunds include the fee item reference.
- Partial return: creditmemo collector contributes 0 and the refund payloads omit the fee.
- "Full return" is decided by comparing returned qtys against the order's ordered qtys (net of previous memos), the same basis `RefundDistributor` already works from.

### D7: Configuration — nested `colorado` group; TIC-misuse guard as backend models

Fields per the agreed set: `co_rdf_enabled` (Enabledisable, default 0), `co_rdf_delivery_methods` (multiselect, `Magento\Shipping\Model\Config\Source\Allmethods`, `can_be_empty`), `co_rdf_amount` (text, default 0.31, frontend `validate-number-range` + backend model enforcing 0.00–2.00), `co_rdf_tic` (text, default 11098, existing `TicField` frontend model). All store-scope, `depends` on the module `enabled` / group toggle, defaults in `etc/config.xml`, accessors on `TaxcloudConfig` taking explicit `$store`.

Guard (spec: RDF TIC not assignable elsewhere): a backend model on `default_tic` and `shipping_tic` rejects saving the value currently configured as the RDF TIC.

### D8: Presentation surfaces

- **Cart/checkout**: layout XML adds a totals item to the checkout summary (`checkout_cart_index` + `checkout_index_index`), Knockout component + template reading a new `total_segments` entry exposed by a `TotalSegmentInterface` provider (standard custom-total pattern).
- **Customer/admin order, invoice, creditmemo views + emails**: order-totals block plugin/child adding the line from the persisted amount when > 0 (emails render through the same totals blocks).
- **PDF**: new `etc/pdf.xml` totals entry mapping the order column, the stock mechanism for PDF totals.
- Label fixed: "Colorado Retail Delivery Fee" via i18n; no label setting.

## Risks / Trade-offs

- [Fee charged but lookup fell back to Magento rates → TaxCloud never saw it] → collector still charges (spec), and the capture payload includes the line anyway — capture, not lookup, is what TaxCloud files from; the fallback logger additionally records the event for reconciliation.
- [Order-totals blocks differ across Magento 2.4.7–2.4.9 / Luma vs admin] → keep display logic in one totals-source helper; verify on the CI matrix versions.
- [Multiselect stores `carrier_method` codes; carriers can be removed later] → stale codes simply never match; no error path.
- [A merchant leaves the amount at 0.31 after a July 1 change] → accepted (non-goal); docs page states the annual change and links the CO DOR rate page.
- [Third-party checkouts may not render custom total segments] → the amount is still in the grand total (correct charge); display degradation only, same class of risk as any custom total.

## Migration Plan

Additive schema columns (nullable, no backfill) + default-off toggle: deploy is `setup:upgrade` with no data migration and no behavior change until a merchant enables the group. Rollback = disable toggle (columns are inert).

## Open Questions

- Integration/e2e coverage: per test policy, proposed after implementation for maintainer confirmation — candidate flows: checkout charge + capture payload assertion, full vs partial refund. Unit coverage is not in question.
