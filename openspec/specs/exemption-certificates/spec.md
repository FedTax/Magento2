## Purpose

Manages a customer's TaxCloud exemption certificates and decides which one exempts an order: the identity they are filed under, the operations that read and write them on either transport, who is allowed to use which certificate, and what the order records about the one that applied.
## Requirements
### Requirement: A customer has a TaxCloud identity that certificates are filed under

Every certificate in TaxCloud is filed under a customer identifier chosen by whoever created it, and neither transport offers any way to find a certificate except by that identifier. Each Magento customer SHALL therefore have a TaxCloud customer identity, defaulting to the customer's own entity identifier when none is set, and a single resolution of that identity SHALL be used by every certificate operation — reading, creating and deleting alike — so that a certificate the module creates is one the module can later find.

The identity SHALL be settable only by an administrator holding the certificate-management permission, and SHALL NOT be readable or writable through any customer-facing interface. Setting it grants a customer the exemptions filed under that identifier, so each change SHALL be recorded in the store's TaxCloud log with the customer, the old and new values, and the administrator responsible.

Two Magento customers MAY be given the same identity. This is a supported arrangement — several buyers at one company covered by that company's certificate — and it follows that the permission guarding this field is what separates one customer's exemptions from another's.

The legacy `taxcloud_cert` attribute, which named a single certificate per customer, SHALL be retired: its values SHALL be carried into the new storage so that a customer exempt before the upgrade remains exempt after it, and no configuration that worked is silently lost.

#### Scenario: Identity defaults to the customer's entity identifier
- **WHEN** a certificate operation is performed for a customer whose TaxCloud identity has never been set
- **THEN** the customer's Magento entity identifier is used, so a store that has never configured anything behaves exactly as it did before this capability existed

#### Scenario: A configured identity is used for every operation
- **WHEN** an administrator sets a customer's TaxCloud identity to a value that differs from the entity identifier
- **THEN** certificates are listed, created and deleted under that value, and certificates filed under it become the customer's certificates

#### Scenario: Customers cannot reach the identity
- **WHEN** a customer-facing request attempts to read or set the TaxCloud identity, whether through a storefront page, an API, or a form submission
- **THEN** the attempt has no effect on the stored identity

#### Scenario: Identity changes are logged
- **WHEN** an administrator changes a customer's TaxCloud identity
- **THEN** the change is recorded with the customer, the previous and new values, and the administrator who made it

#### Scenario: Shared identity shares certificates
- **WHEN** two customers are given the same TaxCloud identity
- **THEN** both resolve the same set of certificates

#### Scenario: An existing attached certificate survives the upgrade
- **WHEN** a store upgrades with customers whose legacy attribute named a certificate
- **THEN** those customers still have that certificate applied to their orders afterwards, without an administrator re-entering anything

### Requirement: Certificate operations are available on both transports

Listing a customer's certificates, creating a certificate, and deleting one SHALL each be available as a gateway operation, dispatched to the transport selected by the `api_type` effective for the store of the entity being processed. Callers SHALL depend only on the gateway contract and SHALL NOT observe which transport answered.

Listing SHALL be by TaxCloud customer identity, since that is the only retrieval both transports support.

#### Scenario: Operations behave equivalently on either transport
- **WHEN** the same customer's certificates are listed, created or deleted on a store selecting `soap` and on a store selecting `rest`
- **THEN** each operation succeeds on both and returns equivalent results, differing only where the two APIs genuinely differ

#### Scenario: Certificates are readable across transports
- **WHEN** a certificate created on one transport is listed from a store using the other
- **THEN** it appears in the customer's certificates, so switching a store's API type does not strand the exemptions it already had

### Requirement: Certificates are represented independently of the transport that supplied them

The two APIs describe the same certificate differently, and the v3 representation omits data the v1 representation carries. Certificates SHALL be exposed to the rest of the module through one representation covering the identifier, the customer identity, the covered states, whether the certificate is disabled, and the descriptive detail needed to display and record it. Values that a transport does not supply, or supplies in a form it cannot map, SHALL be represented as absent rather than as a guess or a placeholder that reads as real data.

#### Scenario: Either transport produces the same representation
- **WHEN** the same certificate is retrieved over v1 and over v3
- **THEN** both produce a representation carrying the same identifier, customer identity, covered states and disabled state

#### Scenario: Detail a transport cannot supply is absent, not invented
- **WHEN** a transport does not carry a field, or returns a value for it outside the range it documents
- **THEN** that field is absent from the representation rather than being filled with a substitute value

### Requirement: Only a customer's own certificates may be applied to their order

TaxCloud applies any certificate belonging to the account to any cart that names it, without checking whose certificate it is. Ownership is therefore enforced entirely by this module. A certificate identifier that arrives from outside the module — a form submission, an API request, a stored value — SHALL NOT be applied, displayed as the customer's, or deleted until it has been re-resolved against the certificates belonging to that customer's TaxCloud identity. An identifier that does not resolve SHALL be treated as absent.

