# Proposal

## Why

A cached tax lookup can come back with no tax for the products, so an order is charged tax on shipping only (ticket 66649, DEV-10651; order 3000022496). The cached result lists product tax under Magento's cart-line labels (`sequence-1`, `sequence-2`, …), which come from a counter that restarts with every request and are not part of the request the cache is keyed on. When the same cart reaches a lookup under different labels, the cache answers with labels the collector does not recognise, and every unmatched line is taxed at 0.

Magento itself produces this whenever one request prices the same cart through two separately loaded copies: the second copy's lines get later labels, the request is identical, and the cache answers. Core does it when a guest cart is merged into a customer's saved cart at login (`Checkout\Model\Session::loadCustomerQuote()`), and any extension that reloads a quote mid-request does the same. An earlier draft of this proposal named the cart page's delete action as the trigger; an integration test showed that path re-prices the unchanged item list and does not shift labels.

## What Changes

- A cached lookup result is stored by cart-line position and applied to whatever labels the current request uses, on both V1 SOAP and V3 REST.
- Entries written in the previous shape are treated as a miss, so a store is correct from its first lookup after updating without flushing any cache.
- Unit tests for the cache mapping and for both transports, including the failing reproduction already written for the REST gateway.
- CHANGELOG entry and a 1.6.2 version bump.

## Non-goals

- Correcting orders already placed with shipping-only or shifted tax. They keep the tax they were charged.
- Changing what the cache is keyed on, its lifetime, or its midnight cap.
- Changing how Magento labels cart lines, or pricing a cart fewer times per request.
- The other two orders on the ticket: the exempt customer who was taxed (3000022578) and the order with no details (3000022380) are separate investigations.
- Caching of address verification results, which carries no per-line data.

## Store scoping

No new setting and no new config read. The cache lifetime keeps resolving against the quote's store, and the cache key is unchanged, so entries stay separated exactly as they are now.

## Capabilities

### New Capabilities
- `lookup-result-cache`: how a cached tax lookup result is applied to the cart being priced, on either transport.

### Modified Capabilities

None. `rest-tax-operations` already requires that an identical lookup is answered from the cache; that stays true and its requirement text does not change.

## Impact

- `Model/Cache/ResultCache.php`: lookup entries change shape; `getLookup()` and `saveLookup()` take the request's line-to-label map.
- `Model/Gateway/Rest/RestGateway.php`, `Model/Api.php`: pass the map on read and write.
- `Test/Unit/Model/Cache/ResultCacheTest.php`, `Test/Unit/Model/Gateway/Rest/RestGatewayTest.php`, and the SOAP lookup unit tests.
- `CHANGELOG.md`, `composer.json`, `etc/module.xml`.
- One extra live lookup per cached cart after updating, as old entries miss.
