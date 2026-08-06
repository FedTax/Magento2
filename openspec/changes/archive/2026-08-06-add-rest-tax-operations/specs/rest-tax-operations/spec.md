## Purpose

Executes the module's seven gateway operations (tax lookup, order capture, refunds, cancellation reversal, order details, address verification, exemption validation) over the TaxCloud v3 REST API, with behavior equivalent to the SOAP implementations wherever the two APIs allow, so a store switched to `api_type = rest` transacts entirely over v3.

## ADDED Requirements

### Requirement: Tax lookup executes over the v3 carts endpoint

For a REST-selected store, a tax lookup for a quote SHALL be performed by creating/updating a v3 cart on the store's connection, keyed by a cart identifier stable for the quote, and the per-line tax amounts returned SHALL be applied to the quote's product and shipping tax exactly as the SOAP lookup applies its per-item responses. The request SHALL carry the quote's line items (including shipping as a line item) with their store-resolved TICs, effective prices, quantities, origin and destination addresses, and — when the customer holds a validated exemption certificate for the destination state — the certificate reference.

#### Scenario: Successful lookup applies per-line tax
- **WHEN** a lookup is performed for a quote on a REST-selected store and the v3 API returns tax for each line item
- **THEN** each product item's tax amount and the shipping tax are populated from the corresponding response lines, keyed back to the originating quote items

#### Scenario: Same-quote lookups reuse the cart identity
- **WHEN** two lookups are performed for the same quote (e.g. the customer changes quantities)
- **THEN** both requests use the same cart identifier so TaxCloud treats them as updates to one cart rather than accumulating abandoned carts

#### Scenario: Pre-flight gates short-circuit without an API call
- **WHEN** the destination is missing a postcode, is outside the US, lacks a region or city, has an invalid ZIP format, or the quote has no taxable items
- **THEN** the lookup returns a zero-tax result without calling the v3 API, matching SOAP behavior

#### Scenario: Lookup results are cached
- **WHEN** a lookup identical to a previously successful one (same post-observer request, same store) occurs within the cache lifetime
- **THEN** the cached result is returned without calling the v3 API

#### Scenario: Failed lookup falls back per store configuration
- **WHEN** the v3 lookup fails (transport error, non-2xx response) and fallback to Magento rates is enabled for the quote's store
- **THEN** tax is calculated from Magento's native tax rules; with fallback disabled, a zero-tax result is returned

### Requirement: Order capture creates a v3 order directly

For a REST-selected store, capturing an order SHALL create a v3 order on the store's connection directly from the Magento order data — line items with their store-resolved TICs, effective prices, quantities, and each line's tax amount and rate as recorded on the Magento order — with the transaction date taken from the order's placement time and the completed date set at capture time. Capture is whole-order: the operation SHALL NOT support partial capture. The result SHALL be reported as success only when the order is created or already exists in TaxCloud.

#### Scenario: Successful capture
- **WHEN** an order on a REST-selected store is captured per the store's capture trigger
- **THEN** a v3 order is created carrying the order's stored per-line tax, marked completed, and the operation reports success

#### Scenario: Duplicate capture is benign
- **WHEN** capture is invoked for an order that was already captured (the v3 API reports the order identifier already exists)
- **THEN** the operation reports success without altering the existing TaxCloud order

#### Scenario: Failed capture reports failure
- **WHEN** the v3 order creation fails for any other reason
- **THEN** the operation reports failure and the error is logged with enough detail to diagnose (status, response detail), with credentials never logged

### Requirement: Credit memo refunds execute over the v3 refunds endpoint

For a REST-selected store, refunding from a credit memo SHALL submit a v3 refund against the captured order, expressing refunded product lines and shipping as item references with quantities (fractional where the refunded amount is not a whole multiple of the unit price). Prices SHALL NOT be sent; the v3 API derives amounts from the filed order. The SOAP path's special cases SHALL be preserved: adjustment-only credit memos distribute the adjustment across remaining items as fractional quantities, tax-only refunds fully refund the order and re-create it as exempt, and credit memos with nothing meaningful to refund succeed without an API call.

#### Scenario: Item and shipping refund
- **WHEN** a credit memo refunds specific items and shipping on a REST-selected store
- **THEN** the v3 refund carries each refunded item and the shipping line by item reference and refunded quantity, and reports success on a created refund

#### Scenario: Adjustment-only refund distributes as quantities
- **WHEN** a credit memo contains only an adjustment amount (no items, no shipping, not tax-only)
- **THEN** the adjustment is distributed proportionally across the order's remaining unrefunded lines and expressed as fractional quantities in the v3 refund

