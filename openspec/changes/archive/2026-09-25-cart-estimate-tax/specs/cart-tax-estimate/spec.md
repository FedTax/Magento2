## Purpose

Prices a cart from a partial destination — the region and ZIP that Magento's cart-page shipping estimator collects, without a street or city — so shoppers see a ZIP-level tax estimate before checkout, while guaranteeing that such an estimate never reaches an order filed with TaxCloud.

## ADDED Requirements

### Requirement: A partial quote address is priced as an estimate

A quote destination that passes every other lookup pre-flight gate (a postcode valid for its country, a supported country that is enabled for the quote's store, a region) but lacks a city, a first street line, or both SHALL be treated as an estimate address. An estimate address SHALL be priced by TaxCloud rather than returning a zero-tax result. This SHALL apply identically on both transports (SOAP and v3 REST) for United States destinations, and to Canadian destinations wherever those are priced (see the `canada-tax` capability), and SHALL NOT be controlled by any setting.

A destination with both a city and a first street line is not an estimate address and SHALL be priced exactly as before.

#### Scenario: Cart estimator address is priced
- **WHEN** a quote whose shipping address has a US country, a region and a valid ZIP but no street and no city is totalled
- **THEN** one lookup is sent to TaxCloud and the tax it returns is applied to the quote

#### Scenario: Missing street alone makes an estimate
- **WHEN** a quote address has a city, region and valid ZIP but an empty first street line
- **THEN** the lookup is sent as an estimate rather than with an empty street

#### Scenario: Canadian estimate on a Canada-enabled store
- **WHEN** a quote address has a Canadian country, a province and a valid postal code but no street or city, on a store with Canadian tax enabled
- **THEN** the lookup is sent as an estimate and its tax applied

#### Scenario: Other gates still short-circuit
- **WHEN** a partial address lacks a region, has an invalid postal code, is in an unsupported country, or is Canadian on a store without Canadian tax enabled
- **THEN** no lookup is sent and a zero-tax result is returned, exactly as for a complete address failing the same gate

#### Scenario: Same behavior on both transports
- **WHEN** the same partial address is priced on a SOAP-selected store and on a REST-selected store
- **THEN** both send a lookup with the same destination values

### Requirement: An estimate lookup fills only the missing fields with a fixed placeholder

For an estimate address, the destination sent to TaxCloud SHALL carry the literal placeholder `ESTIMATE` in each of the first street line and city that the address lacks, and SHALL carry the address's own region and postal code, and any street line or city it does have, unchanged.

#### Scenario: Both street and city missing
- **WHEN** an estimate address has neither street nor city
- **THEN** the destination's first street line and city are both `ESTIMATE`

#### Scenario: Only the city is missing
- **WHEN** an estimate address has a street line but no city
- **THEN** the destination keeps the street line as entered and its city is `ESTIMATE`

### Requirement: Estimate destinations are not address-verified

Address verification SHALL NOT be attempted for a lookup destination carrying the estimate placeholder, even when Verify Address is enabled for the quote's store. The destination SHALL be sent unchanged.

#### Scenario: Verify Address enabled, estimate address
- **WHEN** an estimate lookup runs on a store with Verify Address enabled
- **THEN** no verify-address call is made and the lookup is sent with the placeholder destination

#### Scenario: Complete address still verified
- **WHEN** a lookup with a complete street and city runs on a store with Verify Address enabled
- **THEN** the destination is verified as before

### Requirement: Estimate lookups are identifiable in the log

An estimate lookup SHALL write a log entry, under the quote's store and within the lookup's log context, stating that the lookup is a ZIP-level estimate because the address lacks a street and/or city.

#### Scenario: Estimate is logged
- **WHEN** an estimate lookup runs
- **THEN** the gateway log for that lookup contains an entry identifying it as an estimate

### Requirement: Estimates never reach order-side operations

The estimate placeholder SHALL only ever appear in quote lookups. Every order-side operation that reports a destination to TaxCloud — capture, exempt re-create, refunds, cancellation reversal and order details — SHALL build its destination from the order's real address and SHALL NOT substitute the placeholder for a missing street or city.

#### Scenario: Order with a missing city is not filed with a placeholder
- **WHEN** an order whose resolved destination address has no city is captured
- **THEN** the order payload does not contain the `ESTIMATE` placeholder

#### Scenario: Checkout replaces the estimate
- **WHEN** a quote that was priced as an estimate is later totalled with a complete shipping address
- **THEN** the lookup is sent with the real street and city, not the placeholder, and its result replaces the estimate
