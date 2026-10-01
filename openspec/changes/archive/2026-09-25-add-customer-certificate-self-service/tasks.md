## 1. Settings and policy

- [x] 1.1 Add `customer_certificates_enabled` (default 0) and `customer_certificate_groups` (default empty, `can_be_empty`) to `etc/config.xml` and `etc/adminhtml/system.xml`, depending on `exemptions_enabled`
- [x] 1.2 Add `Model/Config/Source/CustomerGroups` listing logged-in groups only
- [x] 1.3 Add `TaxcloudConfig` accessors for both settings (store-scoped)
- [x] 1.4 Add `ExemptionPolicy::mayManage()`; remove the orphaned docblock; correct the Company Name comment

## 2. Attachment fixes

- [x] 2.1 Add `CertificateResolver::attachmentIsStale()` (fails closed on retrieval errors)
- [x] 2.2 `CertificateAttachment::setIfUnattached()` treats a stale attachment as none
- [x] 2.3 Admin `Delete`: delete the certificate in use and clear its attachment instead of refusing; update the admin JS/confirmation
- [x] 2.4 Storefront `Delete`: clear the attachment after deleting the certificate in use

- [x] 2.5 Guard the attachment attribute on the customer repository: only `CertificateAttachment` (via `AttachmentWriteScope`) or a permitted non-customer-facing context may change it; route the seed script through `CertificateAttachment`

## 3. Storefront self-service

- [x] 3.1 `AbstractCustomerAction`: inject `CertificateAttachment`, add `mayManage()` and the customer actor string
- [x] 3.2 `Listing` returns `attached` and `canManage`
- [x] 3.3 New `Add` controller (attestation, form reader, create, attach-if-none, log)
- [x] 3.4 New `Attach` controller (ownership re-resolved, empty clears)
- [x] 3.5 New `Refresh` controller
- [x] 3.6 `Manage` block: endpoints, form options, US states, billing pre-fill, `canManage` (the unused form-reader dependency is dropped rather than wired)
- [x] 3.7 `manage.phtml` and `my-account.js`: in-use column, attach/stop-using, refresh, add form with attestation

## 4. Tests

- [x] 4.1 Unit tests: policy, config accessors, group source model, stale attachment, setIfUnattached, both Delete controllers, new storefront controllers, Listing fields — compatible with PHPUnit 9.5/10.5/12.5
- [x] 4.2 Propose integration/e2e coverage to the maintainer rather than assuming it (approved)
- [x] 4.4 Integration tests: store-scoped nomination through the real policy/config; storefront self-service lifecycle against a REST mock; the repository guard refusing customer-facing writes
- [x] 4.5 E2E: `self-service-on` pass (seeded Wholesale `trusted-customer@example.com` under a `-trusted` run identity; cleanup script covers it): nominated customer adds a certificate and checkout is exempt; non-nominated customer sees no controls; stop/use; removing the certificate in use re-taxes
- [x] 4.3 Run `make` lint, PHPStan and unit targets; judge by exit code

## 5. Documentation

- [x] 5.1 `docs/customer-account.md`: self-service section, in-use indicator, delete clears attachment
- [x] 5.2 `docs/exemptions-setup.md`: letting trusted customers manage certificates, with warning
- [x] 5.3 `docs/settings.md`: the two settings
- [x] 5.4 `docs/managing-certificates.md`: deleting the certificate in use; customer-made changes in the log; shared-identity note
- [x] 5.5 `CHANGELOG.md`
- [x] 5.7 Developer docs: `docs/E2E_TESTS.md` (self-service pass, trusted customer), `docs/INTEGRATION_TESTS.md` (certificate proxy eviction)
- [x] 5.6 Regenerate `docs/images/certificates-my-account.png` from the E2E stack (`make docs-screenshots`) — the table now has an In use column; plus new `certificates-my-account-add.png` and `exemptions-self-service-settings.png`
