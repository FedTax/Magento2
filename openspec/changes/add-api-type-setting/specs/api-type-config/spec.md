## Purpose

Lets merchants choose which TaxCloud API generation (V1 SOAP or V3 REST) the module uses per scope, and enter the credential set that matches that generation, with safe defaults for both new and upgraded installs.

## ADDED Requirements

### Requirement: API Type setting
The admin configuration SHALL provide an "API Type" select field at `tax/taxcloud_settings/api_type` with exactly two options: `soap` labeled "V1 SOAP (legacy)" and `rest` labeled "V3 REST". The field SHALL be configurable at default, website, and store view scope.

#### Scenario: Field is present with both options
- **WHEN** an admin opens Stores → Configuration → Sales → Tax → TaxCloud Settings with the module enabled
- **THEN** the API Type field is shown with the options "V1 SOAP (legacy)" and "V3 REST"

#### Scenario: Per-scope override
- **WHEN** an admin switches the configuration scope to a website or store view
- **THEN** the API Type field can be overridden for that scope independently of the default scope

### Requirement: Default is V3 REST on fresh installs
On an installation with no previously saved TaxCloud V1 credentials, the API Type SHALL default to `rest`.

#### Scenario: New install defaults to REST
- **WHEN** the module is installed on a system where no `tax/taxcloud_settings/api_id` value exists in any scope
- **THEN** the API Type resolves to `rest` in every scope without any admin action

### Requirement: Existing installs are pinned to V1 SOAP on upgrade
When the module is upgraded on an installation that already has a saved `tax/taxcloud_settings/api_id` value in any scope, the upgrade SHALL persist `api_type` = `soap` at the default scope so that existing integrations keep using SOAP until an admin explicitly switches. The pinning SHALL run exactly once and SHALL NOT overwrite an `api_type` value that has already been saved.

#### Scenario: Upgrade with existing credentials keeps SOAP
- **WHEN** the module is upgraded on a system where `api_id` is saved in at least one scope and `api_type` has never been saved
- **THEN** `api_type` = `soap` is persisted at default scope and all scopes without an explicit override resolve to `soap`

#### Scenario: Upgrade without existing credentials
- **WHEN** the module is upgraded on a system where no `api_id` is saved in any scope
- **THEN** no `api_type` value is persisted and the config default `rest` applies

#### Scenario: Re-running setup does not clobber an admin choice
- **WHEN** setup runs again after an admin has saved `api_type` (any value, any scope)
- **THEN** the saved value is left unchanged

### Requirement: V1 credential fields shown only for SOAP
The `api_id` and `api_key` fields SHALL be visible only when the API Type selected in the admin form is `soap` (and the module is enabled). Their stored values SHALL NOT be deleted when the API Type is switched to `rest`.

#### Scenario: SOAP selected shows V1 fields
- **WHEN** API Type is set to "V1 SOAP (legacy)" in the admin form
- **THEN** the API ID and API Key fields are visible and required

#### Scenario: REST selected hides V1 fields
- **WHEN** API Type is set to "V3 REST" in the admin form
- **THEN** the API ID and API Key fields are hidden and do not block saving as required fields

### Requirement: V3 credential fields shown only for REST
The configuration SHALL provide two V3 credential fields, visible only when the API Type selected in the admin form is `rest` (and the module is enabled): "API Key" at `tax/taxcloud_settings/rest_api_key` and "Connection ID" at `tax/taxcloud_settings/rest_connection_id`. Both SHALL be configurable per scope. The API Key SHALL be stored encrypted and displayed obscured in the admin form.

#### Scenario: REST selected shows V3 fields
- **WHEN** API Type is set to "V3 REST" in the admin form
- **THEN** the API Key and Connection ID fields are visible and required, and helper text explains where each value comes from (Developer → API for the key, Integrations → Custom API for the Connection ID)

#### Scenario: SOAP selected hides V3 fields
- **WHEN** API Type is set to "V1 SOAP (legacy)" in the admin form
- **THEN** the API Key and Connection ID fields are hidden and do not block saving as required fields

#### Scenario: REST API key is stored encrypted
- **WHEN** a V3 API Key is saved
- **THEN** its value is encrypted at rest in configuration storage and rendered obscured when the form is reloaded

### Requirement: API type resolution is store-aware
Any programmatic read of the API type or of either credential set SHALL resolve against an explicitly provided store scope (the store of the entity being processed), never the ambient store.

#### Scenario: Resolution follows the entity's store
- **WHEN** module code resolves `api_type` or credentials while processing an entity belonging to store B while the ambient store is A
- **THEN** the values returned are those effective for store B, including values inherited from website or default scope
