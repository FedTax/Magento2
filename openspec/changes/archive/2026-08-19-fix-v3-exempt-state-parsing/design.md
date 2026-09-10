## Context

See proposal.md — Why.

Two facts shape everything below.

The v3 contract is settled, not guessed. `GET /tax/exemption-certificates` returns `items[]` of `ExemptionCertificateResponse`, whose `states` is an array of `ExemptionCertificateExemptStatesResponse` — an object with a single required `abbreviation`, `minLength: 2`, `maxLength: 2`. Confirmed against TaxCloud's published OpenAPI document (`https://api.v3.taxcloud.com/tax/openapi.json`) and the individual schema documents it references, retrieved 2026-08-19. The WooCommerce plugin already reads it this way. No further discovery is needed to write the fix.

The test account is empty. `GET /tax/exemption-certificates?limit=3` against the seeded sandbox returns `items: []`. That is why `testExemptionCertificateListingEnvelope()` passes today while asserting a contract the code violates: there is no item to assert against, so envelope-only assertions are all it can make. Any test that would actually have caught this defect needs a certificate to exist on the account.

## Goals / Non-Goals

**Goals:**
- Fix the state extraction, and leave behind at least one test that fails against the current code.
- Keep the production change confined to the state-extraction step, so this can ship ahead of the lookup redesign without entangling it.

**Non-Goals:**
- Everything in proposal.md — Non-goals.
- Refactoring `fetchExemptStates()` beyond the extraction step, even though the surrounding pagination is on the list to be replaced wholesale by the follow-on change. Churn there would only be thrown away.

## Decisions

### Extract abbreviations, tolerate junk entries, keep failing closed

Read each entry of `states`, take its `abbreviation`, keep the ones that are usable two-letter strings, drop the rest. A certificate that yields no usable abbreviation produces an empty covered-state list, which fails closed exactly as it does today.

*Alternative considered:* accept both shapes — strings and objects — so the parse works regardless. Rejected. The schema is unambiguous and versioned; accepting a shape the API does not emit buys nothing and quietly re-admits the ambiguity that caused the bug. If TaxCloud ever does change the shape, a hard failure surfaced by the contract test is the outcome we want, not a silent fallback.

*Alternative considered:* validate the abbreviation against a state list and reject unknown codes. Rejected as scope — the destination state is itself supplied by Magento's region data, so an unmatched abbreviation already resolves to "does not cover", which is the correct answer without a second source of truth.

### Seed a certificate in `seed-test-data.php`, and give it the real customer ID

The live contract test needs a certificate on the account. `seed-test-data.php` is already where account state is provisioned, so the certificate is created there via `POST /tax/connections/{connectionId}/exemption-certificates`, using the module's own `RestClient` rather than raw HTTP — it already resolves the endpoint, the auth mode, and credential redaction, and using it here exercises the same client the suites do.

**The certificate hangs off a dedicated exempt customer, not the existing seeded one.** Section 4h seeds `customer@example.com` as the generic registered customer for logged-in flows. Attaching a certificate to that account would make every future logged-in test silently exempt, with nothing at the call site explaining a zero tax line — the same class of invisible-by-default failure this change exists to fix. Nothing references that customer today (verified across `Test/Integration` and `Test/E2E`), so the cost of separating them is zero now and rises the moment someone writes the first logged-in checkout test.

So section 4h is generalized to seed two customers: `customer@example.com`, unchanged and non-exempt, and `exempt-customer@example.com`, carrying the certificate on its `taxcloud_cert` attribute. That pair is exactly what an end-to-end exemption test needs — one order that should be taxed, one that should not — and each account's address states what it is for.

Two things fall out of this that are worth naming, because they are the reason to prefer it over the alternatives:

- The seeded certificate carries the **real Magento customer entity ID** as its `customerId`. That is exactly the shape change 3 will produce for certificates the extension creates, so the fixture is conformant with where the module is going rather than reproducing the portal-created mismatch.
- It leaves the integration and e2e suites with a genuinely exempt customer for the first time, which the follow-on lookup and certificate-creation changes both need. This change is the cheapest place to pay for it.

The certificate covers **TX**. The sandbox account has TX nexus, so a TX-destination order produces non-zero tax and an exemption test there can assert the line actually went to zero. A no-nexus state would give zero tax with or without a working exemption — a test that passes for the wrong reason, which is the same trap that let this defect ship. This settles design.md's open question.

Seeding must be idempotent: list by `customerId`, and create only when the customer has no live certificate, otherwise reuse the existing one. Without that, every seed run leaves another certificate on the sandbox account.

*Alternative considered:* assert the item shape in unit tests only and leave the live test on envelope assertions. Cheaper, and it does catch this specific defect — but it leaves the v3 contract itself unverified, which is the actual root cause. The wrong shape was in the unit fixtures too; a unit test only ever confirms that the code agrees with the fixture author.

*Alternative considered:* have the live test create its own certificate and delete it afterward. Self-contained, but a failed run leaks a certificate onto a shared account, and it gives the other suites nothing.

