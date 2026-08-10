## 1. Version declarations

- [x] 1.1 Bump `version` to `1.4.0` in `composer.json` (the value `PackageInfo` reports — see design D2)
- [x] 1.2 Bump `setup_version` to `1.4.0` in `etc/module.xml`
- [x] 1.3 Promote `CHANGELOG.md`'s `## Unreleased` heading to `## 1.4.0`

## 2. User-Agent builder

- [x] 2.1 Add the builder under `Model/Gateway/` with a single no-argument accessor returning the finished string, memoized in a property, and no `$store` parameter (design D1)
- [x] 2.2 Resolve the extension version via `PackageInfo::getVersion('Taxcloud_Magento2')` (design D2)
- [x] 2.3 Resolve the Magento version and edition via `ProductMetadataInterface`, and PHP via `PHP_VERSION`
- [x] 2.4 Normalise every component: empty string and the literal `UNKNOWN` both become `unknown` (design D6)
- [x] 2.5 Wrap assembly so any `Throwable` yields a valid degraded string rather than propagating (design D6)
- [x] 2.6 Emit the exact format from the spec: `TaxCloud-Magento2/<v> Magento/<v> (<edition>) PHP/<v>`

## 3. Transport wiring

- [x] 3.1 Add the `User-Agent` header in `RestClient::send()` alongside the existing `Accept`/`Content-Type` assembly (covers every v3 operation and both pings)
- [x] 3.2 Add the `User-Agent` header in `TokenExchange::exchange()`
- [x] 3.3 In `SoapGateway::buildSoapOptions()`, set **both** the `user_agent` option and an `http.user_agent` entry in the existing `stream_context`, from the same builder (design D4)
- [x] 3.4 Confirm no `di.xml` plugin, preference, or interceptor is added to any framework HTTP class — the change must touch only this module's own three call sites, leaving Magento core and other modules' outbound traffic untouched (design D3)

## 4. Unit tests

- [x] 4.1 Builder: correct format when every component resolves
- [x] 4.2 Builder: each component degrading to `unknown` independently, including `ProductMetadata`'s literal `UNKNOWN`
- [x] 4.3 Builder: a throwing collaborator still yields a valid header, and the result is memoized (collaborators queried once)
- [x] 4.4 Builder: edition appears for both Open Source and Adobe Commerce values
- [x] 4.5 `RestClient`: the header is present on a v3 operation and on both ping paths
- [x] 4.6 `TokenExchange`: the header is present on the exchange request
- [x] 4.7 `SoapGateway::buildSoapOptions()`: both the `user_agent` option and the stream-context entry are set, carry the identical value, and the existing timeout/`trace`/`cache_wsdl` assertions still hold
- [x] 4.8 Cross-transport: SOAP and REST report a byte-identical string (spec: "the same identity is sent on both generations")
- [x] 4.9 Version drift: a test reading `composer.json`, `etc/module.xml` and the top `CHANGELOG.md` heading and asserting all three agree — reading the files, not hardcoding `1.4.0` (design D5)
- [x] 4.10 Verify all added test code runs on PHPUnit 9.5, 10.5 and 12.5 (local is 12.5 only — check the 9.5/10.5-incompatible APIs by inspection)

## 5. Empirical verification

- [x] 5.1 Confirm a `User-Agent` supplied through `CURLOPT_HTTPHEADER` (how Magento's `Curl` maps headers) reaches the wire — verified against a local logging server; arrives verbatim, no duplicate from a cURL default
- [x] 5.2 Confirm the SOAP call **and** a cold WSDL fetch both carry the header — verified against a local logging server on PHP 8.1.34 / 8.2.31 / 8.3.31 / 8.4.2, with `WSDL_CACHE_NONE` forcing a cold fetch each run
- [x] 5.3 Fallback not needed — measurement **refuted** D4's premise (either placement alone identifies both requests on every supported PHP). D4, its risk entry, the code comment and the SOAP test docblock corrected to describe the arrangement as redundancy rather than necessity
- [x] 5.4 Verified on the Warden dev stack (Adobe Commerce 2.4.9, PHP 8.5.7):
  - DI resolves the builder to `TaxCloud-Magento2/1.4.0 Magento/2.4.9 (Enterprise) PHP/8.5.7` — real `PackageInfo` and `ProductMetadata` values, shared instance confirmed
  - wire capture through the **real** `RestClient` and `SoapGateway` (endpoint stubbed to a local listener, nothing written to the dev DB): REST header, SOAP option and WSDL context all match the DI string
  - **live** read-only v3 ping against TaxCloud with the store's own credentials: SUCCESS with the header attached
  - no live v1 SOAP call: this store has no V1 credentials configured (`api_id` empty). SOAP remains covered by the real-DI wire capture and the PHP 8.1–8.4 matrix

## 6. Quality gates

- [x] 6.1 `make phpstan` clean at level 5 with no new baseline entries
- [x] 6.2 `phpcs` clean on all touched files
- [x] 6.3 Full unit suite green

## 7. Documentation

- [x] 7.1 Add a CHANGELOG entry under `## 1.4.0` describing the User-Agent, with an example string
- [x] 7.2 Note in the entry that the header carries no credential or customer data, so it is safe in shared logs

## 8. Wider test coverage (confirmed by maintainer, implemented)

- [x] 8.1 Integration: `Test/Integration/Model/UserAgentWiringTest.php` — all three transports receive the same DI-managed instance (reflection on the private collaborator; "was this wired" has no public surface), the real install resolves every component with no `unknown`, the reported version matches `composer.json`, and the SOAP placements match what REST sends. **5 tests, 10 assertions, green.** Caught a real failure first time: stale compiled DI still held the pre-change constructor signature — resolved by `setup:di:compile`
- [x] 8.2 E2E: `Test/E2e/specs/admin/admin-request-user-agent.spec.ts` — switches on Advanced logging, provokes a live SOAP `lookupTaxes` through the checkout totals step, and asserts the logged HTTP request headers carry a well-formed, non-degraded User-Agent; restores the logging setting in a `finally`. **Green**, on real wire traffic:
  `lookupTaxes HTTP request headers: POST /1.0/TaxCloud.asmx HTTP/1.1 … User-Agent: TaxCloud-Magento2/1.4.0 Magento/2.4.8-p5 (Community) PHP/8.3.31`
- [x] 8.3 First e2e draft asserted against the Test Connection button and failed: `SoapPing` emits no wire trace — only `Model\Api` operations call `logSoapTrace`. Rewritten around a real tax lookup. REST is deliberately out of e2e scope: that path logs bodies but not headers, so there is nothing on disk to assert against
