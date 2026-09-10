# Tasks: fix-rest-refund-duplicate-line-items

## 1. Fix

- [x] 1.1 Rework the conversion in `RestRequestBuilder::buildRefundItems()` to group entries by itemId with the amount-preserving merge (quantity = Σ(Price×Qty) ÷ max row price, 4dp; all-zero-priced groups dropped; shipping folded into the grouped pipeline with its filed-amount basis)
- [x] 1.2 Unit tests: configurable parent+child collapses to one entry with the parent quantity; two priced rows sharing a SKU sum quantities; zero-priced-only group omitted; existing item/shipping/distribution cases unchanged

## 2. Verify

- [x] 2.1 Full unit suite + PHPStan + lint green
- [x] 2.2 Live re-test on Warden: refund a configurable-product order over REST and confirm the v3 refund is accepted
