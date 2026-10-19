## MODIFIED Requirements

### Requirement: Lookup, capture and refund describe the same order

The payloads built for one order SHALL name the same items: the lines reported at lookup, the lines filed at capture, and the lines referenced by a refund or cancellation. That includes a refund made by distributing an adjustment-only credit memo over the order's remaining lines. Each is built from a different source — the quote, the order's visible items, the credit memo's items — and each stores composites differently, so agreement SHALL be treated as a property to verify rather than an assumption.

A refund SHALL reference at most one entry per item reference, and SHALL reference only items the order filed.

#### Scenario: A refund names the goods the order filed
- **WHEN** an order containing any composite product is refunded in full
- **THEN** the refund references exactly the item references the order was filed under, at the quantities the order reported

#### Scenario: A cancellation reverses what was reported
- **WHEN** a captured order containing a composite product is cancelled
- **THEN** the reversal names the same items the lookup reported

#### Scenario: A partial refund returns proportional quantities
- **WHEN** half of a composite order is refunded
- **THEN** each referenced line comes back at half the quantity the order reported for it

#### Scenario: An adjustment-only refund distributes over the filed lines
- **WHEN** an adjustment-only credit memo is issued for an order containing a dynamic-price bundle
- **THEN** the adjustment is distributed over the bundle's selections, at their stored order quantities, and the bundle wrapper is not referenced
