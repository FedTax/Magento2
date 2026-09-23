## MODIFIED Requirements

### Requirement: An order's destination falls back to its billing address

Every order-side operation that reports a destination to TaxCloud — filing the order at capture, re-creating it as exempt, refunding it, verifying its address, and choosing the state an exemption certificate must cover — SHALL resolve that destination from the order's shipping address when the order has one and from the order's billing address when it does not.

A Magento order made only of non-shippable items has no shipping address at all. Such an order SHALL therefore be filed, refunded and reversed exactly as any other order is; the absence of a shipping address SHALL NOT be treated as an absence of a destination.

A usable destination is a United States address with a valid ZIP code, or — only when the order's store has Canadian tax in effect (see the `canada-tax` capability) — a Canadian address with a province and a valid Canadian postal code. Resolution SHALL fail, and the operation SHALL report failure rather than substitute an invented address, only when the resolved address is not a usable destination — no address at all, a country that is not usable for the order's store, a missing Canadian province, or an unparseable postal code.

#### Scenario: A digital-only order is filed with TaxCloud
- **WHEN** an order made only of virtual or downloadable items is captured
- **THEN** the order is filed with its billing address as the destination, and the capture reports success

#### Scenario: A physical order still files against its shipping address
- **WHEN** an order carrying a shipping address is captured
- **THEN** the destination is the shipping address, and the billing address is not consulted

#### Scenario: A digital-only order can be refunded and reversed
- **WHEN** a tax-only refund or a cancellation reversal runs for an order made only of non-shippable items
- **THEN** the operation builds its destination from the billing address and proceeds, rather than abandoning the reversal

#### Scenario: An exemption certificate covers the billing state for a digital-only order
- **WHEN** an exempt customer places an order made only of non-shippable items
- **THEN** the certificate is matched against the state of the billing address

#### Scenario: A Canadian order resolves when its store enables Canadian tax
- **WHEN** an order shipped to a Quebec address is captured in a store with Canadian tax in effect
- **THEN** the destination is the Quebec shipping address with its province, postal code and country

#### Scenario: No usable address fails loudly
- **WHEN** an order has neither a shipping nor a billing address that yields a usable destination for its store
- **THEN** no destination is produced, the operation reports failure, and the reason is logged
