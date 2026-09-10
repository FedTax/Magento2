## MODIFIED Requirements

### Requirement: Exemption certificates are validated via the v3 exemption-certificates endpoint

For a REST-selected store, validating a customer's exemption certificate SHALL fetch the customer's certificates from the v3 API on the store's account, and return the certificate identifier only when the certificate exists, is enabled, and covers the destination state; otherwise null. The per-customer certificate result SHALL be cached as the SOAP path caches it, keyed so that different stores' accounts never share entries.

A certificate's covered states SHALL be taken from the two-letter state abbreviation carried on each state entry of the v3 certificate, as the v3 exemption-certificate schema defines it. State entries carrying no usable abbreviation SHALL be discarded rather than causing the whole certificate to be treated as covering nothing. The destination state SHALL be matched against those abbreviations.

#### Scenario: Certificate covering the destination state validates
- **WHEN** the customer's certificate lists the destination state among its covered states
- **THEN** the certificate identifier is returned and applied to the lookup

#### Scenario: Certificate not covering the state is rejected
- **WHEN** the certificate does not list the destination state
- **THEN** null is returned and the lookup proceeds without exemption

#### Scenario: Covered states are read as the v3 schema shapes them
- **WHEN** the v3 API returns a certificate whose covered states are the structured state entries the exemption-certificate schema defines, each carrying a two-letter abbreviation
- **THEN** every one of those abbreviations is recognized as a covered state, so a certificate covering the destination state validates rather than being read as covering nothing

#### Scenario: Unusable state entries do not discard the rest
- **WHEN** a certificate's covered states include an entry with no usable two-letter abbreviation alongside entries that have one
- **THEN** the usable abbreviations are still recognized as covered states, and the destination state matches if it is among them

#### Scenario: Certificate with no covered states is rejected
- **WHEN** the certificate is found and enabled but lists no covered state
- **THEN** null is returned and the lookup proceeds without exemption
