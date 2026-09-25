## MODIFIED Requirements

### Requirement: Tax lookup executes over the v3 carts endpoint

For a REST-selected store, a tax lookup for a quote SHALL be performed by creating/updating a v3 cart on the store's connection, keyed by a cart identifier stable for the quote, and the per-line tax amounts returned SHALL be applied to the quote's product and shipping tax exactly as the SOAP lookup applies its per-item responses. The request SHALL carry the quote's line items (including shipping as a line item) with their store-resolved TICs, effective prices, quantities, origin and destination addresses — each address carrying its two-letter country code — and, when the destination is in the United States and the customer holds a validated exemption certificate for the destination state, the certificate reference.

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
- **WHEN** the destination is missing a postcode, lacks a region or city, has a postal code invalid for its country, is outside the US and not an enabled Canadian destination (see the `canada-tax` capability), or the quote has no taxable items
- **THEN** the lookup returns a zero-tax result without calling the v3 API, matching SOAP behavior

#### Scenario: Lookup results are cached
- **WHEN** a lookup identical to a previously successful one (same post-observer request, same store) occurs within the cache lifetime
- **THEN** the cached result is returned without calling the v3 API

#### Scenario: Failed lookup falls back per store configuration
- **WHEN** the v3 lookup fails (transport error, non-2xx response) and fallback to Magento rates is enabled for the quote's store
- **THEN** tax is calculated from Magento's native tax rules; with fallback disabled, a zero-tax result is returned

### Requirement: Address verification executes over the v3 verify-address endpoint

For a REST-selected store, address verification SHALL submit the address parts to the v3 verify-address endpoint and return the normalized address in the same shape the SOAP implementation returns (Address1, Address2, City, State, Zip5, Zip4), so transport-unaware callers behave identically. Verification failures SHALL return false, leaving the caller's address unchanged. Successful verifications SHALL be cached per store. In-lookup verification SHALL only be attempted for US destinations; a destination in any other country SHALL be left unchanged without a verification request.

#### Scenario: Verified address is normalized and cached
- **WHEN** an address is verified successfully on a REST-selected store
- **THEN** the normalized address is returned in the established shape and cached, and a repeat verification within the cache lifetime does not call the v3 API

#### Scenario: Unverifiable address returns false
- **WHEN** the v3 API cannot verify the address
- **THEN** the operation returns false and the original address remains in use

#### Scenario: Non-US destination is not verified
- **WHEN** a lookup with address verification enabled carries a Canadian destination
- **THEN** no verify-address request is made and the destination is sent as built
