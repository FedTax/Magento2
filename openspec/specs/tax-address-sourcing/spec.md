## Purpose

Defines which address a sale is sourced to — the destination the module sends TaxCloud when it prices a cart and when it files an order — so that a sale is always quoted and filed against one address that actually exists, whatever the cart is made of.

## Requirements

### Requirement: A sale is sourced to its delivery address when one is known

The destination reported to TaxCloud for a cart SHALL be the address Magento assigned the cart's items to. A cart containing any shippable line SHALL be sourced to the shipping address, and every line in it SHALL be sourced there — a non-shippable line in such a cart SHALL NOT be split out, given a destination of its own, or reported as a separate sale.

Where a delivery address is known, no other address SHALL displace it. In particular the store's native "Tax Calculation Based On" preference SHALL NOT redirect sourcing to the billing address.

#### Scenario: Physical cart sources to the shipping address
- **WHEN** a cart of shippable items is priced with a shipping address that differs from the billing address
- **THEN** exactly one lookup is performed, and its destination is the shipping address

#### Scenario: A digital line rides along with the shipment
- **WHEN** a cart holds both a shippable item and a virtual, downloadable or virtual gift-card item
- **THEN** exactly one lookup is performed, its destination is the shipping address, and both lines appear in it

#### Scenario: The native billing preference does not redirect a known delivery address
- **WHEN** the store's "Tax Calculation Based On" setting is "Billing Address" and a cart of shippable items is priced with a shipping address
- **THEN** the destination is still the shipping address

### Requirement: A sale with no delivery address is sourced to the billing address

A cart made entirely of non-shippable items has nowhere to be delivered, and Magento assigns such a cart's items to the billing address. Its destination SHALL be that billing address, and the sale SHALL be priced — a cart the module can identify the buyer's address for SHALL NOT be reported as untaxed.

This SHALL hold whether or not a shipping address happens to exist on the quote: the presence of a stale or unused shipping address SHALL NOT displace the billing address for a cart that ships nothing.

#### Scenario: Digital-only cart is priced against the billing address
- **WHEN** a cart of only virtual, downloadable or virtual gift-card items is priced, with a billing address in one state and no shipping address
- **THEN** one lookup is performed with the billing address as its destination, and the resulting tax is applied to the cart

#### Scenario: An unused shipping address does not displace the billing address
- **WHEN** a cart of only non-shippable items is priced on a quote that also carries a shipping address in a different state
- **THEN** the destination is the billing address, not the shipping address

### Requirement: An order's destination falls back to its billing address

Every order-side operation that reports a destination to TaxCloud — filing the order at capture, re-creating it as exempt, refunding it, verifying its address, and choosing the state an exemption certificate must cover — SHALL resolve that destination from the order's shipping address when the order has one and from the order's billing address when it does not.

A Magento order made only of non-shippable items has no shipping address at all. Such an order SHALL therefore be filed, refunded and reversed exactly as any other order is; the absence of a shipping address SHALL NOT be treated as an absence of a destination.

Resolution SHALL fail, and the operation SHALL report failure rather than substitute an invented address, only when neither address yields a usable United States destination — no address at all, a non-US country, or an unparseable postal code.

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

#### Scenario: No usable address fails loudly
- **WHEN** an order has neither a shipping nor a billing address that yields a valid US destination
- **THEN** no destination is produced, the operation reports failure, and the reason is logged

### Requirement: An order is filed against the address it was quoted against

The address an order's destination is resolved from when it is filed SHALL be the same address the destination was resolved from when its cart was priced. A sale SHALL NOT be quoted against one address and filed against another, because the tax the customer paid would then not match the tax the filed order accounts for.

This is a requirement about which address, not about byte-identical payloads: where address verification is enabled it normalises the copy sent with a lookup (case, ZIP+4) and not the copy sent when the order is filed, and the two SHALL still be understood as the same address.

#### Scenario: Digital-only sale agrees end to end
- **WHEN** a digital-only cart is priced and the resulting order is captured
- **THEN** both the lookup and the capture are sourced to the billing address

#### Scenario: Mixed sale agrees end to end
- **WHEN** a cart holding both shippable and non-shippable lines is priced and the resulting order is captured
- **THEN** both the lookup and the capture are sourced to the shipping address

### Requirement: The log names which address a destination came from

The store's TaxCloud log SHALL record, for each order-side destination it resolves, whether that destination came from the order's shipping address or from its billing address, and SHALL record when neither could be used.

Without this, a digital order correctly sourced to a billing address and a physical order wrongly sourced to one are indistinguishable to whoever reads the log.

#### Scenario: A billing fallback is visible in the log
- **WHEN** an order with no shipping address resolves its destination
- **THEN** the log records that the billing address was used, and identifies the order

#### Scenario: An unusable address is visible in the log
- **WHEN** neither of an order's addresses yields a valid US destination
- **THEN** the log records that no destination could be resolved, and identifies the order
