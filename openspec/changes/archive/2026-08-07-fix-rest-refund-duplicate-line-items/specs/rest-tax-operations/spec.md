## MODIFIED Requirements

### Requirement: Credit memo refunds execute over the v3 refunds endpoint

For a REST-selected store, refunding from a credit memo SHALL submit a v3 refund against the captured order, expressing refunded product lines and shipping as item references with quantities (fractional where the refunded amount is not a whole multiple of the unit price). The submission SHALL carry at most one entry per item reference: credit memo rows sharing an item reference (a composite product's parent and child rows both carry the child's SKU) SHALL be combined into a single entry whose quantity preserves the total refunded amount against the filed order line, and rows for which nothing was charged SHALL be omitted. Prices SHALL NOT be sent; the v3 API derives amounts from the filed order. The SOAP path's special cases SHALL be preserved: adjustment-only credit memos distribute the adjustment across remaining items as fractional quantities, tax-only refunds fully refund the order and re-create it as exempt, and credit memos with nothing meaningful to refund succeed without an API call.

#### Scenario: Item and shipping refund
- **WHEN** a credit memo refunds specific items and shipping on a REST-selected store
- **THEN** the v3 refund carries each refunded item and the shipping line by item reference and refunded quantity, and reports success on a created refund

#### Scenario: Composite product rows are combined
- **WHEN** a credit memo for a configurable (or bundle) product carries both the parent row (priced) and its child row (zero-priced), both under the child's SKU
- **THEN** the v3 refund carries a single entry for that SKU whose quantity matches the parent row's refunded quantity — never a duplicate entry and never a doubled quantity

#### Scenario: Adjustment-only refund distributes as quantities
- **WHEN** a credit memo contains only an adjustment amount (no items, no shipping, not tax-only)
- **THEN** the adjustment is distributed proportionally across the order's remaining unrefunded lines and expressed as fractional quantities in the v3 refund

#### Scenario: Tax-only refund re-creates the order as exempt
- **WHEN** a credit memo refunds only the order's tax amount
- **THEN** the order is fully refunded in TaxCloud and re-created as a completed exempt v3 order (distinct order identifier), so nexus tracking is preserved without tax liability

#### Scenario: Refunds are not blindly retried
- **WHEN** a v3 refund request fails in a way that may have reached TaxCloud (e.g. timeout after send)
- **THEN** the request is not retried, so the refund cannot be booked twice
