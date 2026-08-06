## Purpose

Provides extension points around the REST tax operations: before/after events carrying v3-shaped request and response payloads that observers can inspect and mutate, keeping the REST path as customizable as the SOAP path without forcing v1 payload shapes onto v3 requests.

## Requirements

### Requirement: REST operations dispatch mutable before/after events

Each REST tax operation SHALL dispatch a `taxcloud_rest_*_before` event carrying the v3 request payload before calling the API, and a `taxcloud_rest_*_after` event carrying the v3 response afterward. Observers SHALL be able to replace the payload, and the operation SHALL use the (possibly) mutated payload. The event set SHALL cover lookup, capture, refund (shared by credit-memo refunds and cancellation reversal, as the SOAP `taxcloud_returned_*` events are shared today), and address verification, each with the same contextual entities the corresponding SOAP events provide (quote, order, credit memo, customer, address).

#### Scenario: Before-event mutation reaches the API
- **WHEN** an observer of a REST before-event modifies the request payload
- **THEN** the v3 API call carries the modified payload

#### Scenario: After-event mutation reaches the caller
- **WHEN** an observer of a REST after-event modifies the response payload
- **THEN** the operation processes the modified response

#### Scenario: Payloads never contain credentials
- **WHEN** any REST event is dispatched
- **THEN** its payload contains no API keys, connection identifiers used as secrets, or Bearer tokens (v3 authentication lives in transport headers, not the payload)

### Requirement: SOAP events are unaffected by the REST path

The existing `taxcloud_*` events SHALL continue to fire with unchanged SOAP-shaped payloads on SOAP-selected stores, and SHALL NOT fire for operations executed over REST. The `taxcloud_rest_*` events SHALL NOT fire on the SOAP path.

#### Scenario: REST store fires only REST events
- **WHEN** a lookup runs for a REST-selected store
- **THEN** `taxcloud_rest_lookup_before`/`_after` fire and `taxcloud_lookup_before`/`_after` do not

#### Scenario: SOAP store fires only SOAP events
- **WHEN** a lookup runs for a SOAP-selected store
- **THEN** the existing SOAP events fire exactly as before this change and no REST events fire

### Requirement: In-quote address verification works on both transports

The module's own address-verification-during-lookup behavior (when enabled for the store) SHALL hook the REST lookup before-event as it hooks the SOAP lookup before-event, replacing the request's destination with the verified address in the payload shape of that transport, so enabling REST does not silently disable in-quote address verification.

#### Scenario: REST lookup destination is verified
- **WHEN** address verification is enabled for a REST-selected store and a lookup is performed
- **THEN** the v3 cart request's destination is the verified, normalized address