### Create the seeded certificate over v1 SOAP, not v3

Verified against the live sandbox on 2026-08-19, with a three-way control
(`Ping` → OK; `GetExemptCertificates` for a customer with no certificates → OK
and empty; the same call for the customer holding a v3-created certificate →
`ResponseType=Error`, "There was a problem processing the exemption
certificate"):

| Certificate created via | Readable over v3 | Readable over v1 SOAP |
| --- | --- | --- |
| v3 REST | yes | **no — the call errors** |
| v1 SOAP | yes | yes |

Two things make this sharper than a compatibility footnote. First, v1 does not
skip the certificate it cannot read — a single v3-created certificate makes
`GetExemptCertificates` fail for that customer entirely, so their whole
certificate list becomes unreadable over SOAP. Adding a v1 certificate
alongside does not rescue it; that was tested directly. Second, the module's
SOAP validator fails closed on a non-OK response, so the customer is simply
taxed with nothing surfaced to anyone.

A v1-created certificate is therefore the only kind both transports can read,
which is what lets ONE fixture serve both the SOAP and REST e2e passes instead
of needing a separate exempt customer per transport. The reuse check still runs
over v3, since v3 sees certificates from both APIs.

Scope of the consequence, kept in proportion. A store that stays on one
transport is unaffected, and the migration direction the module actually sells
— SOAP to v3 — is the safe one, because v3 reads v1-created certificates. This
is not a reason to design for transport rollback.

What it does leave is a constraint for change 3: certificate creation should
route through the existing gateway router on the store's `api_type`, like every
other operation, so a SOAP store creates over SOAP and reads back what it
wrote. The trap is hardcoding creation to v3 because that is what the
WooCommerce plugin does.

**Resolved 2026-08-19: the TaxCloud portal writes v1-compatible certificates.**
A certificate created by hand in the portal (Settings -> Exemption Certificates
-> +Add, customer id `portal-test-1`) is read by v1 `GetExemptCertificates` with
`ResponseType=OK`, returning its state, reason, business type and tax id. Its
certificate id is also a sequential GUID sharing a generator with v1-created
ids, where v3-created ids are random UUIDs. It carries our own connection id, so
there is no cross-connection issue either.

So SOAP-pinned merchants can read the certificates they actually have, and the
incompatibility is a footnote rather than a live defect. What remains is only
the forward-looking constraint above: route certificate creation on the store's
`api_type` rather than hardcoding v3.

Two secondary observations from the same certificate, neither affecting this
change (the validator reads only ids, `states` and `disabledAt`) but both
relevant to change 3 if it ever displays certificate detail:

- **v3 misreports the exemption reason.** The certificate's reason is `Resale`
  — that is what the portal recorded and what v1 returns — while v3 reports
  `"reason": "Unknown"`, a value outside v3's own documented `reason` enum.
- **v3 does not expose the tax id at all.** The portal collects a tax number and
  v1 returns it (masked, `**-***4567`); the v3 representation has no field for
  it. v3 is a lossy view of what TaxCloud stores.

### Correct the unit fixtures rather than adding tests beside them

`RestExemptionValidatorTest::certificate()` builds `'states' => ['NY', 'NJ']`. Every state-related test in that file flows through it, so correcting the builder to emit `[['abbreviation' => 'NY'], ...]` turns the whole existing set red against current code and green against the fix — better coverage than any test added alongside a fixture that stays wrong. The new scenarios from the spec delta (mixed usable/unusable entries, empty coverage) are then added on top.

## Risks / Trade-offs

- **Seeding puts certificate creation into a change whose production diff is one extraction step.** → It lands in `scripts/`, not in module code; no production path gains a create call. This is the one place the change exceeds a minimal fix, and it is a deliberate trade for a contract test that can actually fail.
- **The seed script gains a dependency on a v3 endpoint the module does not otherwise call.** → Confined to the seeding path, and change 3 brings that endpoint into the module properly. Accepting it here avoids a throwaway abstraction.
- **Certificates accumulate on the shared sandbox account if idempotency is wrong.** → Reuse-if-present, keyed on the seeded customer's ID; the account is shared across CI runs, so this is a correctness requirement of the seed step, not a nicety.
- **Stale cache entries during development.** → The empty state list caches for an hour under `taxcloud_cert_states_*`, so a developer who exercised the broken path keeps seeing "not exempt" after the fix until it expires. `bin/magento cache:clean taxcloud` clears it. This is a local-workflow annoyance only: no installation runs the v3 transport, which is unreleased.

## Migration Plan

None. The v3 REST transport has never been released (latest tag `v1.3.1`; all v3 work sits in the unshipped 1.4.0), so no installation is running the broken parse and there is nothing to migrate, announce, or flush in the field. Rollback is a straight revert — the extraction step has no persisted side effects, and seeded certificates are inert if unused.

## Open Questions

None. The covered-state question is settled above: TX.
