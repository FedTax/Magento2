## ADDED Requirements

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
