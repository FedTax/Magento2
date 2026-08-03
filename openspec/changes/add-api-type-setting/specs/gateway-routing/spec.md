## Purpose

Keeps every consumer of TaxCloud operations unaware of the underlying transport by dispatching each gateway call to the implementation selected by the store-scoped API Type setting.

## ADDED Requirements

### Requirement: Gateway calls are dispatched by store-scoped API type
All TaxCloud gateway operations (lookup, order capture/return, address verification, exemption handling) SHALL be dispatched through a single routing layer that selects the transport implementation based on the `api_type` effective for the store of the entity being processed — never the ambient store. Call sites SHALL depend only on the existing gateway interfaces and SHALL NOT reference a concrete transport.

#### Scenario: Dispatch follows the entity's store
- **WHEN** a gateway operation is invoked for an entity belonging to store B while the ambient store is A
- **THEN** the transport is chosen by the `api_type` effective for store B (including values inherited from website or default scope)

#### Scenario: Call sites are transport-unaware
- **WHEN** the set of available transports changes (e.g. REST operations are added in a later release)
- **THEN** no call site outside the routing layer requires modification

### Requirement: SOAP-only dispatch until REST operations exist
While the REST transport implements no tax operations, the routing layer SHALL dispatch all gateway operations to the SOAP implementation regardless of the selected `api_type`, so that selecting "V3 REST" causes no behavior change in tax calculation, capture, returns, address verification, or exemptions.

#### Scenario: REST-selected store still transacts over SOAP
- **WHEN** `api_type` resolves to `rest` for a store and a tax lookup or capture is performed for that store
- **THEN** the operation is executed by the SOAP implementation, identically to a `soap`-selected store

#### Scenario: No regression for SOAP stores
- **WHEN** `api_type` resolves to `soap` for a store
- **THEN** every gateway operation behaves exactly as before this change
