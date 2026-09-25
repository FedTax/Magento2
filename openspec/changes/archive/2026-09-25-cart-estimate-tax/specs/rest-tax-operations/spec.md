## MODIFIED Requirements

### Requirement: Tax lookup executes over the v3 carts endpoint

For a REST-selected store, a tax lookup for a quote SHALL be performed by creating/updating a v3 cart on the store's connection, keyed by a cart identifier stable for the quote, and the per-line tax amounts returned SHALL be applied to the quote's product and shipping tax exactly as the SOAP lookup applies its per-item responses. The request SHALL carry the quote's line items (including shipping as a line item) with their store-resolved TICs, effective prices, quantities, origin and destination addresses — each address carrying its two-letter country code — and, when the destination is in the United States and the customer holds a validated exemption certificate for the destination state, the certificate reference.

A destination lacking a street line or city SHALL NOT short-circuit the lookup; it is priced as an estimate as specified by the `cart-tax-estimate` capability.

#### Scenario: Successful lookup applies per-line tax
- **WHEN** a lookup is performed for a quote on a REST-selected store and the v3 API returns tax for each line item
- **THEN** each product item's tax amount and the shipping tax are populated from the corresponding response lines, keyed back to the originating quote items

#### Scenario: Same-quote lookups reuse the cart identity
- **WHEN** two lookups are performed for the same quote (e.g. the customer changes quantities)
- **THEN** both requests use the same cart identifier so TaxCloud treats them as updates to one cart rather than accumulating abandoned carts

#### Scenario: Addresses state their country
- **WHEN** a lookup is performed for a US destination
- **THEN** both the origin and the destination in the request carry country code `US`

#### Scenario: Pre-flight gates short-circuit without an API call
- **WHEN** the destination is missing a postcode, lacks a region, has a postal code invalid for its country, is outside the US and not an enabled Canadian destination (see the `canada-tax` capability), or the quote has no taxable items
- **THEN** the lookup returns a zero-tax result without calling the v3 API, matching SOAP behavior

#### Scenario: Missing city or street does not short-circuit
- **WHEN** the destination has a valid postcode and region but lacks a city or street line
- **THEN** the lookup calls the v3 API as an estimate rather than returning a zero-tax result

#### Scenario: Lookup results are cached
- **WHEN** a lookup identical to a previously successful one (same post-observer request, same store) occurs within the cache lifetime
- **THEN** the cached result is returned without calling the v3 API

#### Scenario: Failed lookup falls back per store configuration
- **WHEN** the v3 lookup fails (transport error, non-2xx response) and fallback to Magento rates is enabled for the quote's store
- **THEN** tax is calculated from Magento's native tax rules; with fallback disabled, a zero-tax result is returned
