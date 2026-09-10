## Purpose

Keeps every consumer of TaxCloud operations unaware of the underlying transport by dispatching each gateway call to the implementation selected by the store-scoped API Type setting.

## Requirements

### Requirement: Gateway calls are dispatched by store-scoped API type
All TaxCloud gateway operations (lookup, order capture/return, address verification, exemption handling, and certificate management) SHALL be dispatched through a single routing layer that selects the transport implementation based on the `api_type` effective for the store of the entity being processed — never the ambient store. Call sites SHALL depend only on the existing gateway interfaces and SHALL NOT reference a concrete transport.

#### Scenario: Dispatch follows the entity's store
- **WHEN** a gateway operation is invoked for an entity belonging to store B while the ambient store is A
- **THEN** the transport is chosen by the `api_type` effective for store B (including values inherited from website or default scope)

#### Scenario: Call sites are transport-unaware
- **WHEN** the set of available transports changes (e.g. REST operations are added in a later release)
- **THEN** no call site outside the routing layer requires modification

#### Scenario: Certificate management routes like every other operation
- **WHEN** a customer's certificates are listed, created or deleted for an entity of a given store
- **THEN** the transport is chosen by that store's `api_type`, by the same routing layer that dispatches tax lookups

### Requirement: REST-selected stores dispatch to the REST transport
When `api_type` resolves to `rest` for the store of the entity being processed, the routing layer SHALL dispatch every gateway operation (lookup, order capture, credit-memo refund, cancellation reversal, order details, address verification, exemption validation, and certificate listing, creation and deletion) to the REST implementation. When `api_type` resolves to `soap`, dispatch to the SOAP implementation SHALL remain byte-for-byte unchanged.

#### Scenario: REST store transacts over v3
- **WHEN** `api_type` resolves to `rest` for a store and any gateway operation is performed for an entity of that store
- **THEN** the operation executes over the v3 REST API

#### Scenario: SOAP store is unaffected
- **WHEN** `api_type` resolves to `soap` for a store
- **THEN** every gateway operation behaves exactly as before this change

#### Scenario: Mixed fleet routes per entity store
- **WHEN** store A selects `soap` and store B selects `rest`, and operations are performed for entities of both stores in one request
- **THEN** store A's operations execute over SOAP and store B's over REST, each resolved from the entity's store, never the ambient one

