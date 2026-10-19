## 1. Item ID rule

- [x] 1.1 Add `Model/ItemIdResolver.php` with static `fromSku($sku): string`. A SKU of 50 units or fewer is returned unchanged. A longer one becomes a ≤39-unit prefix, `~`, and 10 hex characters of `sha1` over the first 255 characters. Length is measured in UTF-16 code units (design D2–D4).
- [x] 1.2 Use it at every product-line site:
  - `RequestBuilder::buildLookupCartItems()`, `buildReturnCartItems()` and `buildCartItemsFromOrder()`
  - `RestRequestBuilder::buildOrderPayload()`
  - `RefundDistributor::buildRemainingItems()`

## 2. Adjustment-only distribution names the filed lines

- [x] 2.1 `RefundDistributor::buildRemainingItems()`: iterate top-level items (no parent object and no `parent_item_id`) and expand each through `CompositeItemResolver::orderBasisItems()` (design D5).

## 3. Tests

- [x] 3.1 `ItemIdResolverTest` covers:
  - lengths 50 and 51
  - the merchant's 90-character SKU
  - determinism
  - two SKUs sharing their first 50 characters
  - supplementary-plane characters (length counting, no split character)
  - a SKU over 255 characters matching its truncated form
- [x] 3.2 Builder unit tests: each of the five sites sends the resolved ID for a long SKU and the unchanged SKU for a short one.
- [x] 3.3 `RefundDistributorTest`: a dynamic-price bundle is distributed over its selections, not its wrapper. A fixed-price bundle and a configurable still distribute over the parent.
- [x] 3.4 Self-audit new and changed tests for PHPUnit 9.5/10.5/12.5 compatibility.
- [x] 3.5 Integration test, approved by the maintainer with "implement end to end including tests". Place orders for a fixed-price bundle with a Dynamic SKU over 50 characters, and for a dynamic-price bundle. Assert that the lookup, v3 order, credit-memo return, adjustment-only distribution and full-order cart all name the same IDs, all of at most 50 characters.
- [x] 3.6 Run `make lint`, `make phpstan`, `make test-unit` and `make integration-test`. Judge each by exit code.

## 4. Documentation and release (1.6.1)

- [x] 4.1 Documentation check: `docs/checkout.md` explains how SKUs over 50 characters appear in TaxCloud. README: confirm no developer-material change.
- [x] 4.2 Bump the version to 1.6.1 in `composer.json` and `etc/module.xml`.
- [x] 4.3 Add a `## 1.6.1` CHANGELOG entry.
- [x] 4.4 Commit on branch `1.6.1` (from `v1.6.0`).
