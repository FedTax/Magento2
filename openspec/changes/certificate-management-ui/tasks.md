## 1. Settings

- [x] 1.1 Add store-scoped settings in `etc/adminhtml/system.xml` + `etc/config.xml`: `exemptions_enabled` (master, **default off**), `exempt_customer_groups` (multiselect), `restrict_to_exempt_groups`, `company_name`.
- [x] 1.2 Add typed readers to `TaxcloudConfig`, store-aware like every other setting.
- [x] 1.3 Add an `ExemptionPolicy` service answering: are exemptions enabled for this store, is this customer treated as exempt, may this customer create certificates. One place, so the surfaces cannot disagree.
- [x] 1.4 Unit tests: defaults leave everything off; group membership; restrict-mode; store scoping.

## 2. Group auto-apply

- [x] 2.1 Fill the existing branch in `CertificateResolver::resolve()` — where nothing is claimed, consult `ExemptionPolicy` and pick the first eligible certificate for an exempt-group customer.
- [x] 2.2 Preserve the no-API-call property: a store applying nothing automatically must still resolve without listing certificates.
- [x] 2.3 Honour an explicit clearing as distinct from "nothing chosen", so an exempt-group customer can decline.
- [x] 2.4 Unit tests: auto-applies for a group member, does not for a non-member, respects clearing, still fails closed, and makes no API call when the store auto-applies nothing.

## 3. Per-order and per-customer attachment storage

- [x] 3.1 Add storage for the certificate attached to a customer, replacing `taxcloud_cert`, and for one chosen per quote/order.
- [x] 3.2 Record an explicit clearing on the quote, distinct from absence — `quote.taxcloud_certificate_cleared`, honoured by the resolver's `$cleared` argument.
- [x] 3.3 Unit tests for both. Writing them surfaced a real defect: a decline suppressed group auto-apply but NOT a certificate an administrator had pinned to the customer, so a shopper choosing "no exemption" was exempted anyway and the order filed against a certificate they had refused. A decline now beats every standing arrangement, while a certificate chosen for the cart still wins over a stale decline.

## 4. Admin — customer page

- [x] 4.1 Certificate grid on the customer edit page: list with covered states and detail, view, add, delete. Behind the certificate-management ACL.
- [x] 4.2 Surface the TaxCloud identity field with its note, editable only with the same permission.
- [x] 4.3 Discovery action reporting what the current identity resolves — including reporting "none", which is the diagnostic a wrong identity needs. `Controller/Adminhtml/Certificate/Index` reports the resolved identity, whether it is the default, and the certificates found; a retrieval failure is reported as failure rather than as an empty list.
- [x] 4.4 Name the store whose TaxCloud account the grid queried; certificates are account-level and stores may differ.
- [x] 4.5 Controllers pass every identifier through `CertificateResolver`; none compares identifiers itself. `Delete` refuses a certificate that is not the customer's, with the same message whether it belongs to someone else or does not exist.
- [x] 4.6 Unit tests: form shape checking (`CertificateFormReaderTest`), ownership refusal and fail-closed behaviour (`CertificateResolverTest`), policy gating (`ExemptionPolicyTest`). Controller-level ACL is enforced by `ADMIN_RESOURCE` on the shared base and verified live: the panel renders and loads real certificates.

## 5. Admin — order page

- [x] 5.1 Show the applied certificate and its recorded snapshot on the order view — read from the order's OWN record, not from TaxCloud, since the question an order screen answers cannot change after the sale. Verified live against order 784.
- [ ] 5.2 Allow changing it while the order is not yet captured; recalculate tax on change.
- [x] 5.3 Read-only once captured — the sale is filed and the record is evidence, not a preference.
- [x] 5.4 The editable/read-only gate is covered by `OrderCertificateRecordTest` (the record is written once and never revised) and by the capture flag the block reads. Recalculation-on-change is exercised through the checkout selector.

## 6. Storefront — My Account

- [x] 6.1 Certificate section: list, view, add, delete, gated by the master switch.
- [x] 6.2 Creation confined to customers the store treats as exempt.
- [x] 6.3 The form states that the certificate applies to all future orders — v3 cannot create a single-purchase certificate, so a customer who thinks they are claiming a one-off must be told otherwise.
- [x] 6.4 Every action re-resolves ownership server-side; a customer cannot see, apply or delete another's certificate.
- [ ] 6.5 Unit tests: ownership refusal on view/delete, creation refused outside exempt groups, refused entirely when disabled.

## 7. Checkout — selection only

- [x] 7.1 Offer only certificates covering the destination state; offer "none".
- [x] 7.2 Recalculate tax when the selection changes.
- [x] 7.3 No creation path; direct customers to My Account instead.
- [x] 7.4 Never trust the submitted identifier — re-resolve it.
- [x] 7.5 Unit tests: filtering by destination, refusal of a foreign identifier, clearing.

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
