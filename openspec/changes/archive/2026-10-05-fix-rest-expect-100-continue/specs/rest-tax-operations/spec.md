## ADDED Requirements

### Requirement: The outcome of a v3 call is the server's final response

Every v3 REST request the module sends SHALL have its outcome — status, headers and body — taken from the server's final response. This covers every tax operation, the admin credential test, and the exchange of V1 credentials for a short-lived token. An interim `1xx` response SHALL NOT be reported as the outcome of a call, and its headers SHALL NOT be reported as the final response's headers. This SHALL hold regardless of the size of the request body, the HTTP version negotiated, or the version of the server's HTTP library.

v3 requests SHALL NOT ask the server for an interim `100 Continue` response before sending their body: the body is sent with the request. The SOAP transport is not covered by this requirement.

#### Scenario: Large cart lookup on an HTTP/1.1 host succeeds
- **WHEN** a tax lookup for a cart whose request body exceeds 1,024 bytes is sent from a host whose HTTP library talks HTTP/1.1 to TaxCloud and would ordinarily ask for `100 Continue` on bodies that size
- **THEN** the request carries no `100-continue` expectation, TaxCloud's successful response is read as a success, and the per-line tax it returns is applied to the quote instead of falling back to Magento's rates or to no tax

#### Scenario: Interim response before the final status is discarded
- **WHEN** the server or an intermediary sends one or more interim `1xx` responses (such as `100 Continue` or `103 Early Hints`) before the final `200` response
- **THEN** the call's status is `200`, its headers are those of the `200` response only, and its body is processed as a success

#### Scenario: Interim response before an error is discarded
- **WHEN** an interim `100 Continue` precedes a final `422` validation error
- **THEN** the call is reported as a `422` with the error detail from the body, follows the established terminal-error semantics, and is logged as such — never as `HTTP 100`

#### Scenario: Credential exchange with a large or slow-path request
- **WHEN** V1 credentials are exchanged for a Bearer token and the server sends an interim response before the final one
- **THEN** the exchange succeeds or fails based on the final response's status alone

#### Scenario: Small requests are unaffected
- **WHEN** a v3 request is small enough that no interim response is ever involved
- **THEN** its status, headers and body are reported exactly as before