#### Scenario: A foreign certificate identifier is refused
- **WHEN** a request supplies a certificate identifier that is valid on the account but is not among the certificates of the customer the request concerns
- **THEN** it is not applied, and the order proceeds without exemption

#### Scenario: Deletion is confined to the customer's own certificates
- **WHEN** a request asks to delete a certificate that does not belong to the customer the request concerns
- **THEN** the certificate is not deleted

#### Scenario: A stored identifier is re-resolved, not trusted
- **WHEN** a certificate identifier held in Magento no longer resolves for the customer, because it was deleted in TaxCloud or the customer's identity changed
- **THEN** it is treated as absent and the order proceeds without exemption

### Requirement: One certificate is chosen per order, and only if it covers the destination

A customer may hold several certificates covering different states, so which one applies SHALL be decided per order. Only certificates that are not disabled and that cover the order's destination state are eligible. Among eligible certificates the choice SHALL be, in order: the certificate explicitly attached to the order or the customer; otherwise none. When no eligible certificate exists the order SHALL be taxed normally.

Resolution SHALL fail closed: if the customer's certificates cannot be established, the order is taxed rather than exempted.

When nothing has been explicitly attached, resolution SHALL reach that conclusion without consulting TaxCloud — a store that does not use exemptions must not pay an API call per cart to be told so.

#### Scenario: Only certificates covering the destination are eligible
- **WHEN** a customer holds a certificate covering one state and the order ships to another
- **THEN** that certificate is not applied and the order is taxed

#### Scenario: The explicitly attached certificate wins
- **WHEN** a certificate has been attached to the order or the customer and it covers the destination
- **THEN** that certificate is applied in preference to any other the customer holds

#### Scenario: A disabled certificate is never applied
- **WHEN** the only certificate covering the destination is disabled
- **THEN** no exemption is applied and the order is taxed

#### Scenario: Resolution failure taxes the order
- **WHEN** the customer's certificates cannot be retrieved
- **THEN** no exemption is applied and the order is taxed, rather than the failure being read as "no certificate restrictions"

#### Scenario: Nothing attached costs no API call
- **WHEN** a signed-in customer with no attached certificate shops
- **THEN** the order is taxed without the customer's certificates being requested from TaxCloud

### Requirement: An order records the certificate that exempted it

A certificate is the evidence that a sale was correctly untaxed, and it is held outside Magento in a store TaxCloud may change or delete. An order exempted by a certificate SHALL therefore record both the certificate's identifier and a copy of what the certificate said at the time of the sale — at least its covered states, exemption reason, purchaser and creation date. The record SHALL be written when the exemption is applied to the order and SHALL NOT be altered afterwards by later changes to the certificate.

#### Scenario: The applied certificate is recorded on the order
- **WHEN** an order is placed and a certificate exempts it
- **THEN** the order carries that certificate's identifier and a copy of its detail

#### Scenario: The record survives the certificate
- **WHEN** the certificate is later deleted or altered in TaxCloud
- **THEN** the order's record still shows what was relied on when the sale was made

#### Scenario: A taxed order records no certificate
- **WHEN** an order is placed with no exemption applied
- **THEN** the order carries no certificate record

### Requirement: Certificates created by the module apply to all of a customer's orders

The v3 API cannot create a certificate limited to a single purchase, and supporting them only on v1 would make a customer-visible feature appear and disappear with the store's API type. Certificates created through the module SHALL apply to all of the customer's orders on either transport. Single-purchase certificates that already exist in TaxCloud SHALL NOT be offered for selection, since the module cannot tell which order they were meant for.

#### Scenario: Created certificates are not single-purchase
- **WHEN** a certificate is created through the module on either transport
- **THEN** it applies to the customer's subsequent orders, and no single-purchase option is offered

#### Scenario: Existing single-purchase certificates are not offered
- **WHEN** a customer's certificates include one marked single-purchase
- **THEN** it is not among the certificates offered for selection

### Requirement: A customer's certificates are cached per store account

Certificates change rarely and are read on every tax calculation. A customer's certificates SHALL be cached, keyed so that entries are never shared between customers or between stores whose TaxCloud accounts differ. The cache SHALL be invalidated for the affected customer whenever the module creates or deletes one of their certificates, and SHALL fail closed: a retrieval failure SHALL NOT be cached as "this customer has no certificates".

#### Scenario: Different accounts do not share cached certificates
- **WHEN** the same customer is resolved on two stores whose TaxCloud accounts differ
- **THEN** each store resolves its own account's certificates

#### Scenario: Creating or deleting refreshes the customer's certificates
- **WHEN** the module creates or deletes a certificate for a customer
- **THEN** the next resolution for that customer reflects the change without waiting for the cache to expire

#### Scenario: A failed retrieval is not cached
- **WHEN** retrieving a customer's certificates fails
- **THEN** the failure is not stored, so a later attempt can succeed rather than being answered from a cached empty result

### Requirement: Certificate forms are completable by someone meeting them for the first time

