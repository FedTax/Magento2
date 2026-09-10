## ADDED Requirements

### Requirement: Composite products report the lines that carry their price

A composite product (bundle, configurable, grouped) SHALL be reported to TaxCloud as the lines that carry its taxable basis, and as those lines only.

Where the selections carry the price — a dynamic-price bundle, which Magento marks `CALCULATE_CHILD` — the selections SHALL be reported and the wrapper SHALL NOT, because Magento excludes such a wrapper from its own address totals and reporting both would state the same goods twice. Where the parent carries the price — a configurable, or a fixed-price bundle — the parent SHALL be reported and its zero-priced children SHALL NOT. A grouped product SHALL be reported as its associated lines, which Magento already places in the quote as independent items.

This rule SHALL be applied identically wherever a payload's line list is built, so that no payload for an order describes goods another payload does not.

#### Scenario: Dynamic-price bundle reports its selections
- **WHEN** a cart containing a dynamic-price bundle is looked up
- **THEN** each selection is reported as its own line at its own price, and the bundle wrapper is not reported

#### Scenario: Fixed-price bundle reports its parent
- **WHEN** a cart containing a fixed-price bundle is looked up
- **THEN** the bundle is reported as a single line at the parent's price, and its selections are not reported

#### Scenario: Configurable reports the chosen variant once
- **WHEN** a cart containing a configurable product is looked up
- **THEN** exactly one line is reported, carrying the chosen variant's SKU and TIC, and the zero-priced child row is not reported

#### Scenario: Grouped product reports independent lines
- **WHEN** a cart containing a grouped product is looked up
- **THEN** each associated product is reported as its own line at its own quantity, and no line represents the group itself

#### Scenario: The reported basis equals the taxable subtotal
- **WHEN** any cart is looked up
- **THEN** the sum of price multiplied by quantity across the reported product lines equals the taxable subtotal Magento calculated for that cart

### Requirement: Quote-side composite quantities are resolved against the parent

A quote item's reported quantity SHALL be the quantity its row total was built from. For a child of a children-priced composite, Magento stores the quantity **per parent** while already multiplying the row total by the parent's quantity, so the reported quantity SHALL be the stored quantity multiplied by the parent's — the same reconciliation `TaxCalculation::getTotalQuantity()` performs.

An order or credit memo item's quantity SHALL be used as stored, because conversion to an order has already applied that multiplication. Applying it on both sides, or on neither, SHALL be treated as a defect.

Any per-unit value derived from a line — an apportioned discount, a tax-inclusive unit price — SHALL be derived from that same resolved quantity.

#### Scenario: A bundle selection is reported at its effective quantity
- **WHEN** a bundle holding one unit of a selection is added at quantity 3
- **THEN** that selection is reported at quantity 3, not the quantity 1 stored against the quote item

#### Scenario: A multi-unit selection multiplies
- **WHEN** a bundle holding two units of a selection is added at quantity 3
- **THEN** that selection is reported at quantity 6

#### Scenario: Order-side quantities are not multiplied again
- **WHEN** the same bundle order is described from its order items rather than its quote
- **THEN** the selections are reported at the same quantities as the lookup reported, taken from the stored order quantities without further multiplication

#### Scenario: A discount is apportioned over the effective quantity
- **WHEN** a bundle selection line carries a row-level discount
- **THEN** the per-unit discount subtracted from its reported price is the row discount divided by the effective quantity

#### Scenario: A zero-quantity line does not fail
- **WHEN** a line's effective quantity is zero
- **THEN** the line is reported without a division error and bears no tax

### Requirement: Lookup, capture and refund describe the same order

The payloads built for one order SHALL name the same items: the lines reported at lookup, the lines filed at capture, and the lines referenced by a refund or cancellation. Each is built from a different source — the quote, the order's visible items, the credit memo's items — and each stores composites differently, so agreement SHALL be treated as a property to verify rather than an assumption.

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

### Requirement: A wrapper line's displayed tax is its selections' tax

A children-priced composite's wrapper is never reported to TaxCloud and therefore has no tax of its own. Its displayed tax amount SHALL be the sum of its selections' tax, so an order's line items add up to the tax the order charged, and it SHALL be excluded from the address tax total — matching Magento's own exclusion of children-calculated parents.

#### Scenario: The wrapper displays the sum of its selections
- **WHEN** tax is applied to a cart containing a dynamic-price bundle
- **THEN** the bundle line displays the total tax of its selections

#### Scenario: An order's tax equals the tax of its lines
- **WHEN** an order containing any composite product is placed
- **THEN** the order's tax amount equals the sum of its line item tax, counting a children-priced wrapper's tax only once, plus shipping tax

### Requirement: The native tax fallback applies the same basis

When a lookup cannot be completed and the store has opted into falling back to Magento's own tax engine, the quote details built for that engine SHALL apply the same composite rules: a children-priced wrapper SHALL be omitted and child quantities SHALL be resolved against the parent. The fallback builds a flat list carrying no parent references, so Magento's own quantity reconciliation cannot apply on its behalf.

#### Scenario: Fallback prices a bundle on the same basis
- **WHEN** a cart containing a dynamic-price bundle is priced by the fallback
- **THEN** each selection is priced at its parent-multiplied quantity and the wrapper contributes nothing
