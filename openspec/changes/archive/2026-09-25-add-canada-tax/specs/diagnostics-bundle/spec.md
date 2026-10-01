## MODIFIED Requirements

### Requirement: A live probe reports connectivity separately from credentials
When enabled (the default), generation SHALL run a read-only canned Lookup and VerifyAddress against a fixed test address for each distinct TaxCloud configuration in scope, recording per call the URL, HTTP status, duration, outcome and error, with DNS resolution and TLS handshake recorded separately and REST authentication mode and token acquisition recorded. For a configuration whose stores have Canadian tax in effect, the probe SHALL also run the Canada access check and record its outcome and message as a separate call, so a missing Canadian account entitlement is visible in the bundle. The store's API timeout SHALL apply and a probe failure SHALL NOT fail generation.

#### Scenario: Blocked outbound connection
- **WHEN** the TLS handshake to the TaxCloud endpoint fails
- **THEN** the API calls are recorded as skipped for that reason and the bundle is still generated

#### Scenario: Canadian tax on for an account without Canada
- **WHEN** a store with Canadian tax in effect is probed and its account refuses Canadian lookups
- **THEN** the probe records the Canada access check as failed with a message to contact TaxCloud support, and the summary lists it as a blocker
