# Fix REST Refund Duplicate Line Items

## Why

Refunding a configurable (or bundle) product on a REST-selected store fails with HTTP 400: `item <sku> appears more than once for cart line 0; combine the quantities into a single entry` (observed live 2026-08-07, order SO000000033). Credit memos list both the composite parent row and its child row — both carrying the child's SKU — and the v1→v3 refund conversion passes both through. Harmless in v1 (index-keyed items, the child row is zero-priced so it refunded $0), fatal in v3 (itemId-keyed refunds reject duplicates). Every composite-product refund over REST fails until fixed.

## What Changes

- The v3 refund conversion combines entries sharing an `itemId` into a single entry, amount-preservingly: quantity sent = total refunded amount across the rows ÷ the reference unit price (the highest row price — the composite parent's; child rows are zero-priced). A configurable parent+child pair collapses to the parent's quantity; genuinely distinct order lines sharing a SKU sum their quantities; all-zero-priced groups are dropped (nothing was charged, nothing to refund).
- Shipping keeps its filed-amount-based conversion, folded into the same grouped pipeline.
- No SOAP-path changes; no changes to what capture files.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `rest-tax-operations`: the credit-memo refund requirement gains the constraint that refund submissions carry at most one entry per item reference, with composite-product credit memo rows combined to match the filed order line.

## Impact

- **Code**: `Model/Gateway/Rest/RestRequestBuilder::buildRefundItems()` (conversion only); unit tests for the merge cases; no interface or wiring changes.
- **Store scoping**: unchanged — all values already resolve from the credit memo's order.
- **Tests**: unit (merge: composite parent+child, duplicate-SKU summing, zero-priced-only drop, shipping unchanged); live re-verification of a configurable refund on the Warden store.

## Non-goals

- No change to capture-side line building (`getAllVisibleItems` already files composite parents only, one line per SKU).
- No handling for duplicate SKUs at capture time (two distinct visible order lines sharing a SKU filing as two v3 lines) — pre-existing, unchanged behavior, noted in the parent change's risk list.
- No SOAP refund changes.
