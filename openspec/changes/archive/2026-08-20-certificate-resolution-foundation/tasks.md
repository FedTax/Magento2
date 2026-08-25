## 1. Certificate model and mappers

- [x] 1.1 Add a `Certificate` value object (identifier, TaxCloud customer identity, covered state abbreviations, disabled flag, single-purchase flag, optional detail: reason, purchaser name, address, created date, tax id). Immutable, following the `Model/Tic/TicSearchResult` precedent.
- [x] 1.2 Add a SOAP mapper: `GetExemptCertificates` response → `Certificate[]`. Handles the single-object-instead-of-array shape and both `StateAbbr` / `StateAbbreviation` spellings, as `ResponseMapper::extractExemptStates()` already does.
- [x] 1.3 Add a v3 mapper: certificate JSON → `Certificate`. States from `states[].abbreviation`. **Absent-not-invented**: a value the transport omits, or returns outside its documented range (observed: `reason: "Unknown"`), becomes absent rather than a substitute — see design.md.
- [x] 1.4 Unit tests for both mappers, including the absent-not-invented cases and the SOAP shape quirks.

## 2. Gateway contract and transport implementations

- [x] 2.1 Add `Api/CertificateGatewayInterface` — list by TaxCloud customer identity, create, delete; each store-aware. Add it to `GatewayInterface`.
- [x] 2.2 Implement on the SOAP transport: `GetExemptCertificates`, `AddExemptCertificate`, `DeleteExemptCertificate`. Never send a single-purchase flag.
- [x] 2.3 Implement on the REST transport: account-level list filtered by `customerId` **and `connectionId`** (new — closes the cross-connection leak), connection-scoped create and delete. `singlePurchase` is not accepted on create; do not send it.
- [x] 2.4 Dispatch all three through `Router` on the store's `api_type`.
- [x] 2.5 Remove `Api/ExemptionGatewayInterface` and `getValidatedCertificateID()` from `Router`, `Api`/`SoapGateway` and `RestGateway`; the single-certificate validators are superseded.
- [x] 2.6 Unit tests: each operation on each transport, routing by store, and failure paths reporting failure rather than an empty set.

## 3. TaxCloud customer identity

- [x] 3.1 Add the `taxcloud_customer_id` customer attribute via a data patch. Not rendered in any customer-facing form.
- [x] 3.2 Add a `TaxCloudCustomerIdentity` resolver: stored value, else the Magento entity id. Every certificate call goes through it — this is the single seam that makes creation and lookup unable to disagree.
- [x] 3.3 Add an ACL resource for certificate management; guard writes to the attribute with it.
- [x] 3.4 Block customer-facing writes at the point customer data is saved, **not** by omitting a form field — `used_in_forms` governs rendering, not writability. A crafted account-edit submission must not set it.
- [x] 3.5 Log every identity change with customer, previous value, new value and the acting administrator, through the store-gated TaxCloud logger.
- [x] 3.6 Unit tests: default-to-entity-id, stored value wins, shared identity resolves the same set, customer-facing write refused, change logged.

## 4. Resolution and ownership

- [x] 4.1 Add `CertificateResolver`: obtain the customer's certificates, filter to enabled ones covering the destination state, then apply precedence — explicitly attached → group auto-apply (**slot only; Change B fills it**) → none.
- [x] 4.2 Enforce ownership here: an identifier from anywhere outside the module is applied only if it appears in that customer's own listing. An identifier that does not resolve is treated as absent.
- [x] 4.3 Fail closed: if the certificate set cannot be established, resolve to no exemption so the order is taxed.
- [x] 4.4 Exclude single-purchase certificates from the eligible set.
- [x] 4.5 Honour `taxcloud_cert` as the explicit-attachment slot, so existing installs behave exactly as before.
- [x] 4.6 Unit tests: eligibility by state, precedence order, disabled excluded, single-purchase excluded, **foreign identifier refused**, stale identifier treated as absent, failure taxes the order.

## 5. Caching

- [x] 5.1 Re-key the certificate cache to (resolved TaxCloud identity, account) — dropping the per-certificate component, since the cached unit is now the customer's set. Keying on the resolved identity rather than the Magento customer id keeps two customers sharing an identity on one entry.
- [x] 5.2 Invalidate the affected customer's entry on create and delete.
- [x] 5.3 Keep the existing rule that retrieval failures are never cached.
- [x] 5.4 Unit tests: accounts never share entries, shared identity shares one entry, write invalidates, failure not cached.

## 6. Order record

- [x] 6.1 Add `sales_order` columns for the applied certificate identifier and a serialized snapshot of its detail, via `etc/db_schema.xml`.
- [x] 6.2 Write both when the exemption is applied to the order; never rewrite afterwards.
- [x] 6.3 Leave the record empty for orders placed without an exemption.
- [x] 6.4 Unit tests: recorded on exemption, absent when taxed, not mutated by later certificate changes.

## 7. Wire into the tax path

- [x] 7.1 Replace the certificate step in both lookup paths (`Model/Api.php`, `Model/Gateway/Rest/RestGateway.php`) with a single `CertificateResolver` call, so precedence exists once rather than per transport.
- [x] 7.2 Confirm the resolved certificate still reaches the v1 `exemptCert` and v3 `exemption.exemptionId` payload positions unchanged.
- [x] 7.3 Confirm guests remain unexempted — no customer, no certificate, no API call.
- [x] 7.4 Delete `Model/Gateway/ExemptionValidator.php` and `Model/Gateway/Rest/RestExemptionValidator.php` and their tests once nothing references them.

## 8. Verify

- [x] 8.1 `make test-unit`, plus a check of every added test against PHPUnit 9.5 / 10.5 / 12.5 conventions (CI spans Magento 2.4.7/2.4.8/2.4.9; only 12.5 runs locally).
- [x] 8.2 `make phpstan` — no new level-5 findings, no baseline growth.
- [x] 8.3 `make lint`.
- [x] 8.4 `make integration-test` — the existing suite must stay green; this change alters the lookup path.
- [x] 8.5 Full e2e matrix — both new checkout specs must still pass under SOAP and REST, since `exempt-customer@example.com` now resolves through the new path.

## 9. Coverage agreed with the maintainer and added

- [x] 9.1 `Test/Integration/Certificate/LiveCertificateLifecycleTest` — create → list → delete against the live sandbox on BOTH transports, plus the cross-transport read (v3 reads a v1-created certificate) the seeding design depends on, and an unknown identity listing empty rather than throwing. Run-unique customer identities and cleanup in `finally`, since the sandbox account is shared and has no bulk cleanup. **Found two real defects on its first run** that unit tests could not reach: `RestClient` rejected `DELETE` outright (so v3 certificate deletion was entirely broken), and SOAP `AddExemptCertificate` failed without `CreatedDate` (PHP's encoder requires every WSDL-declared property, even one TaxCloud stamps itself).
- [x] 9.2 `Test/E2E/specs/checkout/exempt-customer-wrong-state.spec.ts` — the TX-certificate customer shipping to CALIFORNIA, asserting the normal $0.99 CA tax. CA because the sandbox has real CA nexus, so this is a genuine charge rather than an absence of one. Without it, a module that applied every certificate it found while ignoring coverage would pass both other specs — the mirror image of the defect that shipped.
- [x] 9.3 Cart hygiene for logged-in specs: a signed-in customer's cart persists against their account between runs, unlike a guest's, so leftovers from a failed run silently double every total. `CheckoutPage::emptyCart()` added and applied to all three logged-in specs.
