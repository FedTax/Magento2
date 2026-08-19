## 1. Fix the state extraction

- [x] 1.1 In `Model/Gateway/Rest/RestExemptionValidator.php`, change the state-extraction step of `fetchExemptStates()` to read each entry of the certificate's `states` array and collect its `abbreviation`, keeping only usable two-letter string values and discarding entries without one. Do not accept bare strings — see design.md, "Extract abbreviations, tolerate junk entries, keep failing closed".
- [x] 1.2 Update the `@return` docblock on `fetchExemptStates()` so the described shape matches what the method now produces.
- [x] 1.3 Leave the surrounding pagination, cache-key, `disabledAt` and fail-closed logic untouched.

## 2. Unit tests

- [x] 2.1 Correct the `certificate()` fixture builder in `Test/Unit/Model/Gateway/Rest/RestExemptionValidatorTest.php` to emit `states` as `[['abbreviation' => 'NY'], ...]`, keeping its call sites passing plain abbreviation lists so the existing tests read unchanged.
- [x] 2.2 Confirm the corrected fixtures fail against unpatched code before applying task 1 — this is the check that the suite can actually catch the defect. Record the observed failure.
  - Observed pre-fix (PHPUnit 12.5.31 / PHP 8.4.2): 3 of 9 failed — `testCertificateCoveringDestinationStateValidates`, `testPaginationFollowsCursorUntilCertificateFound`, `testSecondValidationServedFromCache`, each `Failed asserting that null is identical to 'cert-9'`. The six that still passed are the rejection cases, which the defect satisfies by accident.
- [x] 2.3 Add a test for a certificate whose `states` mixes entries with a usable abbreviation and entries without one, asserting the usable abbreviations still match (spec scenario: "Unusable state entries do not discard the rest").
- [x] 2.4 Add a test for a found, enabled certificate with an empty `states` array, asserting `validate()` returns null and the empty list is cached (spec scenario: "Certificate with no covered states is rejected").
- [x] 2.5 Check every added or edited test against PHPUnit 9.5, 10.5 and 12.5 conventions — the CI matrix spans Magento 2.4.7/2.4.8/2.4.9 and only 12.5 runs locally.

## 3. Seed an exemption certificate for the test account

