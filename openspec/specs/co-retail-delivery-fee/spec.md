## Purpose

Collects the Colorado Retail Delivery Fee on eligible orders as a module-priced, zero-rated TIC 11098 line item carried through TaxCloud for filing: eligibility, presentation, persistence through the order lifecycle, capture, and refund reversal.

## Requirements

### Requirement: Fee is charged only on eligible quotes

The Colorado Retail Delivery Fee SHALL be charged on a quote only when all of the following hold: the feature is enabled for the quote's store; the shipping destination region is Colorado (US-CO); the quote contains at least one taxable tangible item (physical, non-zero tax class); and the selected shipping method is one the merchant has configured as a motor-vehicle delivery method. Eligibility SHALL be re-evaluated on every totals collection pass so the fee appears and disappears as the cart, destination, or shipping method changes. Every gate SHALL resolve against the quote's store, never the ambient store.

#### Scenario: Eligible quote is charged the fee
- **WHEN** totals are collected for a quote on an enabled store, shipping taxable tangible goods to a Colorado address via a configured motor-vehicle method
- **THEN** the quote's totals include the Colorado Retail Delivery Fee exactly once, at the configured amount

#### Scenario: Non-Colorado destination is not charged
- **WHEN** totals are collected for an otherwise identical quote shipping to a non-Colorado address
- **THEN** no fee is charged and no fee total segment is present

#### Scenario: No taxable tangible items means no fee
- **WHEN** a quote shipping to Colorado contains only virtual/downloadable items or only items with a non-taxable tax class
- **THEN** no fee is charged

#### Scenario: Unmapped shipping method does not trigger the fee
- **WHEN** a Colorado quote with taxable tangible goods selects a shipping method not present in the motor-vehicle method configuration (e.g. in-store pickup)
- **THEN** no fee is charged

#### Scenario: Eligibility follows cart changes
- **WHEN** a quote that was charged the fee changes so that any condition no longer holds (destination leaves Colorado, last taxable tangible item removed, shipping method changed to an unmapped one)
- **THEN** the next totals collection removes the fee

#### Scenario: Gates resolve against the quote's store
- **WHEN** a quote belonging to a store where the feature is disabled is processed while the ambient store has it enabled
- **THEN** no fee is charged

#### Scenario: Customer exemption certificates do not exempt the fee
- **WHEN** an otherwise eligible quote belongs to a customer holding a validated exemption certificate for Colorado
- **THEN** the fee is still charged

### Requirement: Fee amount is merchant-configured, never TaxCloud-derived

The fee amount SHALL come from a store-scoped configuration value (default 0.31). The module SHALL NOT derive, override, or accept the amount from any TaxCloud response: TaxCloud zero-rates the fee line and echoes it back, so a returned amount of zero carries no eligibility or pricing information. Configuration SHALL reject amounts outside the range 0.00–2.00.

#### Scenario: Configured amount is charged
- **WHEN** the fee amount is configured as 0.31 for the quote's store and the quote is eligible
- **THEN** the fee charged, displayed, persisted, and sent to TaxCloud is exactly 0.31

#### Scenario: TaxCloud's echoed zero does not remove the fee
- **WHEN** the lookup response returns the fee line with zero tax and an unchanged price
- **THEN** the fee remains charged at the configured amount

#### Scenario: Out-of-range amount is rejected at save
- **WHEN** an administrator saves a fee amount above 2.00 or below 0.00
- **THEN** the configuration save fails with a validation error

### Requirement: Fee rides the lookup as a discrete zero-rated line on both transports

When a quote is eligible, the tax lookup request — on both the SOAP and REST transports — SHALL include one additional line item carrying a stable sentinel item id, the configured RDF TIC (default 11098), the configured fee amount as its price, and quantity 1, in the same lookup call as the rest of the cart. No additional TaxCloud call SHALL be introduced. The fee line SHALL always be sent at the full configured amount: discounts SHALL never be prorated onto or netted into it. When the quote is not eligible, no fee line SHALL be sent.

#### Scenario: Eligible lookup carries the fee line
- **WHEN** a tax lookup is performed for an eligible quote
- **THEN** the request contains exactly one fee line with the sentinel item id, the configured TIC, the configured amount, and quantity 1, alongside the product and shipping lines

#### Scenario: Discounts leave the fee line untouched
- **WHEN** a cart-rule discount applies to an eligible quote
- **THEN** product line prices reflect the discount but the fee line's price remains the full configured amount

#### Scenario: Ineligible lookup carries no fee line
- **WHEN** a tax lookup is performed for an ineligible quote on a store with the feature enabled
- **THEN** the request contains no line with the RDF TIC

### Requirement: Fee stays out of sales tax

