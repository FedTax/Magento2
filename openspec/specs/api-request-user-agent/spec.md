## Purpose

Gives every request the module sends to TaxCloud a stable identity — which extension version, on which Magento version and edition, on which PHP — so that support and diagnostics can attribute traffic to a concrete installation without asking the merchant.

## Requirements

### Requirement: Every outbound TaxCloud request is identified

Every HTTP request the module sends to TaxCloud SHALL carry a `User-Agent` header naming the extension, the Magento version, the Magento edition and the PHP version. This SHALL hold for both API generations and for every request type, including tax operations, address verification, exemption handling, order capture and returns, the admin credential test, the credential exchange performed during upgrade and at runtime, and the retrieval of the V1 service description document.

No request type SHALL be exempt: adding a new operation to either transport SHALL NOT require separately remembering to identify it.

#### Scenario: V3 REST operation is identified
- **WHEN** any v3 REST operation is performed against TaxCloud
- **THEN** the request carries a `User-Agent` header naming the extension, Magento version, Magento edition and PHP version

#### Scenario: V1 SOAP operation is identified
- **WHEN** any v1 SOAP operation is performed against TaxCloud
- **THEN** the request carries the same `User-Agent`, in place of the transport's default identification

#### Scenario: Service description retrieval is identified
- **WHEN** the module retrieves the V1 service description document
- **THEN** that retrieval carries the same `User-Agent` as the operations that follow it

#### Scenario: Credential exchange is identified
- **WHEN** V1 credentials are exchanged for a short-lived token, whether during an upgrade or during normal operation
- **THEN** the exchange request carries the same `User-Agent`

#### Scenario: Credential test is identified
- **WHEN** an administrator verifies credentials from the configuration screen, for either API generation
- **THEN** the verification request carries the same `User-Agent`

### Requirement: User-Agent composition and format

The `User-Agent` SHALL be composed of, in order: a product token identifying this extension with its version, a token identifying Magento with its version followed by the edition in parentheses, and a token identifying PHP with its version — separated by single spaces, in the form:

`TaxCloud-Magento2/<extension-version> Magento/<magento-version> (<edition>) PHP/<php-version>`

for example `TaxCloud-Magento2/1.4.0 Magento/2.4.7-p3 (Community) PHP/8.3.14`.

The header SHALL contain only these components. It SHALL NOT contain credentials, connection identifiers, store identifiers, merchant identifiers, customer data, or the selected API generation.

The composition SHALL be identical across every transport and every request, so that traffic from one installation is attributable to it regardless of which operation produced the request.

#### Scenario: Format is stable and parseable
- **WHEN** the `User-Agent` is produced on an installation whose component versions are all known
- **THEN** it matches the documented form exactly, with single-space separators and no trailing whitespace

#### Scenario: Edition is reported alongside the Magento version
- **WHEN** the installation is Adobe Commerce rather than Magento Open Source
- **THEN** the edition token reflects that, distinguishing the two installations at a glance

#### Scenario: The same identity is sent on both generations
- **WHEN** the same installation sends one v1 SOAP request and one v3 REST request
- **THEN** both carry a byte-identical `User-Agent`

#### Scenario: No sensitive material is disclosed
- **WHEN** any request is sent
- **THEN** the `User-Agent` contains no credential, connection identifier, or customer data, and is therefore safe to log in full

### Requirement: Unknown components degrade without breaking the header

If any component version or the edition cannot be determined, the `User-Agent` SHALL still be well-formed: the affected component SHALL be reported as `unknown` in place of its value, and every other component SHALL be reported normally. A component that cannot be determined SHALL NOT produce an empty token, a missing separator, a malformed header, or a failed request.

Determining the `User-Agent` SHALL NOT be able to fail an API request: any error while assembling it SHALL result in a degraded but valid header rather than a propagated failure.

#### Scenario: One component cannot be determined
- **WHEN** the Magento version cannot be determined but the others can
- **THEN** the header is sent with `unknown` in place of that version and correct values elsewhere, and the request proceeds normally

#### Scenario: Assembly failure does not fail the request
- **WHEN** an error occurs while assembling the `User-Agent`
- **THEN** the request is still sent, with a valid header naming whatever components could be determined

### Requirement: Reported extension version is the true installed version

The extension version reported in the `User-Agent` SHALL be the version this installation actually declares, and the module's version declarations SHALL agree with one another so that a single version is unambiguously reported.

A release SHALL NOT be able to report a version other than the one it ships: a discrepancy between the module's version declarations SHALL be detectable automatically rather than discovered from support traffic.

#### Scenario: Reported version matches the shipped release
- **WHEN** the module of a given released version sends a request
- **THEN** the extension token reports that same version

#### Scenario: Version declarations are prevented from drifting
- **WHEN** the module's version declarations disagree with one another
- **THEN** this is reported as a failure by the automated checks, before release

### Requirement: Identification is installation-wide, not store-scoped

The `User-Agent` SHALL be determined by properties of the installation and SHALL NOT vary by store, website, or configuration scope. Requests made for entities belonging to different stores SHALL carry an identical `User-Agent`.

There SHALL be no administrative setting to disable, override, or customise the `User-Agent`.

#### Scenario: Different stores produce the same identity
- **WHEN** an operation is performed for an entity of store A and another for an entity of store B, on the same installation
- **THEN** both requests carry an identical `User-Agent`, including when the two stores use different API generations or credentials

#### Scenario: No configuration surface is added
- **WHEN** an administrator opens the TaxCloud configuration
- **THEN** no field to change or suppress the `User-Agent` is present
