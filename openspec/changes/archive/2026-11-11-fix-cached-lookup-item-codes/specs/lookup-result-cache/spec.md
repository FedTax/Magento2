# Spec Delta

## Purpose

Defines how a cached TaxCloud tax lookup result is applied to the cart being priced, so that reusing a result never changes the tax a cart line is charged, on either the V1 SOAP or the V3 REST transport.

## ADDED Requirements

### Requirement: A cached lookup result taxes each cart line as the live result did
When a tax lookup is answered from the cache, each product line of the cart SHALL receive the tax amount TaxCloud returned for the line at the same position in the originally cached request, and shipping SHALL receive the cached shipping tax. The outcome SHALL NOT depend on the internal labels Magento assigns to cart lines during a request, on either transport.

#### Scenario: Same cart, different line labels
- **WHEN** a lookup is cached while the cart's only product line carries one internal label, and an identical lookup later runs with that line carrying a different label
- **THEN** the cached result is used without calling TaxCloud, and the line is charged the same product tax as in the first lookup

#### Scenario: A cart priced twice in one request
- **WHEN** one request prices the same cart through two separately loaded copies, as when a guest cart is merged into a customer's saved cart at login
- **THEN** the cart carries TaxCloud's tax for each product line as well as for shipping after the second pricing, and an order placed from it is charged the same

#### Scenario: Several lines keep their own amounts
- **WHEN** a cached result for a cart with several product lines is reused under different internal labels
- **THEN** each line is charged the amount returned for its own position, not a neighbouring line's amount

#### Scenario: Same labels behave as before
- **WHEN** an identical lookup runs with the same internal labels as the cached one
- **THEN** the result is the same as the live result that was cached

#### Scenario: Both transports
- **WHEN** the store uses V1 SOAP or V3 REST
- **THEN** a cached lookup result is applied by line position in the same way

### Requirement: Cache entries that cannot be mapped to cart lines are not served
A cached lookup entry that does not record tax by line position SHALL be treated as absent, so the lookup is performed live and its result replaces the entry.

#### Scenario: Entry written before line positions were recorded
- **WHEN** a lookup finds a cache entry stored by an earlier version of the extension
- **THEN** TaxCloud is called, the cart is taxed from the live result, and the entry is replaced

#### Scenario: Unreadable entry
- **WHEN** a lookup finds a cache entry whose content is not a usable result
- **THEN** it is treated as a miss rather than taxing the cart at zero
