## Purpose

Lets scopes that were migrated from V1 SOAP transact with the v3 REST API immediately — authenticating with short-lived Bearer tokens exchanged from their V1 credentials — while scopes with a portal-generated API key keep using it.

## ADDED Requirements

### Requirement: Auth mode is derived per scope
For every v3 REST request, the transport SHALL select the authentication mode from the configuration effective for the store being processed (never the ambient store): if a `rest_api_key` is set (directly or by inheritance), requests SHALL authenticate with the `X-API-KEY` header; otherwise, if V1 credentials are available for that store, requests SHALL authenticate with an `Authorization: Bearer` token exchanged from those credentials. If neither is available, the request SHALL fail with a configuration error naming what is missing, without contacting TaxCloud.

#### Scenario: X-API-KEY wins when present
- **WHEN** a store has both a saved `rest_api_key` and V1 credentials
- **THEN** REST requests for that store use `X-API-KEY` and no token exchange happens

#### Scenario: Migrated store uses Bearer
- **WHEN** a store has V1 credentials and a `rest_connection_id` but no `rest_api_key`
- **THEN** REST requests for that store use `Authorization: Bearer <token>` obtained by exchanging that store's V1 credentials

#### Scenario: Two stores run different modes side by side
- **WHEN** store A has a `rest_api_key` and store B has only migrated V1 credentials
- **THEN** store A's requests use X-API-KEY and store B's use Bearer, regardless of which store is ambient

#### Scenario: No credentials at all
- **WHEN** a store has neither a `rest_api_key` nor V1 credentials
- **THEN** the request fails locally with a configuration error and no HTTP call is made

### Requirement: Token exchange
Bearer tokens SHALL be obtained by posting the store's V1 `api_id`/`api_key` pair to the configurable exchange endpoint. A response without an access token, or an error status, SHALL be treated as an authentication failure for that request. Credential values and token contents SHALL NOT appear in logs or error messages.

#### Scenario: Successful exchange
- **WHEN** the exchange endpoint returns an access token with a validity timestamp
- **THEN** the transport uses it as the Bearer token for the request

#### Scenario: Exchange rejection surfaces as auth failure
- **WHEN** the exchange endpoint rejects the V1 pair
- **THEN** the REST operation reports an authentication failure (equivalent to a 401 outcome) without retrying in a loop

### Requirement: Tokens are cached and refreshed
Exchanged tokens SHALL be cached per credential pair/scope until shortly before their reported expiry, so repeated REST calls do not re-exchange on every request. A cached token that TaxCloud answers with 401 SHALL be discarded and the exchange performed once more for that request; a second 401 SHALL surface as an authentication failure.

#### Scenario: Second call reuses the cached token
- **WHEN** two REST calls for the same store happen within the token's validity window
- **THEN** the exchange endpoint is called at most once

#### Scenario: Expired token is refreshed
- **WHEN** a REST call happens after the cached token's expiry cutoff
- **THEN** a fresh token is exchanged before the call and the cache updated

#### Scenario: Revoked token retries once
- **WHEN** TaxCloud answers 401 to a request carrying a cached (unexpired) token
- **THEN** the token is discarded, a fresh exchange is performed, the request retried once, and a second 401 is reported as an authentication failure
