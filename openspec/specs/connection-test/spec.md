## Purpose

Lets an admin verify the credentials of the currently selected API type against TaxCloud directly from the configuration form, before or after saving, with actionable feedback for each failure mode.

## Requirements

### Requirement: Test Connection button
The TaxCloud configuration form SHALL show a "Test Connection" button whenever the module is enabled, for both API types. Activating it SHALL verify the credentials of the API type currently selected in the form and SHALL report the outcome inline without a page reload:
- API Type `rest`: call TaxCloud's ping endpoint `GET /tax/connections/{connectionID}/ping` with the `X-API-KEY` and `Accept: application/json` headers.
- API Type `soap`: call the TaxCloud V1 SOAP `Ping` operation with the `api_id`/`api_key` credentials.

#### Scenario: Successful REST ping
- **WHEN** API Type is "V3 REST" and the admin triggers Test Connection with credentials TaxCloud accepts
- **THEN** an inline success message confirms the connection is working

#### Scenario: Successful SOAP ping
- **WHEN** API Type is "V1 SOAP (legacy)" and the admin triggers Test Connection with credentials TaxCloud accepts
- **THEN** an inline success message confirms the connection is working

#### Scenario: Selected type determines the call
- **WHEN** the admin switches API Type in the form and triggers Test Connection
- **THEN** the verification call matches the newly selected type, using that type's credential fields

### Requirement: Test uses the credentials as currently entered
The test SHALL use the credential values currently entered in the form, even if unsaved. If an encrypted credential field (V3 API Key) holds the obscured placeholder for an already-saved value, the test SHALL fall back to the saved (decrypted) value effective for the scope being edited, honoring scope inheritance from website and default.

#### Scenario: Unsaved credentials are tested
- **WHEN** the admin enters new credential values and triggers Test Connection without saving
- **THEN** the entered values are the ones used for the verification call

#### Scenario: Saved key with obscured placeholder
- **WHEN** the admin triggers Test Connection while the V3 API Key field still shows the obscured saved value
- **THEN** the saved value effective for the currently edited scope (including inherited values) is used

### Requirement: Failure modes are distinguished
The test SHALL map failures to distinct, actionable admin messages:
- REST: an authentication failure (HTTP 401) SHALL indicate the API Key is invalid or missing (generated under Developer → API); an unknown connection (HTTP 404) SHALL indicate the Connection ID is wrong or belongs to another account (Integrations → Custom API).
- SOAP: a Ping failure response SHALL indicate the API ID / API Key pair is invalid.
- Both: network errors, timeouts, and other non-success responses SHALL produce a generic failure message that includes the underlying reason.
Credential values SHALL never appear in messages or logs.

#### Scenario: Invalid REST API key
- **WHEN** the REST ping endpoint responds 401
- **THEN** the admin sees a message pointing at the API Key

#### Scenario: Unknown REST connection ID
- **WHEN** the REST ping endpoint responds 404
- **THEN** the admin sees a message pointing at the Connection ID

#### Scenario: Invalid SOAP credentials
- **WHEN** the SOAP Ping operation reports the credentials are not valid
- **THEN** the admin sees a message pointing at the API ID / API Key pair

#### Scenario: Endpoint unreachable
- **WHEN** the verification request times out or fails at the network level (either API type)
- **THEN** the admin sees a failure message with the transport-level reason, and no credential values are exposed

### Requirement: Test endpoint is protected
The admin route backing the button SHALL require an authenticated admin session with the same ACL resource that protects the TaxCloud configuration section, and SHALL reject requests with incomplete credentials for the selected type (blank required value and no saved fallback) with a validation message rather than calling TaxCloud.

#### Scenario: Unauthorized access is rejected
- **WHEN** the test route is requested without an admin session holding the tax configuration ACL
- **THEN** the request is denied and no call is made to TaxCloud

#### Scenario: Missing input short-circuits
- **WHEN** the admin triggers Test Connection with a required credential for the selected type absent (e.g. no Connection ID for REST, no API ID for SOAP)
- **THEN** a validation message is shown and no request is made to TaxCloud
### Requirement: Test Connection verifies Bearer-mode scopes
When API Type is `rest` and the scope being edited resolves to Bearer authentication (no `rest_api_key`, V1 credentials present), the Test Connection button SHALL verify the connection by exchanging the V1 credentials for a Bearer token and pinging with it — succeeding without any `rest_api_key` being entered or saved. Failure messages SHALL distinguish a rejected V1 pair (fix API ID / API Key) from an unknown Connection ID and from transport errors, and SHALL NOT contain credential values.

#### Scenario: Migrated scope tests successfully
- **WHEN** the admin triggers Test Connection on a REST scope that has only migrated credentials (V1 pair + `rest_connection_id`)
- **THEN** the ping is performed with Bearer authentication and an inline success message is shown

#### Scenario: Rejected exchange points at the V1 pair
- **WHEN** the exchange endpoint rejects the V1 credentials during a Bearer-mode test
- **THEN** the admin sees a failure message pointing at the API ID / API Key pair

#### Scenario: Entered X-API-KEY still takes precedence
- **WHEN** the admin enters a value in the API Key field and triggers Test Connection
- **THEN** the test uses X-API-KEY mode with the entered value, not the Bearer exchange
