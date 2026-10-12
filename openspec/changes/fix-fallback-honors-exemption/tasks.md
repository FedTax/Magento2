## 1. Failed lookups honour an applied certificate

- [x] 1.1 `RestGateway`: add `?string $certificateID` to `lookupFallback()`; when set, warn naming the certificate and return the zero result before consulting the fallback setting (design D1). Pass `$certificateID` from both failure call sites (exception and non-success response).
- [x] 1.2 `Api` (v1 SOAP): replace the two inline fallback branches in `lookupTaxes()` (exception, non-OK `ResponseType`) with a private `lookupFallback()` of the same shape and message, keeping each branch's existing error log.

## 2. The order record requires an untaxed order

- [x] 2.1 `RecordCertificate`: when a certificate resolves, skip the record and warn naming the order, certificate and tax when `round(max(tax_amount, base_tax_amount), 4) > 0` (design D3). Update the class docblock to state the invariant.

## 3. Tests

- [x] 3.1 `RestGatewayTest`: failed lookup (exception, and error response) with an applied certificate returns no tax and never calls the fallback, with fallback on and off; the warning names the certificate; without a certificate the existing fallback behavior is unchanged.
- [x] 3.2 SOAP unit test (via `BuildsGatewayApi` or a focused test): the same matrix for the exception branch and the non-OK response branch.
- [x] 3.3 `RecordCertificate` unit test: untaxed order with a resolved certificate is recorded; taxed order (store amount, and base-only amount) is not, and warns; no certificate → no record and no warning.
- [x] 3.4 Self-audit new and changed tests for PHPUnit 9.5/10.5/12.5 compatibility (no `addMethods`, `withConsecutive`, `at()`, or renamed assertions).
- [x] 3.5 Run `make lint`, `make phpstan` and `make test-unit`; judge each by exit code.
- [x] 3.6 Propose integration/e2e coverage to the maintainer rather than adding it (an exempt checkout over a failing recorded v3 transport is the candidate).

## 4. Documentation and release (1.5.2)

- [x] 4.1 Documentation check and edits: `docs/settings.md` (fallback setting), `docs/how-an-order-becomes-exempt.md` (failed lookups and the order record), and `docs/common-problems.md` where it covers fallback or exempt-but-taxed orders. README: confirm no developer-material change.
- [x] 4.2 Bump the version to 1.5.2 in `composer.json` and `etc/module.xml` (`setup_version`); `ModuleVersionConsistencyTest` stays green.
- [x] 4.3 Add a `## 1.5.2` CHANGELOG entry: *Fixed* items for both behaviors, with upgrade notes and a note that orders already placed are not corrected.
- [x] 4.4 Commit on branch `1.5.2` (from `v1.5.1`) and push the branch; tag `v1.5.2` on its tip.

## 5. Carry forward to 1.6.0

- [ ] 5.1 Cherry-pick (`-x`) the 1.5.2 commit onto `1.6.0`; verify the change's files match 1.5.2 and the suite passes there.
- [ ] 5.2 Archive the OpenSpec change on `1.6.0` (syncing the deltas into the main specs) and push.
