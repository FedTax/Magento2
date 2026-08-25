## MODIFIED Requirements

### Requirement: Exemption certificates are validated via the v3 exemption-certificates endpoint

For a REST-selected store, the v3 API SHALL supply the certificate operations the `exemption-certificates` capability defines: listing a customer's certificates by their TaxCloud customer identity, creating a certificate on the store's connection, and deleting one. Which certificate then applies to an order, and whether it may be applied at all, is decided by that capability rather than here.

A certificate's covered states SHALL be taken from the two-letter state abbreviation carried on each state entry of the v3 certificate, as the v3 exemption-certificate schema defines it. State entries carrying no usable abbreviation SHALL be discarded rather than causing the whole certificate to be treated as covering nothing.

Where the v3 representation of a certificate omits detail the v1 representation carries, or returns a value outside the range its own schema documents, the omission SHALL be carried through as absent detail rather than substituted — a REST store shows less about a certificate than a SOAP store, and never shows something untrue about it.

The listing SHALL be requested for the store's own connection, so a TaxCloud account carrying several connections does not expose one store's certificates to another.

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

#### Scenario: v3 supplies the certificate operations
- **WHEN** a REST-selected store lists, creates or deletes a customer's certificates
- **THEN** each is performed against the v3 API and reports success only when v3 accepts it

#### Scenario: Detail v3 does not carry is reported as absent
- **WHEN** a certificate is read over v3 and the representation omits detail the v1 representation would carry, or reports a value outside its documented range
- **THEN** that detail is absent rather than replaced by a substitute value

#### Scenario: Listing is confined to the store's connection
- **WHEN** certificates are listed on an account carrying more than one connection
- **THEN** only certificates belonging to the store's own connection are returned
