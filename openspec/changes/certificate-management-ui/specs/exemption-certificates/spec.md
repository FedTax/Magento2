## ADDED Requirements

### Requirement: Exemption features are off until a merchant turns them on

Certificates are attestations that nothing verifies, and offering one is an invitation to stop paying tax. Every customer-facing exemption surface SHALL therefore be governed by a store-scoped setting that is off by default, so an install gains none of it by upgrading. While it is off, no exemption surface SHALL appear and certificate resolution SHALL behave exactly as it did before this capability existed.

A store SHALL additionally be able to nominate customer groups it treats as exempt, and to restrict the exemption surfaces to those groups alone.

#### Scenario: Disabled by default
- **WHEN** a store upgrades without changing any setting
- **THEN** no exemption surface is shown to customers or administrators, and orders are taxed as before

#### Scenario: Restricting to exempt groups hides the surfaces from everyone else
- **WHEN** a store enables exemptions and restricts them to nominated customer groups
- **THEN** a customer outside those groups sees no exemption surface, and a certificate identifier submitted by one is not applied

### Requirement: Customers in an exempt group are exempted without choosing

A merchant who has vetted a customer and placed them in an exempt group has already made the exemption decision; asking them to re-make it on every order is friction without value. For a customer in a nominated exempt group, when no certificate has been explicitly chosen for the order, a certificate covering the destination state SHALL be applied automatically. A customer who explicitly clears the applied certificate SHALL NOT have one re-applied for that order.

Automatic application SHALL be subject to the same eligibility and ownership rules as an explicit choice: only the customer's own certificates, only ones covering the destination, never disabled or single-purchase ones.

#### Scenario: Exempt-group customer needs no interaction
- **WHEN** a customer in a nominated exempt group holds a certificate covering the destination and chooses nothing
- **THEN** that certificate is applied and the order is untaxed

#### Scenario: Customers outside the groups are not auto-applied
- **WHEN** a customer who holds a covering certificate but is in no nominated group chooses nothing
- **THEN** no certificate is applied and the order is taxed

#### Scenario: An explicit clearing is respected
- **WHEN** a customer in an exempt group removes the applied certificate from their order
- **THEN** no certificate is re-applied automatically for that order

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

### Requirement: Customers manage their own certificates

Where the store allows it, a signed-in customer SHALL be able to list, view, add and delete their own exemption certificates from their account area, and SHALL NOT be able to reach any other customer's.

A certificate created here applies to all of the customer's subsequent orders, and the interface SHALL say so before it is created. The alternative — a certificate limited to one purchase — cannot be created on the v3 API at all, so a customer who believes they are claiming a one-off exemption would otherwise be made permanently exempt without being told.

Creating a certificate SHALL be available only to customers the store treats as exempt. A merchant vetting a customer into an exempt group is the control that stands in for the verification neither TaxCloud nor this module performs.

#### Scenario: Customer adds and removes their own certificate
- **WHEN** a customer permitted to create certificates adds one from their account area
- **THEN** it is filed under their TaxCloud identity, appears in their list, and applies to their subsequent orders

#### Scenario: The permanence is stated before creation
- **WHEN** a customer is presented with the certificate creation form
- **THEN** it states that the certificate will apply to all of their future orders

#### Scenario: A customer cannot reach another's certificates
- **WHEN** a request from one customer names a certificate belonging to another
- **THEN** it is neither displayed, applied, nor deleted

#### Scenario: Creation is confined to exempt customers
- **WHEN** a customer the store does not treat as exempt attempts to create a certificate
- **THEN** the attempt is refused

### Requirement: Checkout selects a certificate but never creates one

At checkout a signed-in customer SHALL be able to choose among their certificates that cover the destination state, and to choose none. Certificates that do not cover the destination SHALL NOT be offered, since choosing one could only mislead.

Checkout SHALL NOT create certificates. Creation is a deliberate, permanent act belonging in the account area; offering it inside a checkout step invites a customer to make themselves permanently exempt while trying to complete one order.

Changing the selection SHALL recalculate the order's tax.

#### Scenario: Only covering certificates are offered
- **WHEN** a customer at checkout holds certificates covering different states
- **THEN** only those covering the destination are offered for selection

#### Scenario: Selecting a certificate recalculates tax
- **WHEN** a customer selects a covering certificate at checkout
- **THEN** the order's tax is recalculated and the exemption is reflected in the totals

#### Scenario: Checkout offers no way to create a certificate
- **WHEN** a customer at checkout holds no certificate covering the destination
- **THEN** they are directed to their account area rather than offered a creation form

### Requirement: An order's certificate can be changed until it is filed

An administrator SHALL be able to change the certificate applied to an order while it has not yet been captured in TaxCloud, and the order's tax SHALL be recalculated accordingly. Once captured, the applied certificate SHALL be shown but SHALL NOT be changeable: the sale has been filed, and the order's record is evidence of what was relied on rather than a current preference.

#### Scenario: Uncaptured order can be corrected
- **WHEN** an administrator changes the certificate on an order not yet captured
- **THEN** the certificate is applied and the order's tax recalculated

#### Scenario: A filed order is read-only
- **WHEN** an administrator opens an order already captured in TaxCloud
- **THEN** the applied certificate is shown and cannot be changed

### Requirement: A stale certificate list can be refreshed on demand

Certificates change outside Magento — in the TaxCloud portal, or through another integration on the same account — and the module caches them. An administrator SHALL be able to discard a customer's cached certificates so the next resolution reads afresh, without waiting for the cache to expire.

#### Scenario: Refresh picks up an external change
- **WHEN** a certificate is changed in the TaxCloud portal and an administrator refreshes that customer's certificates
- **THEN** the next resolution reflects the change rather than the cached set

## MODIFIED Requirements

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

### Requirement: One certificate is chosen per order, and only if it covers the destination

A customer may hold several certificates covering different states, so which one applies SHALL be decided per order. Only certificates that are not disabled and that cover the order's destination state are eligible. Among eligible certificates the choice SHALL be, in order: the certificate explicitly attached to the order or the customer; otherwise a certificate selected automatically for customers the store treats as exempt; otherwise none. When no eligible certificate exists the order SHALL be taxed normally.

Resolution SHALL fail closed: if the customer's certificates cannot be established, the order is taxed rather than exempted.

When nothing has been explicitly chosen and the store applies nothing automatically, resolution SHALL reach that conclusion without consulting TaxCloud — a store that does not use exemptions must not pay an API call per cart to be told so.

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

#### Scenario: Nothing claimed and nothing automatic costs no API call
- **WHEN** a signed-in customer with no attached certificate shops on a store that applies none automatically
- **THEN** the order is taxed without the customer's certificates being requested from TaxCloud
