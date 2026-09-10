## Purpose

Turns a working V1 SOAP configuration into a validated V3 REST configuration at upgrade time, per scope, so migrated merchants never have to hand-copy credentials — and are told immediately, and loudly, when a stored credential pair is no longer valid.

## ADDED Requirements

### Requirement: Every scope with V1 credentials is migrated
During setup upgrade, the system SHALL find every configuration scope (default, website, store view) whose stored V1 credentials (`api_id` and `api_key`, both non-blank, resolved at exactly that scope row — not via inheritance) exist, and for each such scope SHALL validate the pair against the TaxCloud credential exchange endpoint and persist `rest_connection_id` equal to that scope's V1 `api_key` at the same scope.

#### Scenario: Default-scope credentials are migrated
- **WHEN** setup upgrade runs on an install whose only V1 credentials are at the default scope and the exchange accepts them
- **THEN** `rest_connection_id` = that `api_key` is persisted at the default scope

#### Scenario: Multiple scopes with distinct credentials are each migrated
- **WHEN** the default scope and a store-view scope each hold their own V1 credential pair and both pairs are accepted
- **THEN** each scope receives its own `rest_connection_id` equal to its own `api_key`, at its own scope row

#### Scenario: A scope without its own credential row is untouched
- **WHEN** a store view has no `api_id`/`api_key` rows of its own (it inherits from default)
- **THEN** no `rest_connection_id` row is written at that store's scope (it inherits the migrated default value)

### Requirement: Existing REST configuration is never overwritten
A scope that already has a `rest_connection_id` value SHALL be skipped without contacting TaxCloud for that scope, and its stored value SHALL remain byte-identical.

#### Scenario: Re-running the migration is a no-op
- **WHEN** the migration runs again after a previous successful run (or after an admin has saved a `rest_connection_id` manually)
- **THEN** no scope with an existing `rest_connection_id` is modified and no exchange call is made for those scopes

### Requirement: Validation failure aborts the upgrade with an actionable message
If the exchange endpoint rejects a credential pair, or cannot be reached at all, the migration SHALL abort the setup upgrade with an error that names the failing scope (scope type + id/code), states whether the pair was rejected or the endpoint unreachable, and tells the merchant what to fix. Credential values SHALL NOT appear in the error, in logs, or in any output.

#### Scenario: Rejected credentials fail the upgrade
- **WHEN** the exchange endpoint answers a 4xx for one scope's credential pair
- **THEN** setup upgrade aborts with an error identifying that scope and stating the V1 credentials there are invalid, and no partial `rest_connection_id` is left written for that scope

#### Scenario: Unreachable endpoint fails the upgrade
- **WHEN** the exchange endpoint cannot be reached (network error, timeout)
- **THEN** setup upgrade aborts with an error saying the validation could not be performed and naming the endpoint, without credential values anywhere in the output

#### Scenario: Scopes validated before the failure stay migrated
- **WHEN** the second of three scopes fails validation
- **THEN** the abort message names the failing scope, and scopes already migrated in this run keep their written `rest_connection_id` (the patch is re-runnable; completed scopes are skipped on the next run)

### Requirement: Deliberate skip via environment variable
An operator SHALL be able to skip the credential migration during setup upgrade by setting a documented environment variable, in which case the migration writes nothing, fails nothing, and logs that it was skipped. The migration SHALL be re-runnable on demand through a CLI command that executes the identical logic (same validation, same loud failure, same never-overwrite guarantee).

#### Scenario: Escape hatch skips the migration
- **WHEN** setup upgrade runs with the skip variable set (e.g. an offline build environment)
- **THEN** the upgrade completes without contacting TaxCloud and without writing any `rest_connection_id`, and the skip is logged

#### Scenario: CLI command completes a skipped migration
- **WHEN** the operator later runs the migration CLI command without the skip variable
- **THEN** every scope with V1 credentials and no `rest_connection_id` is validated and migrated exactly as the upgrade path would have done

### Requirement: Exchange endpoint is configurable
The exchange endpoint host SHALL default to TaxCloud's production exchange service and SHALL be overridable via configuration (no admin field), so staging installs and a vendor-side host change need no code release.

#### Scenario: Overridden endpoint is used
- **WHEN** a non-default exchange endpoint is configured and the migration (or any Bearer-mode exchange) runs
- **THEN** requests go to the configured endpoint