- [x] 3.1 Generalize section 4h of `scripts/seed-test-data.php` so it can seed more than one customer, then add `exempt-customer@example.com` alongside the unchanged `customer@example.com`. The generic customer must stay non-exempt — see design.md, "Seed a certificate in `seed-test-data.php`".
- [x] 3.2 Look up existing certificates for the exempt customer via `GET /tax/exemption-certificates?customerId=<entity id>` (v3 sees both APIs' certificates) and reuse a live one if present; otherwise create one over **v1 SOAP `AddExemptCertificate`**, sending the customer's **Magento entity ID** as the customer id. Created over v1 because v3-created certificates are unreadable over SOAP — see design.md. Required fields per the v3 schema: `customerId`, `customerName`, `customerBusinessType`, `reason`, `reasonDescription` (max 20 chars), `address`, `states`. Note `singlePurchase` is not accepted on create.
- [x] 3.3 Write the resulting `certificateId` onto the exempt customer's `taxcloud_cert` attribute so that customer is genuinely exempt for downstream suites.
- [x] 3.4 Verify the seed step is idempotent across two consecutive runs — the shared sandbox account must not accumulate a certificate per run.
- [x] 3.5 Covered state settled: **TX**. The sandbox has TX nexus, so a TX order yields non-zero tax and an exemption test can assert it dropped to zero; a no-nexus state would pass whether or not exemptions work.

## 4. Live contract test

- [x] 4.1 Extend `testExemptionCertificateListingEnvelope()` in `Test/Integration/Rest/RestLiveApiTest.php` to assert the item-level contract on the seeded certificate: `certificateId`, `customerId`, `disabledAt`, and `states` entries carrying a two-letter `abbreviation`.
- [x] 4.2 Assert the listing is non-empty, so the test cannot pass vacuously against an account with no certificates the way it does today.
- [x] 4.3 Run it against the live sandbox and confirm it passes with the fix and fails against the pre-fix parse.

## 5. Verify

- [ ] 5.1 `make test-unit`, and `make test-unit-version` across the supported PHPUnit versions.
- [x] 5.2 `make phpstan` — confirm no new level-5 findings and no baseline growth.
- [x] 5.3 `make lint`.
- [x] 5.4 `make integration-test` after reseeding, to confirm the seeding change did not disturb the existing suite.

## 6. E2E coverage — logged-in checkout (agreed with the maintainer)

Every checkout spec in the suite today is a GUEST journey; no logged-in customer
has ever been exercised end to end. That is a coverage gap in its own right, and
it is also a hard prerequisite here — an exemption certificate only applies to a
logged-in customer, so exemptions cannot be tested end to end at all until this
exists.

Specs live in `Test/E2E/specs/checkout/`, which `playwright.config.ts` matches
for BOTH the `chromium` (SOAP) and `checkout-rest` (V3 REST) projects, so each
one runs once per transport with no extra wiring.

- [x] 6.1 Build the storefront customer-login helper in `Test/E2E/fixtures/auth.ts`, which is currently an explicit placeholder ("intentionally exports nothing usable"). Write the selectors against the running storefront, not from memory. Correct its stale note claiming the seed creates no customer — it now creates two.
- [x] 6.2 Add a logged-in path to `CheckoutPage` (`openAsCustomer()`, `hasTaxRow()`, `expectOrderPlaced()`). Three real differences from the guest flow, all confirmed against the running storefront rather than assumed: no `#customer-email` to wait on, Luma OMITS the tax row at zero instead of rendering `$0.00`, and the success page reads "Your order number is:" with the number as a link rather than the guest's "Your order # is:".
- [x] 6.3 Add `specs/checkout/logged-in-checkout.spec.ts` for `customer@example.com` — log in, check out to the account's default TX address, assert the normal non-zero TX tax and a completed order. This is the general logged-in journey, independent of exemptions.
- [x] 6.4 Add `specs/checkout/exempt-customer-checkout.spec.ts` for `exempt-customer@example.com` — same journey, asserting tax is zero. Paired with 6.3 it distinguishes "exemption applied" from "tax broken"; alone, neither does.
- [x] 6.5 Both specs pass under BOTH projects — full suite 34/34 in 6.2 min, `rest-teardown` restoring SOAP afterwards. The risk that the SOAP pass could not see the certificate was resolved by creating it over v1 instead of v3 (see design.md): one certificate now serves both transports, so no per-project account switching was needed.
- [x] 6.6 Reuse the existing page objects, golden-value style and `taxcloudLog` fixture rather than introducing a parallel convention.

## 7. Open investigation — which API does the TaxCloud portal write with?

Decides whether the v1/v3 certificate incompatibility is a live problem or a
footnote. Magento has no certificate creation of its own, so portal-created
certificates are the only kind merchants have today, and existing installs are
pinned to `soap` by the credential migration. If the portal writes v3, those
merchants cannot read their own certificates over SOAP right now.

- [x] 7.0 Maintainer creates one certificate in the TaxCloud portal against the sandbox account, using customer id **`portal-test-1`**. NOT customer id `2` — that is the seeded exempt customer, and a v3 certificate on it would make its whole SOAP listing error and take the SOAP e2e pass down with it.
- [x] 7.1 Inspect it from both sides: `GET /tax/exemption-certificates?customerId=portal-test-1` (expected to list it either way) and v1 `GetExemptCertificates(customerID=portal-test-1)`. **v1 returning OK with the certificate → portal writes v1, non-issue, drop the concern. v1 returning `Error` → portal writes v3, and SOAP-pinned merchants have a live exemption failure in shipped versions.**
- [x] 7.2 Record the outcome in design.md and, if it is the error case, raise it with TaxCloud and reconsider whether change 3 must create over the store's own transport.

## 8. Changelog

- [x] 8.1 No CHANGELOG entry. The v3 REST transport is unreleased (latest tag `v1.3.1`; all v3 work sits in the unshipped 1.4.0), so there is no shipped behavior to correct and nothing for a user to act on — this is part of the initial v3 implementation. An entry drafted as a "Fixed" note was removed for exactly this reason.
- [ ] 8.2 Flag separately to the maintainer: 1.4.0's `### Added` never describes the v3 REST tax operations themselves, and its gateway-routing entry still reads "Until REST tax operations ship, both selections transact over SOAP" — stale now that they have. Out of scope here; it belongs to whoever cuts 1.4.0.
