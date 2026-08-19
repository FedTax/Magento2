## Why

`RestExemptionValidator` reads a v3 certificate's covered states as a list of plain strings, but TaxCloud v3 returns a list of objects — `states[].abbreviation`, per the published `ExemptionCertificateExemptStatesResponse` schema. The `array_filter($states, 'is_string')` therefore yields an empty list for every certificate, no destination state ever matches, and `getValidatedCertificateID()` returns null — **so no exemption is ever applied on a REST-selected store**, and the empty result is cached for an hour.

Nothing caught this because the assumption is wrong in the tests too: the unit tests build fixtures as flat string arrays, and the live contract test asserts only the listing envelope (`items`, `nextCursor`) against a sandbox account that holds zero certificates, so it passes vacuously. The shape was carried over by analogy from the SOAP mapper's flat `StateAbbr` strings rather than verified against v3.

## What Changes

- Read a v3 certificate's covered states from each state entry's two-letter `abbreviation`, discarding entries that carry no usable abbreviation.
- Correct the `RestExemptionValidator` unit fixtures, which currently encode the wrong response shape and so cannot fail on this defect.
- Make the live contract test meaningful: it must exercise a certificate that actually exists on the account and assert the item-level contract (`certificateId`, `customerId`, `states[].abbreviation`, `disabledAt`), not just the envelope. This requires seeding a certificate into the test account, since the sandbox holds none.
- No change to the fail-closed posture, the cache key discipline, pagination, or the `disabledAt` check.

- Build the suite's first logged-in customer checkout journey, and cover it for both a plain and an exempt customer.

The production fix is deliberately the narrowest possible one: a single extraction step. Replacing the customer-filtered listing with a direct fetch by certificate ID, scoping the request by connection, and defining certificate ownership are follow-on work, tracked separately.

The **test** scope is deliberately wider than that, by agreement. Exemptions only apply to logged-in customers, and the e2e suite has never exercised one — every checkout spec is a guest journey. So proving this fix end to end means building the logged-in journey that was missing, which also closes a coverage gap that has nothing to do with exemptions.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `rest-tax-operations`: tighten the exemption-validation requirement so the source of a certificate's covered states is stated at spec level — the two-letter abbreviation on each of the certificate's state entries — rather than left to implementation. The silence there is what allowed a wrong-shaped read to satisfy the spec on paper while dropping every exemption in practice.

## Non-goals

- Switching to `GET /tax/connections/{connectionId}/exemption-certificates/{id}` in place of paging the customer-filtered listing.
- Passing `connectionId` (or `disabled`) as query filters; the listing remains account-wide for now.
- Any certificate-ownership policy — deciding which `customerId` values count as "this Magento customer" when a certificate was created in the TaxCloud portal.
- Certificate creation in Magento (Woo parity), and the `taxcloud_cert` free-text customer attribute it would replace.
- Any change to the SOAP path, which reaches the same states through `ResponseMapper::extractExemptStates()` and is unaffected by this defect.

## Store-scoping implications

None new. `validate()` already receives the entity's store and threads it into both the connection-ID read that forms the cache key and the `RestClient` call. This change touches only how the response body is parsed, after the store has been resolved; no new config read is introduced.

## Impact

- `Model/Gateway/Rest/RestExemptionValidator.php` — `fetchExemptStates()`, the state-extraction step.
- `Test/Unit/Model/Gateway/Rest/RestExemptionValidatorTest.php` — the `certificate()` fixture builder and every test that passes state lists through it.
- `Test/Integration/Rest/RestLiveApiTest.php` — `testExemptionCertificateListingEnvelope()` extended to item-level assertions.
- `scripts/seed-test-data.php` — likely needs to seed an exemption certificate so the live test has something to assert against.
- Behavioral blast radius: **none in the field.** The v3 REST transport is unreleased — the latest tag is `v1.3.1`, and everything v3 lives in the unshipped 1.4.0. No installation has ever run this code, so there is no tax-outcome change to announce, no upgrade note, and no cache to flush. This is part of getting v3 exemptions right the first time, not a repair of shipped behavior — and so it earns no CHANGELOG entry of its own.
