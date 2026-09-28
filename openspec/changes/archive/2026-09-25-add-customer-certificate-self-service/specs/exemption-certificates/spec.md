## ADDED Requirements

### Requirement: Customers in nominated groups manage their certificates themselves

Some merchants vouch for particular buyers and would rather those buyers keep their own exemption paperwork. A store SHALL be able to nominate customer groups whose signed-in members may, from My Account, do for their own certificates what an administrator can do from the customer's admin page: create a certificate, attach one of their certificates as the one in use, clear the attachment, and refresh their certificates from TaxCloud.

This SHALL be governed by two store-scoped settings, both requiring exemptions to be enabled: a switch that is off by default, and a list of customer groups that is empty by default. A customer SHALL be allowed to manage their certificates only when exemptions are enabled, the switch is on, and their current customer group is in the list — all resolved against the store the request is made on. The switch on with no group listed SHALL allow nobody. The guest group SHALL NOT be offered, since guests have no account area.

The customer's group SHALL be read from the customer's current record, so a customer removed from a nominated group loses the controls on their next request rather than when their session ends. Every self-service request SHALL be refused unless the customer is allowed at the moment it is made, regardless of what the page showed.

The customer SHALL always be the signed-in customer; no self-service request SHALL accept a customer or TaxCloud identity from the request. Certificates are created under the customer's TaxCloud identity, which SHALL remain settable only by an administrator.

Creating a certificate is the customer's own claim of exemption, so the form SHALL require the customer to attest that the claim is accurate and that they will provide the signed certificate on request, and the attestation SHALL be checked when the request is processed, not only in the browser. The form SHALL collect the same values as the administrator's form, validated the same way, and MAY pre-fill the purchaser's name and address from the customer's default billing address.

Each create, attach and clear made by a customer SHALL be recorded in the store's TaxCloud log with the customer, the certificate, the previous and new attachment where it changed, and the customer as the actor, distinguishable from a change made by an administrator.

The settings govern who may make changes, not how orders are exempted: turning self-service off, or removing a customer from a nominated group, SHALL leave any certificate they attached in force until an administrator changes it.

#### Scenario: Off by default
- **WHEN** a store enables exemptions without changing the self-service settings
- **THEN** no customer is offered creation, attachment or refresh, and every such request is refused

#### Scenario: Switch on with no group nominated allows nobody
- **WHEN** the self-service switch is on and no customer group is nominated
- **THEN** no customer is offered or allowed self-service

#### Scenario: A nominated customer creates a certificate
- **WHEN** a signed-in customer in a nominated group submits a valid certificate with the attestation accepted
- **THEN** the certificate is filed with TaxCloud under the customer's TaxCloud identity and appears in their list

#### Scenario: A certificate without the attestation is refused
- **WHEN** a customer submits a certificate without accepting the attestation, including by a request made outside the page
- **THEN** nothing is filed with TaxCloud and the customer is told the attestation is required

#### Scenario: A customer outside the nominated groups is refused
- **WHEN** a signed-in customer whose group is not nominated sends a create, attach, clear or refresh request
- **THEN** the request is refused and nothing changes

#### Scenario: The nomination is per store
- **WHEN** a customer's group is nominated on one store and not on another
- **THEN** the customer may manage their certificates on the first store only

#### Scenario: Leaving a nominated group removes the controls at once
- **WHEN** an administrator moves a signed-in customer out of a nominated group
- **THEN** the customer's next self-service request is refused, and a certificate they attached earlier remains attached

#### Scenario: A nominated customer changes the certificate in use
- **WHEN** a customer in a nominated group attaches another of their own certificates
- **THEN** that certificate replaces the previous attachment and applies to their subsequent orders to states it covers

#### Scenario: A nominated customer cannot attach a certificate that is not theirs
- **WHEN** a customer in a nominated group asks to attach a certificate identifier that is not among their own certificates
- **THEN** the request is refused with the same answer whether the certificate belongs to someone else or does not exist, and the attachment is unchanged

#### Scenario: Customer changes are logged as the customer's
- **WHEN** a customer creates a certificate or changes their attachment
- **THEN** the store's TaxCloud log records the change with the customer as the actor

## MODIFIED Requirements

### Requirement: Customers manage their own certificates

Where the store allows it, a signed-in customer SHALL be able to list, view and delete their own exemption certificates from their account area, and SHALL NOT be able to reach any other customer's.

The account area SHALL show the customer which of their certificates, if any, is the one in use. Changing it, and creating a certificate, SHALL be offered only to customers the store has nominated for self-service; for everyone else the indicator is read-only. Nothing verifies an exemption claim, so outside the nominated groups creation remains confined to administrators, who are accountable for the certificates they record.

Deleting a certificate that is in use SHALL also clear the attachment, so the customer is never left attached to a certificate that no longer exists.

