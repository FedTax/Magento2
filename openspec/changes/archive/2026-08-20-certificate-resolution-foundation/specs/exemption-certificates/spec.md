## Purpose

Manages a customer's TaxCloud exemption certificates and decides which one exempts an order: the identity they are filed under, the operations that read and write them on either transport, who is allowed to use which certificate, and what the order records about the one that applied.

## ADDED Requirements

### Requirement: A customer has a TaxCloud identity that certificates are filed under

Every certificate in TaxCloud is filed under a customer identifier chosen by whoever created it, and neither transport offers any way to find a certificate except by that identifier. Each Magento customer SHALL therefore have a TaxCloud customer identity, defaulting to the customer's own entity identifier when none is set, and a single resolution of that identity SHALL be used by every certificate operation — reading, creating and deleting alike — so that a certificate the module creates is one the module can later find.

The identity SHALL be settable only by an administrator holding the certificate-management permission, and SHALL NOT be readable or writable through any customer-facing interface. Setting it grants a customer the exemptions filed under that identifier, so each change SHALL be recorded in the store's TaxCloud log with the customer, the old and new values, and the administrator responsible.

Two Magento customers MAY be given the same identity. This is a supported arrangement — several buyers at one company covered by that company's certificate — and it follows that the permission guarding this field is what separates one customer's exemptions from another's.

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

A customer may hold several certificates covering different states, so which one applies SHALL be decided per order. Only certificates that are not disabled and that cover the order's destination state are eligible. Among eligible certificates the choice SHALL be, in order: the certificate explicitly attached to the order or the customer; otherwise a certificate selected automatically for customers the store treats as exempt; otherwise none. When no eligible certificate exists the order SHALL be taxed normally.

Resolution SHALL fail closed: if the customer's certificates cannot be established, the order is taxed rather than exempted.

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
