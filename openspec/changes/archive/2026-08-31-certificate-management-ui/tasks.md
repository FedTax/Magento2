## 1. Settings

- [x] 1.1 Add store-scoped settings in `etc/adminhtml/system.xml` + `etc/config.xml`: `exemptions_enabled` (master, **default off**) and `company_name`.
- [x] 1.2 Add typed readers to `TaxcloudConfig`, store-aware like every other setting.
- [x] 1.3 Add an `ExemptionPolicy` service answering: are exemptions enabled for this store, is this customer treated as exempt, may this customer create certificates. One place, so the surfaces cannot disagree.
- [x] 1.4 Unit tests: defaults leave everything off; visibility follows the master switch; store scoping.

## 3. Per-order and per-customer attachment storage

- [x] 3.1 Add storage for the certificate attached to a customer, replacing `taxcloud_cert`, and for one chosen per quote/order.
- [x] 3.2 Record an explicit clearing on the quote, distinct from absence — `quote.taxcloud_certificate_cleared`, honoured by the resolver's `$cleared` argument.
- [x] 3.3 Unit tests for the attachment storage and the resolution that reads it.

## 4. Admin — customer page

- [x] 4.1 Certificate grid on the customer edit page: list with covered states and detail, view, add, delete. Behind the certificate-management ACL.
- [x] 4.2 Surface the TaxCloud identity field with its note, editable only with the same permission.
- [x] 4.3 Discovery action reporting what the current identity resolves — including reporting "none", which is the diagnostic a wrong identity needs. `Controller/Adminhtml/Certificate/Index` reports the resolved identity, whether it is the default, and the certificates found; a retrieval failure is reported as failure rather than as an empty list.
- [x] 4.4 Name the store whose TaxCloud account the grid queried; certificates are account-level and stores may differ.
- [x] 4.5 Controllers pass every identifier through `CertificateResolver`; none compares identifiers itself. `Delete` refuses a certificate that is not the customer's, with the same message whether it belongs to someone else or does not exist.
- [x] 4.6 Unit tests: form shape checking (`CertificateFormReaderTest`), ownership refusal and fail-closed behaviour (`CertificateResolverTest`), policy gating (`ExemptionPolicyTest`). Controller-level ACL is enforced by `ADMIN_RESOURCE` on the shared base and verified live: the panel renders and loads real certificates.

- [x] 4.7 Attach/clear control on each row of the admin certificate panel, showing which certificate is currently attached. Behind the certificate-management ACL.
- [x] 4.8 `Controller/Adminhtml/Certificate/Attach`: re-resolves the identifier through `CertificateResolver::belongsToCustomer()` before storing, resolves against the customer's store, and logs the change with the previous and new values and the administrator responsible.
- [x] 4.9 Creating a certificate from the admin panel attaches it when the customer has none attached, and never displaces an existing attachment.
- [x] 4.11 Render the panel as a customer tab (`ui_component/customer_form.xml`), positioned after Account Information, with the block implementing `TabInterface` so the ACL hides the tab rather than showing an empty one. Needed its own starter: knockout injects tab content after component bootstrap, and the stock tab template renders through knockout's native `html` binding rather than Magento's `bindHtml`, so neither `text/x-magento-init` nor `data-mage-init` is ever applied there — verified twice by a panel that rendered correctly and issued no requests at all.
- [x] 4.10 Unit tests: a certificate that is not the customer's is refused and the stored value is unchanged; auto-attach fires only when the attachment is empty; clearing is a real state; the change is logged.

- [x] 4.13 Unit: a certificate chosen for the cart beats the one attached to the customer (`CertificateResolverTest`). Previously untested at every layer. The resolver keeps that precedence for the order-level path; no customer-facing surface produces it.

## 5. Admin — order page

- [x] 5.1 Show the applied certificate and its recorded snapshot on the order view — read from the order's OWN record, not from TaxCloud, since the question an order screen answers cannot change after the sale. Verified live against order 784.
- [x] 5.2 The record is read-only. Changing an order's certificate was dropped: with the default capture trigger the order is filed with TaxCloud on `sales_order_place_after`, so the "not yet captured" window does not exist on a default store; it would have cost the write-once property that makes the record usable as evidence; and cancel or credit-memo already reverse an order correctly.

## 6. Storefront — My Account

