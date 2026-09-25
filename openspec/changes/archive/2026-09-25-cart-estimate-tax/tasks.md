## 1. Estimate-address helper

- [x] 1.1 Add `Model/Address/EstimateAddress` with `PLACEHOLDER = 'ESTIMATE'`, `isPartial($address)`, `fill(array $v1Destination)` and `isEstimate(array $destination)` (v1 and v3 key shapes)
- [x] 1.2 Unit tests for the helper: missing city / missing street / both / neither / whitespace-only; `fill()` leaves present fields, region and ZIP (and Canadian `Country`/`PostalCode`) untouched; `isEstimate()` on v1 and v3 shapes

## 2. Lookup gates

- [x] 2.1 `RestGateway::lookupTaxes`: replace the "No city" gate with estimate handling — build the destination (US or Canadian), then log and `fill()` it when the address is partial
- [x] 2.2 `Api::lookupTaxes` (SOAP): same replacement
- [x] 2.3 Update the existing unit tests that asserted the "no city → zero, no call" behavior on both transports
- [x] 2.4 Unit tests on both transports: partial US address sends a lookup with the placeholder destination; missing street only fills only the street; complete address sends no placeholder; partial Canadian address on a Canada-enabled store is filled; partial address still short-circuits on missing region / invalid ZIP / Canada disabled; estimate is logged

## 3. Address verification skip

- [x] 3.1 `Observer/Sales/Address`: skip verification for a v1 destination or a v3 cart destination carrying the placeholder
- [x] 3.2 Unit tests: SOAP-shape and REST-shape estimate destinations make no verify call and leave the payload unchanged; complete destinations are still verified

## 4. Order-side guarantee

- [x] 4.1 Unit test pinning that an order destination with no street or city (`RequestBuilder::buildDestinationFromOrder`, US and Canada — the single source of order-side destinations) never contains the placeholder

## 5. Documentation

- [x] 5.1 `docs/checkout.md`: cart page shows an estimate once a ZIP and state are entered; estimate is ZIP-level and can differ from the final tax in ZIPs spanning jurisdictions; final tax from the full address at checkout
- [x] 5.2 `docs/common-problems.md` and `docs/testing-your-setup.md`: replace the "no tax on the cart page is normal" guidance
- [x] 5.3 `docs/extending.md`: note that a lookup-before destination may carry the `ESTIMATE` placeholder
- [x] 5.4 `CHANGELOG.md` entry

## 6. Verification

- [x] 6.1 Run unit tests, phpcs and PHPStan via make targets; judge by exit code
- [x] 6.2 Check new test code against PHPUnit 9.5 / 10.5 / 12.5 APIs
- [x] 6.3 Propose integration (partial address → lookup sent with placeholder, REST mock) and e2e (cart estimator shows a tax line) coverage to the maintainer

## 7. Integration and e2e coverage (approved by maintainer)

- [x] 7.1 Integration `Test/Integration/Model/Tax/CartEstimateLookupTest`: estimator address priced with the placeholder and not verified (SOAP and REST), tax lands on the quote, full address replaces the estimate and is verified, Canadian estimate on a Canada-enabled store, no region → not sent
- [x] 7.2 E2E `specs/checkout/cart-estimate-tax.spec.ts` (+ `CartPage` page object): guest fills the cart estimator with Texas / 78701 and sees the golden $1.24 tax, in both the SOAP and REST passes
- [x] 7.3 Full integration suite and the e2e spec pass locally
