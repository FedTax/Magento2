# Design

## Context

See proposal.md for the failure. The facts that shape the fix:

- Both lookups build `indexedItems`, a map of request line index → Magento item code, from `RequestBuilder::buildLookupCartItems()`. The index is part of the request (`Index` on SOAP, `index` on REST); the code is not.
- Both build the result `['product' => [code => tax], 'shipping' => tax]` and hand that same array to `ResultCache::saveLookup()` and back out of `getLookup()`.
- The cache key is a hash of the post-observer request. Two requests with one key therefore have the same lines at the same indexes, whatever their codes.
- `Model\Tax::collect()` reads `$taxAmounts['product'][$code] ?? 0`, so an unknown code is silently 0.

## Goals / Non-Goals

**Goals:**
- One fix, in the cache, that both transports get by passing one extra argument.
- No cache flush or setup step on update.

**Non-Goals:**
- Making the collector complain about an unmatched code. Worth considering separately; it would not have prevented this.
- Reducing how often Magento prices a cart.

## Decisions

### D1. Store by line index, rebuild by the caller's codes

`ResultCache::saveLookup(array $params, array $result, array $indexedItems, $store)` stores

```
['lines' => [index => tax, …], 'shipping' => tax]
```

by walking `$indexedItems` and taking `$result['product'][$code]` where present. `getLookup(array $params, array $indexedItems, $store)` walks the caller's `$indexedItems` and rebuilds `['product' => [code => tax], 'shipping' => tax]`. A line the live result had no amount for stays absent, exactly as on the live path.

*Why not add the codes to the cache key?* It would stop the wrong answer, but every label shift becomes a live call, and the shifted entry is useless to every later request. Position is what the two requests actually share.

*Why not return positional data and map in each gateway?* Two copies of the mapping, one per transport, is how the two lookups drift.

### D2. Old entries miss by shape, not by key

`getLookup()` returns null unless the entry is an array with an array `lines` and a numeric `shipping`. Entries written before this change have `product` and no `lines`, so they miss, the lookup runs live, and `saveLookup()` overwrites the same key.

*Why not rename the key prefix?* It leaves the old entries in the backend until they expire and gives nothing the shape check does not.

### D3. `ResultCache::get()`/`save()` stay as they are

Address verification uses them through `getAddress()`/`saveAddress()` and has no per-line data. Only the two lookup methods change.

### D4. The argument is required

`$indexedItems` goes before `$store` and has no default. A caller that forgot it would silently reintroduce the bug; a missing argument fails loudly in tests and static analysis. Only `Api` and `RestGateway` call these methods.

## Risks / Trade-offs

- [An observer on `taxcloud_lookup_before` reorders or removes request lines] → The index it leaves on each line is what both TaxCloud and `indexedItems` refer to, as today; the cache adds no new assumption.
- [One extra live lookup per cached cart right after updating] → Bounded by the number of live entries, all of which expire by midnight anyway.
- [Signature change on a public class] → `ResultCache` is internal to the module and has two callers; noted in the CHANGELOG for anyone who extended it.

## Migration Plan

`bin/magento setup:upgrade` as for any release. No cache flush needed. When rolling back to 1.6.1, flush the `taxcloud` cache type: the old code reads a new-shape entry as a result with no product tax.
