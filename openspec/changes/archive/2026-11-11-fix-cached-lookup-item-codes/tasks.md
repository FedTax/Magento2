# Tasks

## 1. Cache stores lookup results by line position

- [x] 1.1 `ResultCache`: `saveLookup()` takes `$indexedItems` and stores `['lines' => [index => tax], 'shipping' => tax]`; `getLookup()` takes `$indexedItems`, rebuilds the result under the caller's codes, and returns null for an entry without that shape (design D1, D2, D4). Verify with 1.2.
- [x] 1.2 `ResultCacheTest`: round trip under the same codes; hit under different codes returns the caller's codes; several lines keep their own amounts; a line with no cached amount stays absent; an old-shape entry and a non-array entry miss; existing lifetime and disabled-cache tests updated to the new signature. Verify the file passes.

## 2. Both transports pass the line map

- [x] 2.1 `RestGateway::lookupTaxes()`: pass `$indexedItems` to `getLookup()` and `saveLookup()`. Verify `testCachedLookupIsReturnedUnderTheCurrentItemCodes` passes with a cache double that behaves like the real one, and the other `RestGatewayTest` cache tests pass against the new signature.
- [x] 2.2 `Api::lookupTaxes()` (V1 SOAP): the same two calls. Verify with a SOAP unit test for a cache hit under different codes, and that existing SOAP lookup tests pass.
- [x] 2.3 Self-audit new and changed tests for PHPUnit 9.5/10.5/12.5 compatibility (no `addMethods`, `withConsecutive`, `at()`, `onConsecutiveCalls`, or renamed assertions).

## 3. Documentation and release notes

- [x] 3.1 Documentation check: search `docs/` for the cache lifetime setting and for shipping-only or missing tax in `common-problems`; edit any page whose described behavior is affected, and state the conclusion. README: confirm no developer-material change.
- [x] 3.2 Bump the version to 1.6.2 in `composer.json` and `etc/module.xml`; verify `ModuleVersionConsistencyTest` passes.
- [x] 3.3 Add a `## 1.6.2` CHANGELOG entry: the fix, how it showed up, that no cache flush is needed, that orders already placed are not corrected, and the `ResultCache` signature change.

## 4. Verification

- [x] 4.1 Run `make test-unit`, `make lint` and `make phpstan`; judge each by exit code and by a count of ERROR/WARNING lines.
- [x] 4.2 Propose integration coverage to the maintainer rather than adding it. (Approved 2026-11-11.)
- [x] 4.3 `Test/Integration/Rest/CachedLookupAcrossCartCopiesTest`: a real cart priced, saved, loaded again as a new object and priced again over the recorded v3 transport — the sequence `loadCustomerQuote()` runs — then ordered; asserts the second pricing is a cache hit under different labels and that cart and order carry product and shipping tax. Verified: passes with the fix, fails with product tax 0.0 with the `ResultCache` change reverted (Magento 2.4.9).

## Workflow follow-up

- Commit, push and tag `v1.6.2` when the maintainer asks.
- Archive the change after review, syncing the delta into `openspec/specs/lookup-result-cache/`.
