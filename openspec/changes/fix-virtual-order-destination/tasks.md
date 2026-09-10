## 1. The resolver

- [x] 1.1 Add `Model/Address/TaxAddressResolver.php` with `forOrder($order)` returning `$order->getShippingAddress() ?: $order->getBillingAddress()` (null when neither exists) and `forQuote($quote)` returning the billing address for a virtual quote and the shipping address otherwise, per design.md D1/D2. No logger, no config, no constructor dependencies.
- [x] 1.2 Document on the class why an order address can be absent while a quote address never is, so the asymmetry between the two methods reads as deliberate.

## 2. Order-side destination

- [x] 2.1 Change `RequestBuilder::buildDestinationFromOrder()` to take its address from `TaxAddressResolver::forOrder()`, keeping the existing signature, the US/valid-ZIP guards and the `null` contract intact (design.md D4).
- [x] 2.2 Inject `TaxAddressResolver` into `RequestBuilder`, and bind it in `etc/di.xml`. Resolved during implementation (design.md D6): a *required* argument fatals any install whose compiled DI is stale, so all three consumers take it as a defaulted last argument and di.xml supplies it — with a test pinning the three bindings, since a defaulted argument DI never supplies cannot be redirected by a `<preference>`.
- [x] 2.3 Add the address-type logging the spec requires: an info line naming shipping vs billing and the order, and an error line when neither address yields a usable US destination (design.md D3).

## 3. Converging the other call sites

- [x] 3.1 Point `Observer/Sales/RecordCertificate::destinationState()` at `TaxAddressResolver::forOrder()`, dropping its inline `?:`.
- [x] 3.2 Point `Observer/Sales/PersistRetailDeliveryFee` at `TaxAddressResolver::forQuote()`, replacing the `?:` that is dead code on a quote (design.md D2).
- [x] 3.3 Grep the module for any remaining bare `getShippingAddress()` on an order or quote outside the resolver, and confirm each remaining one is intentional.

## 4. Unit tests

- [x] 4.1 `Test/Unit/Model/Address/TaxAddressResolverTest.php` — `forOrder()`: shipping present wins; shipping absent falls back to billing; both absent returns null. `forQuote()`: virtual quote yields billing; non-virtual yields shipping.
- [x] 4.2 Extend the `RequestBuilder` unit tests: `buildDestinationFromOrder()` returns the shipping address when present, the billing address for a shipping-less order, and null for no address / non-US / unparseable ZIP on both branches.
- [x] 4.3 Extend the `RestRequestBuilder` unit tests: `buildOrderPayload()` produces a complete payload with the billing destination for a shipping-less order (today it returns null).
- [x] 4.4 Assert the log records which address type was used, and records the unusable case.
- [x] 4.5 Verify every new or changed test against PHPUnit 9.5, 10.5 and 12.5 API compatibility before considering the group done.

## 5. Verification

- [x] 5.1 Run the full unit suite.
- [x] 5.2 Run PHPStan at the project's level and confirm no new findings and no baseline growth.
- [x] 5.3 Run PHP CodeSniffer over the changed files.

## 6. Documentation

- [x] 6.1 `docs/checkout.md` — correct "Where it ships to — the customer's shipping address" and add a short digital-products note: a cart of only virtual, downloadable or virtual gift-card items is taxed against the billing address; add any shippable item and the whole cart, digital lines included, is taxed against the shipping address.
- [x] 6.2 `docs/capture.md` — state that a digital-only order files against its billing address.
- [x] 6.3 `docs/common-problems.md` — add an entry for the pre-fix symptom (a digital-only order that never reached TaxCloud) and how to tell from the log which address a sale was sourced to.
- [x] 6.4 Confirm `README.md` and `mkdocs.yml` need no change (no new pages, settings, attributes or install steps) and say so explicitly when wrapping up.
- [x] 6.5 Add the `CHANGELOG.md` entry.

## 7. Integration and e2e coverage

- [x] 7.1 Integration coverage, confirmed by the maintainer and written:
  - `Test/Integration/Model/Tax/VirtualOrderSourcingTest.php` — a placed download-only order resolves a destination and builds a v3 order payload against its billing address (both `null` before the fix); the same order captures and is flagged captured; quote-time and capture-time destinations are the same address for download-only, mixed and physical carts; a tax-only refund of a download-only order runs the SOAP exempt re-lookup and re-creates under the `-exempt` cart id.
  - `Test/Integration/Model/Tax/CatalogTypeLookupTest.php` — virtual-cart tests re-pointed at a Texas billing address and a Colorado shipping address, plus a new mixed-cart test asserting one lookup to the shipping address carrying the digital line. Previously both addresses were identical, so no sourcing assertion in the file could fail.
  - `Test/Integration/IntegrationTestCase.php` — `newGuestQuote()` takes a `$shippingOverride` so a test can make the two addresses differ.
  - `Test/Integration/SeededCatalogTrait.php` — `echoingVerifyAddressResponder()`. The seeded `verifyAddress` double returns a fixed Austin TX address for any input and `Observer\Sales\Address` substitutes it into the lookup destination, so without this every sourcing assertion in the suite passes vacuously.
  - Verified the new tests bite: reverting the resolver to shipping-only fails exactly the four download-only cases and leaves the physical and mixed ones passing.
- [x] 7.2 E2E: not warranted, and not added. Nothing here is storefront-visible — no new setting, no new screen, no change to what a shopper sees — so an E2E run would only re-prove what the integration suite covers.
