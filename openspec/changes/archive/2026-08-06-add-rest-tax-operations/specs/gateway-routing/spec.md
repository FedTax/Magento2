## ADDED Requirements

### Requirement: REST-selected stores dispatch to the REST transport

When `api_type` resolves to `rest` for the store of the entity being processed, the routing layer SHALL dispatch every gateway operation (lookup, order capture, credit-memo refund, cancellation reversal, order details, address verification, exemption validation) to the REST implementation. When `api_type` resolves to `soap`, dispatch to the SOAP implementation SHALL remain byte-for-byte unchanged.

#### Scenario: REST store transacts over v3
- **WHEN** `api_type` resolves to `rest` for a store and any gateway operation is performed for an entity of that store
- **THEN** the operation executes over the v3 REST API

#### Scenario: SOAP store is unaffected
- **WHEN** `api_type` resolves to `soap` for a store
- **THEN** every gateway operation behaves exactly as before this change

#### Scenario: Mixed fleet routes per entity store
- **WHEN** store A selects `soap` and store B selects `rest`, and operations are performed for entities of both stores in one request
- **THEN** store A's operations execute over SOAP and store B's over REST, each resolved from the entity's store, never the ambient one

## REMOVED Requirements

### Requirement: SOAP-only dispatch until REST operations exist

**Reason**: The REST transport now implements every gateway operation, so the transitional rule that routed REST-selected stores to SOAP no longer applies.

**Migration**: Stores with `api_type = rest` begin transacting over the v3 REST API on upgrade with no configuration change. Operators who selected "V3 REST" ahead of time and are not ready should set `api_type` back to `soap` before upgrading.
