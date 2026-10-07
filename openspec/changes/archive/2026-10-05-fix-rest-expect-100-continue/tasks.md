## 1. Final-status HTTP client

- [x] 1.1 Add `Model/Gateway/Rest/FinalStatusCurl.php`: extends core `Curl` and overrides only `parseHeaders()`. A status line arriving after headers were already counted resets `_headerCount` and `_responseHeaders` before delegating to the parent (design D2).
- [x] 1.2 Unit test `FinalStatusCurlTest`, driving `parseHeaders()` with header-callback lines on the real parent class:
  - a plain `200` is unchanged
  - `100 Continue` → `200` reports 200, with only the final headers
  - `100 Continue` → `422` reports 422
  - `103 Early Hints` (with a `Link` header) → `200` drops `Link`
  - the callback returns the line length
  - core `Curl` reports 100 for the same `100` → `200` sequence, pinning why the subclass exists

## 2. Wire it into the v3 transport

- [x] 2.1 `RestClient`: take `FinalStatusCurlFactory` in the constructor (design D3), and add `Expect: ''` to the base header set in `send()` (design D1).
- [x] 2.2 `TokenExchange`: same constructor type change, and add `Expect: ''` alongside its other headers.
- [x] 2.3 Switch the existing unit tests that build these classes (`RestClientTest`, `RestClientRequestTest`, `RestClientScopePingTest`, `TokenExchangeTest`, `UserAgentTransportParityTest`) to mock `FinalStatusCurlFactory`/`FinalStatusCurl`.
- [x] 2.4 New unit tests:
  - `RestClient` sends an empty `Expect` header on POST, GET and DELETE requests, and on the admin ping
  - `TokenExchange` sends it on the exchange
  - a `100`-then-`422`-shaped outcome is surfaced as the final status

## 3. Verification

- [x] 3.1 Self-audit new and changed tests for PHPUnit 9.5/10.5/12.5 compatibility (no `addMethods`, `withConsecutive`, `at()`, or renamed assertions).
- [x] 3.2 Run `make lint`, `make phpstan` and the unit suite against the worktree's code; judge each by exit code.
- [x] 3.3 Live check on libcurl 7.61.1 (AlmaLinux 8 + PHP 8.4 container) with the 1.5.1 code: the order-sized 10-line cart reads 200 where 1.5.0 read 100.
- [x] 3.4 Propose integration/e2e coverage to the maintainer rather than adding it. The suites run on modern libcurl, which never sends `Expect` at these sizes.

## 4. Documentation and release

- [x] 4.1 Documentation check: confirm no `docs/` page describes behavior this changes, and that the README needs no developer-material update. Record the conclusion.
- [x] 4.2 Bump the version to 1.5.1 in `composer.json` and `etc/module.xml` (`setup_version`); the 1.5 README carries no version line. `ModuleVersionConsistencyTest` stays green.
- [x] 4.3 Add a `## 1.5.1` CHANGELOG entry: a *Fixed* item for the HTTP 100 misread, with upgrade notes (`setup:upgrade`; `setup:di:compile` in production for the constructor change).
