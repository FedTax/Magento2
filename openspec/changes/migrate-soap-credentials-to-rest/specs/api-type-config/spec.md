## MODIFIED Requirements

### Requirement: V3 credential fields shown only for REST
The configuration SHALL provide two V3 credential fields, visible only when the API Type selected in the admin form is `rest` (and the module is enabled): "API Key" at `tax/taxcloud_settings/rest_api_key` and "Connection ID" at `tax/taxcloud_settings/rest_connection_id`. Both SHALL be configurable per scope. The API Key SHALL be stored encrypted and displayed obscured in the admin form. The Connection ID SHALL be required; the API Key SHALL be optional, because a scope with V1 credentials authenticates through the automatic exchange when no key is saved, and its helper text SHALL say the field may stay empty in that case.

#### Scenario: REST selected shows V3 fields
- **WHEN** API Type is set to "V3 REST" in the admin form
- **THEN** the API Key and Connection ID fields are visible, the Connection ID is required, and helper text explains where each value comes from (Developer → API for the key, Integrations → Custom API for the Connection ID) and that the API Key may stay empty when V1 credentials are configured

#### Scenario: Migrated scope saves without an API Key
- **WHEN** an admin saves the configuration with API Type "V3 REST", a Connection ID present, and the API Key field empty
- **THEN** the form saves successfully — an empty API Key does not fail validation

#### Scenario: SOAP selected hides V3 fields
- **WHEN** API Type is set to "V1 SOAP (legacy)" in the admin form
- **THEN** the API Key and Connection ID fields are hidden and do not block saving as required fields

#### Scenario: REST API key is stored encrypted
- **WHEN** a V3 API Key is saved
- **THEN** its value is encrypted at rest in configuration storage and rendered obscured when the form is reloaded