#### Scenario: Customer removes a certificate held for them
- **WHEN** a customer removes one of their certificates from My Account
- **THEN** it is deleted at TaxCloud and no longer applies to their orders

#### Scenario: Deleting the certificate in use clears the attachment
- **WHEN** a customer deletes the certificate that is attached to them
- **THEN** the certificate is deleted, their attachment is cleared, and the change is logged

#### Scenario: A customer sees which certificate is in use
- **WHEN** a customer with an attached certificate opens their certificates in My Account
- **THEN** the attached certificate is marked as in use, whether or not the customer may change it

#### Scenario: A customer outside the nominated groups cannot create or attach
- **WHEN** a customer whose group is not nominated for self-service opens their certificates
- **THEN** no create, attach or clear control is offered

#### Scenario: A customer cannot reach another's certificates
- **WHEN** a request from one customer names a certificate belonging to another
- **THEN** it is neither displayed, applied, attached nor deleted

### Requirement: A certificate can be attached to a customer

Resolution prefers the certificate explicitly attached to a customer. An administrator holding the certificate-management permission SHALL be able to attach any certificate the customer holds to that customer, and to clear the attachment, from the customer's admin page. A customer the store has nominated for self-service SHALL be able to do the same for their own certificates from My Account. Both surfaces SHALL show which certificate is currently attached.

Creating a certificate — from the customer's admin page, or by a nominated customer from My Account — SHALL attach it when the customer has no certificate attached, so that adding a certificate does not require a second, undiscoverable step to make it apply. An attachment naming a certificate that the customer's certificates no longer include SHALL count as no attachment for this purpose. Creation SHALL NOT displace an attachment to a certificate the customer still holds, and SHALL NOT displace any attachment when the customer's certificates cannot be retrieved.

Deleting the attached certificate, from either surface, SHALL clear the attachment.

The certificate identifier SHALL be re-resolved against the customer's own certificates before being stored; an identifier that is not theirs SHALL be refused with the same answer whether it belongs to someone else or does not exist.

Attaching grants exemptions, so each change SHALL be recorded in the store's TaxCloud log with the customer, the previous and new values, and the administrator or customer responsible.

Outside the self-service described above, the attachment SHALL NOT be writable through any customer-facing interface — including the customer APIs, which accept customer attributes generally — nor by an administrator without the certificate-management permission, whatever form or path carries the value. A refused value SHALL be left as stored without failing the rest of the customer save, and the refusal SHALL be logged.

#### Scenario: Attaching a certificate makes it apply
- **WHEN** an administrator, or a nominated customer, attaches a certificate to the customer and that customer orders to a state the certificate covers
- **THEN** the certificate is applied

#### Scenario: Creating a certificate attaches it when none is attached
- **WHEN** an administrator or a nominated customer creates a certificate for a customer who has none attached
- **THEN** the new certificate becomes the customer's attached certificate, and applies to covered destinations without any further action

#### Scenario: A stale attachment does not block the new certificate
- **WHEN** a certificate is created for a customer whose attachment names a certificate that no longer exists in their certificates
- **THEN** the new certificate becomes the attached certificate

#### Scenario: Creating does not displace an existing attachment
- **WHEN** a certificate is created for a customer whose attached certificate they still hold
- **THEN** the existing attachment is left as it is, and the new certificate is merely available to attach

#### Scenario: An unverifiable attachment is not displaced
- **WHEN** a certificate is created for a customer with an attachment and their certificates cannot be retrieved from TaxCloud
- **THEN** the existing attachment is left as it is

#### Scenario: Deleting the attached certificate clears the attachment
- **WHEN** an administrator deletes the certificate attached to a customer
- **THEN** the certificate is deleted and the customer's attachment is cleared, and the change is logged

#### Scenario: Clearing the attachment removes the exemption
- **WHEN** an administrator or a nominated customer clears the customer's attached certificate
- **THEN** the customer is resolved as if none had been attached, and their orders are taxed

#### Scenario: A certificate that is not the customer's is refused
- **WHEN** an attachment request names a certificate that the customer does not hold
- **THEN** the attachment is refused and the stored value is unchanged, with the same answer whether the certificate belongs to someone else or does not exist

#### Scenario: Attachment changes are logged
- **WHEN** an administrator or a customer attaches or clears a customer's certificate
- **THEN** the change is recorded with the customer, the previous and new values, and who made it

#### Scenario: The attachment cannot be set around the certificate controls
- **WHEN** a customer — nominated or not — sends a value for the attached certificate through the customer API, an account form, or any path other than the My Account certificate controls
- **THEN** the stored attachment is unchanged, the rest of the customer save succeeds, and the refusal is logged

#### Scenario: An administrator without the permission cannot change the attachment
- **WHEN** an administrator without the certificate-management permission saves a customer carrying a different attached certificate
- **THEN** the stored attachment is unchanged
