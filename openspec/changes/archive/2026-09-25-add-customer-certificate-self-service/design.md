## Context

Certificate management exists in two surfaces: the admin customer tab (list, create, attach/detach, delete, refresh, identity) and My Account (list, delete). Both sit on the same model layer — `CertificateRepository`, `CertificateResolver` (ownership and the attached id), `CertificateAttachment` (writes and logs the attachment), `CertificateFormReader` (form normalisation and validation) and `ExemptionPolicy` (who sees what). The storefront controllers share `AbstractCustomerAction`, which takes the customer from the session only.

This change gives My Account the admin's write operations for customers in nominated groups, and fixes two attachment gaps found on the way.

## Goals / Non-Goals

**Goals:** self-service create / attach / detach / refresh for nominated groups; deletion clears the attachment everywhere; a stale attachment never blocks auto-attach; one policy method for the gate.

**Non-Goals:** customer access to the TaxCloud identity or discovery; approvals or notifications; more than one applied certificate per customer. See the proposal.

## Decisions

### One policy method, enforced at every endpoint

`ExemptionPolicy::mayManage($customer, $store)` = `isVisibleTo()` AND the self-service switch AND the customer's group in the nominated list. The page asks it to decide what to render; each write controller asks it again before doing anything. The listing response carries `canManage` so the JS renders controls from the same answer the endpoints enforce.

The group is `$customer->getGroupId()` on the session's customer data object. `Session::getCustomerData()` loads it from the customer repository once per request, not from session storage, so a group change applies to the next request. The session's cached `customer_group_id` is not used.

*Alternative considered:* a single multiselect with "empty = off". Rejected as agreed: a switch makes turning it on a deliberate step, and Magento multiselects need `can_be_empty` to be clearable anyway.

### Settings

- `tax/taxcloud_settings/customer_certificates_enabled` — Yes/No, default 0.
- `tax/taxcloud_settings/customer_certificate_groups` — comma-separated group ids, default empty, `can_be_empty="1"`, source model listing logged-in groups only (`GroupManagementInterface::getLoggedInGroups()`).

Both depend on `exemptions_enabled`; the group list also depends on the switch.

### Storefront controllers mirror the admin ones

New `Controller/Certificate/Add`, `Attach`, `Refresh`, POST-only (form key validated by Magento's frontend CSRF check). Each refuses via the existing uniform `refuse()` when `mayManage()` is false. `Attach` re-resolves a non-empty id through `belongsToCustomer()`; an empty id clears. `Add` requires `attestation` = `1` before reading the form, then reuses `CertificateFormReader` and `setIfUnattached()` exactly as the admin `Add` does.

The store is the request's store (`currentStoreId()`), as for the existing storefront endpoints.

### Actor in the log

`CertificateAttachment::set()` already takes a free-text "responsible" string appended as `by …`. Administrators pass their username; customers pass `customer <id> (My Account)`. A string, not a new actor type: the only consumer is a log line, and the format stays distinguishable. Creation by a customer is logged by the `Add` controller with the covered states and the attestation.

### Deletion clears the attachment

Both `Delete` controllers delete at TaxCloud first, then clear the attachment if it named the deleted certificate. Order matters: if the delete fails, the customer keeps their exemption; if clearing fails after a successful delete, the stale-attachment rule below still keeps auto-attach working, and resolution already taxes a stale id.

The admin tab previously refused to delete the certificate in use ("Stop using first"). That refusal is removed; the admin confirmation says deleting the certificate in use also stops it applying.

### A stale attachment counts as none for auto-attach

`CertificateResolver::attachmentIsStale($customer, $store)` answers true only when an id is attached, the customer's certificates were retrieved, and the id is not among them. Retrieval failure answers false, so `setIfUnattached()` never displaces an attachment it cannot verify. Called right after a create, which has just invalidated the cache, so the list is fresh.

### My Account UI

The existing page grows an "In use" column, attach/stop-using buttons, a Refresh button and an Add form — the latter three only when `canManage`. The form uses Luma's `fieldset`/`field` markup, the same field names as the admin form (`data-field`), US states and business-type/reason options from the same sources, default billing address pre-fill, and a required attestation checkbox. The block's already-injected `CertificateFormReader` and region collection become used.

## Risks / Trade-offs

- **A nominated customer can stop paying tax at will.** → That is the feature; off by default, per group, per store, logged, with the attestation on record. Docs carry the liability warning.
- **Shared identity**: any customer sharing an identity can delete or (if nominated) attach the shared certificates. → Documented; unchanged from today for deletion.
- **Extra API call on create** for the stale check when something is attached. → Creation is rare.
