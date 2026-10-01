## Why

Only an administrator can create an exemption certificate or choose which one applies, so every exempt B2B buyer's paperwork turns into admin data entry — and a buyer with certificates in several states waits on an admin each time the one in use needs to change. Some merchants already vouch for certain buyers (a wholesale or reseller customer group) and would rather those buyers keep their own certificates. Opening that to every customer was rejected in the original design as a self-service "stop charging me tax" button; confining it to customer groups the merchant nominates, off by default, keeps the merchant's control while removing the bottleneck.

## What Changes

- **Two new store-scoped settings**, shown only when *Enable Exemption Certificates* is on:
  - **Let Customers Manage Certificates** (Yes/No, **default No**).
  - **Customer Groups That Can Manage Certificates** (multiselect, **default empty**; NOT LOGGED IN is not offered; can be saved empty). On with no group selected means nobody.
- **Self-service in My Account for customers in a nominated group** — everything an administrator can do with a customer's certificates, for their own:
  - **Create** a certificate, with the same fields and validation as the admin form, purchaser name and address pre-filled from the default billing address, and a **required attestation** that the claim is accurate and the signed certificate will be provided on request (checked server-side).
  - **Attach** any of their certificates as the one in use, and **detach** it.
  - **Refresh** their certificates from TaxCloud.
  - Creating attaches the new certificate when nothing valid is attached, exactly as creation from the admin does; it never displaces an existing attachment.
- **Every customer with access to My Account certificates sees which one is in use** (read-only unless in a nominated group). Viewing and deleting stay available to every signed-in customer, as today.
- **Deleting a certificate clears its attachment**, from My Account and from the admin. Today a My Account delete leaves the attachment pointing at a certificate that no longer exists, and the admin refuses to delete the certificate in use until it is detached.
- **An attachment that no longer resolves counts as "none attached"** when deciding whether creation should attach — so a customer whose certificate was deleted (in the portal, or before this fix) is exempted by the next one they or an administrator create.
- **One policy decides who may manage**: a new `ExemptionPolicy` check (exemptions on, self-service on, customer's group nominated), used by the page and enforced again by every self-service endpoint. The customer's group is read from the customer record, not the session, so removing someone from a group takes effect on their next request.
- **Audit**: every customer create, attach and detach is logged like the admin equivalents, with the actor recorded as the customer rather than an administrator, and the attestation noted on creation.
- **The attachment is guarded on the customer repository.** It is an ordinary customer attribute, so the customer REST/GraphQL APIs accept it in `custom_attributes` and the admin customer form carries it for administrators without the certificate permission. A new repository plugin reverts any change that does not come through certificate management or a permitted backend context, as the identity guard already does for the TaxCloud identity.
- **Tidy-up**: the unused form dependencies in the My Account block become used; the orphaned `ExemptionPolicy` docblock and the stale *Company Name* comment are corrected.

## Non-goals

- **The TaxCloud identity and the discovery action stay admin-only.** The identity decides whose certificates a customer holds; letting a customer set it would let them claim any certificate on the TaxCloud account. This is the one admin capability not offered in My Account.
- **Approval before a certificate applies.** The group nomination is the trust decision. A review step would be a different feature.
- **Email notification to the merchant** on customer changes. The log is the record.
- **Applying more than one certificate per customer.** Resolution still applies only the attached certificate; a customer covered in several states switches between certificates rather than holding them all in force.
- **Marking customer-created certificates in the admin grid.** TaxCloud does not record who created a certificate.
- **Restricting deletion of certificates shared through a common TaxCloud identity.** Any customer sharing an identity can already delete the shared certificates; this is documented rather than changed.
- **Uploading the signed certificate document**, single-purchase certificates, guest exemptions, rate limiting.

## Store-scoping implications

- Both settings are `showInDefault/Website/Store` and resolve against the store the My Account request is served from, consistent with every existing storefront certificate endpoint.
- Customer groups are global while the nomination is per store, so the same customer may manage certificates on one store and not another. Intended: a B2B store and a consumer store can share a catalog and customer base.
- Certificates belong to the store's TaxCloud account, so on a multi-store install with different accounts a customer creates, attaches and refreshes against the account of the store they are on — the same scoping the admin panel already reports.
- The settings govern the surfaces, not resolution: turning self-service off, or removing a customer from a nominated group, removes the controls but leaves any certificate they attached in force until an administrator changes it.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `exemption-certificates`:
  - *Customers manage their own certificates* — creation is no longer forbidden outright; customers see which certificate is in use; deleting clears the attachment.
  - *A certificate can be attached to a customer* — customers in a nominated group may attach and detach their own certificates; creation's attach-if-none rule ignores an attachment that no longer resolves.
  - New requirement: *Customers in nominated groups manage their certificates themselves* — the settings and their defaults, the group gate, session-only customer, the attestation, logging, and loss of nomination.

## Impact

- **Config**: `etc/adminhtml/system.xml`, `etc/config.xml`, `Model/Config/TaxcloudConfig.php`; new customer-group source model excluding NOT LOGGED IN.
- **Policy**: `Model/Certificate/ExemptionPolicy.php` (new manage check).
- **Storefront**: `Controller/Certificate/` — new `Add`, `Attach`, `Refresh`; `Delete` clears the attachment; `Listing` reports the attached certificate and what the customer may do. `Block/Certificate/Manage.php`, `view/frontend/templates/certificate/manage.phtml`, `view/frontend/web/js/certificate/my-account.js`.
- **Attachment**: `Model/Certificate/CertificateAttachment.php` — actor can be an administrator or a customer; attach-if-none ignores a stale attachment.
- **Admin**: `Controller/Adminhtml/Certificate/Delete.php` deletes the certificate in use and clears the attachment instead of refusing; `view/adminhtml/web/js/certificate/admin-certificates.js` enables its Delete button. `Add.php` inherits the stale-attachment fix.
- **Docs**: `docs/customer-account.md`, `docs/exemptions-setup.md`, `docs/settings.md`, `docs/managing-certificates.md` (customer-made changes, shared-identity deletion), screenshots, `CHANGELOG.md`.
- **Behavioural**: none for a store that leaves the new setting off, apart from the delete fix and the read-only "in use" indicator in My Account.