- [x] 6.1 Certificate section: list, view, add, delete, gated by the master switch.
- [x] 6.2 Creation confined to customers the store treats as exempt.
- [x] 6.3 The form states that the certificate applies to all future orders — v3 cannot create a single-purchase certificate, so a customer who thinks they are claiming a one-off must be told otherwise.
- [x] 6.4 Every action re-resolves ownership server-side; a customer cannot see, apply or delete another's certificate.
- [x] 6.5 Unit tests: `Test/Unit/Controller/Certificate/StorefrontCertificateControllersTest` — signed-out and exemptions-off refusals, a foreign certificate id refused with the SAME answer as an unknown one (or the refusal tells an attacker which ids are real), and a failed read reported as failure rather than as an empty list.

## 7. Test coverage

- [x] 7.1 `Test/Integration/Certificate/ExemptionMatrixTest`: every reachable exemption decision — 13 rows — asserting the quote's tax, not the resolver's return value. Certificates come from a stub so the set is fixed and the read observable; resolver, request builder, tax collector and totals are real. Two rows exist only here: a live API cannot be made to fail on demand, and no amount of clicking proves a call did NOT happen.
- [x] 7.2 `Test/Integration/Certificate/ExemptionMatrixLiveTest`: three rows against the real API — exempt, taxed, and wrong-state — proving the stub above tells the truth.
- [x] 7.3 `Test/Integration/Certificate/AttachmentPersistenceTest`: the attachment lands on a real customer, survives a reload, never displaces, and clearing is a real state. Also pins that a customer-facing write to the TaxCloud identity is refused — found by asserting the opposite and watching the guard hold.
- [x] 7.4 `Test/Integration/Certificate/OrderCertificateRecordIntegrationTest`: an exempted order records the certificate and its snapshot, the record survives a reload, and a taxed order claims nothing.
- [x] 7.5 `Test/E2E/specs/admin/certificate-tab.spec.ts`: the panel is a tab after Account Information, opening it ACTUALLY requests its certificates, exactly one panel is live, refresh re-reads while masking only the table. Behaviour rather than markup on purpose — the panel has twice rendered perfectly and made no request at all.
- [x] 7.6 `Test/E2E/specs/admin/certificate-attach.spec.ts`: put in use, take out of use, auto-attach on create, never displace — through the interface against the real API.
- [x] 7.7 `Test/E2E/specs/admin/certificate-create-validation.spec.ts`: an incomplete form is refused without a request, a complete one passes, and a server rejection is put in a modal.
- [x] 7.8 `Test/E2E/specs/exemptions-on/` plus setup/teardown projects: My Account lists the certificates held for a customer and removes one. A teardown PROJECT restores the settings, because it runs even when the guarded tests fail.
- [x] 7.9 Hardened `admin-saves-taxcloud-credentials.spec.ts` to restore credentials from the environment rather than from whatever is stored. A killed run skipped its `finally` and left placeholder credentials saved; every later TaxCloud call then failed authentication, which surfaced as customers appearing to hold no certificates — indistinguishable from "not exempt".

## 8. Cache refresh

- [x] 8.1 Admin action discarding a customer's cached certificates so the next resolution reads afresh — `Controller/Adminhtml/Certificate/Refresh`, invalidating only that customer's entry.
- [x] 8.2 Unit test that it invalidates only that customer's entry.

## 9. Migration off `taxcloud_cert`

- [x] 9.1 Data patch copying every `taxcloud_cert` value into the new attachment storage. Re-runnable.
- [x] 9.2 A second patch, ordered after it, removing the attribute. Split so an interruption between them leaves values recoverable.
- [x] 9.3 **Tested explicitly, per the maintainer's request**: a customer exempt before the upgrade is still exempt after it, with nothing re-entered.
- [x] 9.4 Integration test running both patches against a seeded customer carrying a legacy value.

## 10. Verify

- [x] 10.1 `make test-unit`, checked against PHPUnit 9.5 / 10.5 / 12.5 conventions.
- [x] 10.2 `make phpstan` — no new findings, no baseline growth.
- [x] 10.3 `make lint`.
- [x] 10.4 `make integration-test`. **Run alone** — it shares a database with e2e and the two corrupt each other's config when concurrent.
- [x] 10.5 Full e2e matrix, alone, both transports.
- [x] 10.6 Confirm a store with the master switch off behaves exactly as before, since that is every existing install on upgrade.

## 11. Release

- [x] 11.1 CHANGELOG entry for the whole release: the certificate management feature, and the **breaking** removal of `taxcloud_cert` with what an integrator must do.
- [x] 11.2 Note that this release removes classes, so `setup:di:compile` is required — a stale compiled container fails loudly and confusingly.