#### Scenario: Tax-only refund re-creates the order as exempt
- **WHEN** a credit memo refunds only the order's tax amount
- **THEN** the order is fully refunded in TaxCloud and re-created as a completed exempt v3 order (distinct order identifier), so nexus tracking is preserved without tax liability

#### Scenario: Refunds are not blindly retried
- **WHEN** a v3 refund request fails in a way that may have reached TaxCloud (e.g. timeout after send)
- **THEN** the request is not retried, so the refund cannot be booked twice

### Requirement: Order cancellation reverses the capture via full refund

For a REST-selected store, reversing a canceled order's capture SHALL submit a v3 full-order refund (all lines) against the captured order and report success only when TaxCloud accepts it.

#### Scenario: Canceled order is fully refunded
- **WHEN** an uninvoiced captured order on a REST-selected store is canceled
- **THEN** a v3 refund covering the entire order is submitted and the operation reports success on acceptance

### Requirement: Order details are fetched from the v3 order resource

For a REST-selected store, fetching order details SHALL read the v3 order (including its refunds) and expose at minimum whether the order exists, its completion state, and its refund history, in the shape existing callers consume. A missing order SHALL be reported as not-found (null), distinct from errors, which are logged with TaxCloud's reason.

#### Scenario: Existing order returns details
- **WHEN** order details are requested for an order captured via v3
- **THEN** the order's completion date and refund records are returned

#### Scenario: Unknown order returns null
- **WHEN** order details are requested for an order TaxCloud does not know
- **THEN** the operation returns null and callers treat the order as never captured

### Requirement: Address verification executes over the v3 verify-address endpoint

For a REST-selected store, address verification SHALL submit the address parts to the v3 verify-address endpoint and return the normalized address in the same shape the SOAP implementation returns (Address1, Address2, City, State, Zip5, Zip4), so transport-unaware callers behave identically. Verification failures SHALL return false, leaving the caller's address unchanged. Successful verifications SHALL be cached per store.

#### Scenario: Verified address is normalized and cached
- **WHEN** an address is verified successfully on a REST-selected store
- **THEN** the normalized address is returned in the established shape and cached, and a repeat verification within the cache lifetime does not call the v3 API

#### Scenario: Unverifiable address returns false
- **WHEN** the v3 API cannot verify the address
- **THEN** the operation returns false and the original address remains in use

### Requirement: Exemption certificates are validated via the v3 exemption-certificates endpoint

For a REST-selected store, validating a customer's exemption certificate SHALL fetch the customer's certificates from the v3 API on the store's account, and return the certificate identifier only when the certificate exists, is enabled, and covers the destination state; otherwise null. The per-customer certificate result SHALL be cached as the SOAP path caches it, keyed so that different stores' accounts never share entries.

#### Scenario: Certificate covering the destination state validates
- **WHEN** the customer's certificate lists the destination state among its covered states
- **THEN** the certificate identifier is returned and applied to the lookup

#### Scenario: Certificate not covering the state is rejected
- **WHEN** the certificate does not list the destination state
- **THEN** null is returned and the lookup proceeds without exemption

### Requirement: v3 errors and retries follow the established outcome semantics

REST operations SHALL map v3 HTTP outcomes onto the module's established error semantics: transport failures, 5xx responses, and rate-limit (429) responses are retryable per the existing retry policy for idempotent operations; other 4xx responses are terminal; an unauthorized response on a Bearer-authenticated call SHALL invalidate the cached token and retry once with a fresh token. Every failure SHALL be logged through the store-gated logger with credentials and tokens redacted, and advanced logging SHALL record request and response bodies at debug level.

#### Scenario: Rate-limited idempotent call retries
- **WHEN** a lookup receives a 429 response
- **THEN** the call is retried per the retry policy before reporting failure

#### Scenario: Revoked token retries once
- **WHEN** an operation authenticated with a cached Bearer token receives 401
- **THEN** the token cache for that scope is invalidated, a fresh token is exchanged, and the call is retried exactly once

#### Scenario: Terminal client error does not retry
- **WHEN** an operation receives a 422 validation error
- **THEN** the call is not retried and the response detail is logged

### Requirement: REST operations are store-scoped

Every REST operation SHALL resolve its connection identifier, credentials, auth mode, endpoint, TICs, cache entries, fallback setting, and logging gate against the store of the entity being processed (quote, order, credit memo) or the explicit store argument — never the ambient store — matching the module-wide store-scoping policy.

#### Scenario: Entity store wins over ambient store
- **WHEN** an order belonging to store B is captured while the ambient store is A, and stores A and B have different TaxCloud connections
- **THEN** the v3 order is created on store B's connection with store B's credentials