A person creating an exemption certificate is asserting something they may be held liable for, on a form they will see rarely. The forms SHALL therefore present every choice in terms a merchant recognises rather than in the identifiers the APIs exchange.

Covered states SHALL be chosen from a list of states, not typed. The purchaser's state SHALL be chosen from a list, as everywhere else in the admin. Exemption reasons and business types SHALL be shown with readable names, and SHALL match the names the WooCommerce plugin uses for the same values so the two products do not describe one certificate two ways.

Where a choice is a tax question rather than a software question — which reason applies, which business type fits — the form SHALL link to TaxCloud's guidance rather than leave the merchant guessing. The form SHALL NOT imply that the choices have been checked: nothing verifies an exemption claim.

The forms SHALL follow the surrounding admin and storefront conventions, so the panel reads as part of the page it sits on.

#### Scenario: States are chosen, not typed
- **WHEN** a merchant records which states an exemption applies in
- **THEN** they select from a list of states rather than entering abbreviations as text

#### Scenario: Choices read as words
- **WHEN** a reason or business type is offered
- **THEN** it is shown as readable text such as "Wholesale Trade" rather than as the value the API exchanges

#### Scenario: Tax questions carry guidance
- **WHEN** a merchant must pick an exemption reason or business type
- **THEN** guidance from TaxCloud is one click away, and the form does not suggest that the answer has been validated

### Requirement: Forms do not collect what the store cannot use

A field that is accepted and discarded is worse than an absent one: it tells a merchant something was recorded when nothing was. Certificate forms SHALL only ask for values that the store's transport can actually carry.

The purchaser's tax identification number SHALL NOT be collected. The v3 API has no field for it, so on a REST store it can be neither stored nor shown back — and TaxCloud does not retain the certificate document either, so the number remains on the signed paperwork the merchant keeps.

#### Scenario: No field is silently discarded
- **WHEN** a certificate is created on a store using either transport
- **THEN** every value the form collected is one that transport records, and nothing entered is dropped without the merchant being told

### Requirement: Exemption certificates are offered for the United States only

Exemption certificates cover US states only, and a lookup for a destination outside the United States — including a Canadian destination priced under the `canada-tax` capability — SHALL NOT carry or record a certificate, so an exemption covering anywhere else could never apply. The forms SHALL make that limit visible rather than offering choices that cannot take effect.

#### Scenario: The limit is stated where it matters
- **WHEN** a merchant or customer records the states an exemption applies in
- **THEN** only US states are offered, and the form says that exemptions apply to US destinations

#### Scenario: A certificate holder shipping to Canada is taxed
- **WHEN** a customer holding an attached certificate places an order to a Canadian address in a store with Canadian tax enabled
- **THEN** the order is taxed and no certificate is applied or recorded

### Requirement: Exemption features are off until a merchant turns them on

Certificates are attestations that nothing verifies, and offering one is an invitation to stop paying tax. Every customer-facing exemption surface SHALL therefore be governed by a store-scoped setting that is off by default, so an install gains none of it by upgrading. While it is off, no exemption surface SHALL appear and certificate resolution SHALL behave exactly as it did before this capability existed.

#### Scenario: Disabled by default
- **WHEN** a store upgrades without changing any setting
- **THEN** no exemption surface is shown to customers or administrators, and orders are taxed as before

### Requirement: Administrators manage a customer's certificates

An administrator holding the certificate-management permission SHALL be able to see every certificate a customer holds, view one in detail, add one, and delete one, from the customer's admin page. The customer's TaxCloud identity SHALL be shown and editable alongside, together with an action that reports what that identity currently resolves to.

The discovery action is what makes a portal-created certificate findable: it is how an administrator learns that a customer resolves nothing, and confirms the identity they set is the right one. Without it, a wrong identity is indistinguishable from a customer who genuinely holds no certificates.

Because certificates are account-level while stores may use different TaxCloud accounts, the grid SHALL make clear which store's account it is reporting.

#### Scenario: Administrator sees and manages certificates
- **WHEN** an administrator with the permission opens a customer holding certificates
- **THEN** each certificate is listed with the states it covers and its detail, and can be viewed, added to, or deleted

#### Scenario: Discovery reports what an identity resolves
- **WHEN** an administrator runs the discovery action for a customer
- **THEN** the certificates currently filed under that customer's TaxCloud identity are reported, including when there are none

#### Scenario: Certificate management requires the permission
- **WHEN** an administrator without the certificate-management permission opens a customer
- **THEN** no certificate management is offered and any attempt to reach its endpoints is refused

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

### Requirement: A stale certificate list can be refreshed on demand

Certificates change outside Magento — in the TaxCloud portal, or through another integration on the same account — and the module caches them. An administrator SHALL be able to discard a customer's cached certificates so the next resolution reads afresh, without waiting for the cache to expire.

#### Scenario: Refresh picks up an external change
- **WHEN** a certificate is changed in the TaxCloud portal and an administrator refreshes that customer's certificates
- **THEN** the next resolution reflects the change rather than the cached set

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
