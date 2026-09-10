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

Every tax lookup in this module is limited to US destinations, so an exemption covering anywhere else could never apply. The forms SHALL make that limit visible rather than offering choices that cannot take effect.

#### Scenario: The limit is stated where it matters
- **WHEN** a merchant or customer records the states an exemption applies in
- **THEN** only US states are offered, and the form says that exemptions apply to US destinations

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

Resolution already prefers the certificate explicitly attached to a customer, but nothing writes that attachment. An administrator holding the certificate-management permission SHALL be able to attach any certificate the customer holds to that customer, and to clear the attachment, from the customer's admin page. The panel SHALL show which certificate is currently attached.

Creating a certificate from the customer's admin page SHALL attach it when the customer has none attached, so that an administrator who adds a certificate for a customer does not have to perform a second, undiscoverable step to make it apply. It SHALL NOT displace an attachment that already exists.

The certificate identifier SHALL be re-resolved against the customer's own certificates before being stored; an identifier that is not theirs SHALL be refused with the same answer whether it belongs to someone else or does not exist.

Attaching grants exemptions, so each change SHALL be recorded in the store's TaxCloud log with the customer, the previous and new values, and the administrator responsible.

Attachment SHALL be settable only by an administrator holding the certificate-management permission, and SHALL NOT be readable or writable through any customer-facing interface.

#### Scenario: Attaching a certificate makes it apply
- **WHEN** an administrator attaches a certificate to a customer and that customer orders to a state the certificate covers
- **THEN** the certificate is applied

#### Scenario: Creating a certificate attaches it when none is attached
- **WHEN** an administrator creates a certificate for a customer who has none attached
- **THEN** the new certificate becomes the customer's attached certificate, and applies to covered destinations without any further action

#### Scenario: Creating does not displace an existing attachment
- **WHEN** an administrator creates a certificate for a customer who already has one attached
- **THEN** the existing attachment is left as it is, and the new certificate is merely available to attach

#### Scenario: Clearing the attachment removes the exemption
- **WHEN** an administrator clears a customer's attached certificate
- **THEN** the customer is resolved as if none had been attached, and their orders are taxed

#### Scenario: A certificate that is not the customer's is refused
- **WHEN** an attachment request names a certificate that the customer does not hold
- **THEN** the attachment is refused and the stored value is unchanged, with the same answer whether the certificate belongs to someone else or does not exist

#### Scenario: Attachment changes are logged
- **WHEN** an administrator attaches or clears a customer's certificate
- **THEN** the change is recorded with the customer, the previous and new values, and the administrator who made it

#### Scenario: Customers cannot reach the attachment
- **WHEN** a customer-facing request attempts to read or set the attached certificate, whether through a storefront page, an API, or a form submission
- **THEN** the attempt has no effect on the stored attachment

### Requirement: Customers manage their own certificates

Where the store allows it, a signed-in customer SHALL be able to list, view and delete their own exemption certificates from their account area, and SHALL NOT be able to reach any other customer's.

Creating a certificate SHALL NOT be offered to customers: nothing verifies an exemption claim, so creation is confined to administrators, who are accountable for the certificates they record.

#### Scenario: Customer removes a certificate held for them
- **WHEN** a customer removes one of their certificates from My Account
- **THEN** it is deleted at TaxCloud and no longer applies to their orders

#### Scenario: A customer cannot reach another's certificates
- **WHEN** a request from one customer names a certificate belonging to another
- **THEN** it is neither displayed, applied, nor deleted

### Requirement: A stale certificate list can be refreshed on demand

Certificates change outside Magento — in the TaxCloud portal, or through another integration on the same account — and the module caches them. An administrator SHALL be able to discard a customer's cached certificates so the next resolution reads afresh, without waiting for the cache to expire.

#### Scenario: Refresh picks up an external change
- **WHEN** a certificate is changed in the TaxCloud portal and an administrator refreshes that customer's certificates
- **THEN** the next resolution reflects the change rather than the cached set