Any tax returned by TaxCloud against the fee line SHALL be ignored and SHALL NOT be attributed to any product or shipping line. The fee SHALL NOT be included in the quote's or order's tax amount; it SHALL be its own total, distinct from tax, shipping, and product totals, and SHALL be included in the grand total and in authorized/captured payment amounts.

#### Scenario: Fee excluded from tax total
- **WHEN** totals are collected for an eligible quote
- **THEN** the tax total equals the tax on products and shipping only, and the grand total includes the fee as a separate addend

#### Scenario: Echoed fee line does not pollute item tax
- **WHEN** the lookup response includes the fee line among its response lines
- **THEN** no product or shipping line's tax amount is affected by it

### Requirement: Fee is presented as its own labeled total

When the fee applies, a total segment labeled "Colorado Retail Delivery Fee" SHALL appear on cart totals, checkout totals, the customer order view, the admin order view, order and invoice emails, and printed/PDF documents, showing the charged amount. When the fee does not apply, no such segment SHALL appear anywhere.

#### Scenario: Checkout shows the fee line
- **WHEN** an eligible quote reaches the cart or checkout totals
- **THEN** a "Colorado Retail Delivery Fee" line shows the configured amount

#### Scenario: No fee, no line
- **WHEN** the fee does not apply to a quote or order
- **THEN** no Colorado Retail Delivery Fee line is rendered on any document

### Requirement: Fee persists across the order lifecycle

The charged fee amount SHALL persist from quote to order, and onto each invoice and credit memo that settles it, in dedicated fields separate from tax, shipping, and product amounts, so every downstream document and report can retrieve it.

#### Scenario: Order carries the quote's fee
- **WHEN** an eligible quote is placed as an order
- **THEN** the order stores the fee amount charged on the quote, and its grand total includes it

#### Scenario: Invoice includes the fee
- **WHEN** an order with the fee is invoiced
- **THEN** the invoice includes the fee amount in its totals

### Requirement: Captured carts include the fee line so TaxCloud files it

When an order that was charged the fee is captured in TaxCloud — on either transport — the captured cart SHALL include the fee line (sentinel item id, RDF TIC, charged amount, quantity 1, zero tax). If an eligible order is placed without the fee having been recorded in a TaxCloud lookup (e.g. lookup failure with fallback to Magento rates), the condition SHALL be logged so the merchant can reconcile, and order placement SHALL NOT be blocked.

#### Scenario: Capture carries the fee line
- **WHEN** an order charged the fee is captured in TaxCloud
- **THEN** the capture payload contains the fee line with the charged amount and the RDF TIC

#### Scenario: Fallback orders are flagged, not blocked
- **WHEN** an eligible order is placed while the TaxCloud lookup is failing and Magento-rate fallback is active
- **THEN** the order is placed, the fee is still charged, and a log entry records that the fee was not priced through a TaxCloud lookup

### Requirement: Fee is refunded only on full returns

On a full return or cancellation of an order that was charged the fee, the fee SHALL be refundable to the customer and SHALL be reversed in TaxCloud so it is not remitted. On a partial return (some items returned, the delivery having occurred), the fee SHALL NOT be refunded and SHALL NOT be reversed in TaxCloud.

#### Scenario: Full return reverses the fee
- **WHEN** a credit memo returns all items of an order charged the fee
- **THEN** the credit memo includes the fee amount and the TaxCloud reversal includes the fee

#### Scenario: Partial return keeps the fee
- **WHEN** a credit memo returns a strict subset of an order's items
- **THEN** the credit memo excludes the fee amount and the TaxCloud reversal does not touch the fee

### Requirement: Colorado configuration group

The module's tax settings SHALL contain a dedicated group labeled "Colorado Retail Delivery Fee", store-scoped, with: an enable toggle defaulting to disabled; a multiselect of the store's shipping methods designating motor-vehicle delivery methods; the fee amount (default 0.31); and the RDF TIC (default 11098). The group SHALL describe the merchant's liability context (small-business exemption thresholds) and state that the amount changes annually and is not validated by TaxCloud. Enabling the feature is the merchant's assertion of liability; the module SHALL NOT attempt to determine it.

#### Scenario: Feature is off by default
- **WHEN** the module is installed or upgraded without touching the new settings
- **THEN** no fee is ever charged

#### Scenario: Settings resolve per store
- **WHEN** two store views configure different fee amounts or method mappings
- **THEN** each quote is charged according to its own store's values

### Requirement: RDF TIC cannot be applied to product or shipping configuration

The module SHALL reject saving its default product TIC or shipping TIC configuration with the value configured as the RDF TIC, because TaxCloud zero-rates any line carrying it and files the line's amount as fee revenue.

#### Scenario: Shipping TIC of 11098 is rejected
- **WHEN** an administrator attempts to save the shipping TIC setting as 11098
- **THEN** the save fails with a message explaining the TIC is reserved for the Colorado Retail Delivery Fee line
