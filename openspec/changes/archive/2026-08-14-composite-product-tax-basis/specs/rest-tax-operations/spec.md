## MODIFIED Requirements

### Requirement: Order capture creates a v3 order directly

For a REST-selected store, capturing an order SHALL create a v3 order on the store's connection directly from the Magento order data — line items with their store-resolved TICs, effective prices, quantities, and each line's tax amount and rate as recorded on the Magento order — with the transaction date taken from the order's placement time and the completed date set at capture time.

Composite products SHALL file as the lines that carry their taxable basis, which is the same set of lines the cart was filed under (see the `composite-product-tax` capability): a parent-priced composite (configurable, fixed-price bundle) files its parent only, and its zero-priced child rows SHALL never file as additional lines — including when capture runs before the order is persisted and child rows are not yet linked to their parent by ID; a children-priced composite (dynamic-price bundle) files its selections, and the wrapper SHALL NOT file as a line. Because a v3 refund references an order's item identifiers by name, an order filed under identifiers its cart did not use cannot be cleanly refunded.

Capture is whole-order: the operation SHALL NOT support partial capture. The result SHALL be reported as success only when the order is created or already exists in TaxCloud.

#### Scenario: Successful capture
- **WHEN** an order on a REST-selected store is captured per the store's capture trigger
- **THEN** a v3 order is created carrying the order's stored per-line tax, marked completed, and the operation reports success

#### Scenario: Composite product files a single line
- **WHEN** an order containing a configurable (or fixed-price bundle) product is captured at order placement, before the order has been persisted
- **THEN** the v3 order carries exactly one line for the composite item — the priced parent — and no zero-priced child line, so later refunds by item reference are unambiguous

#### Scenario: A dynamic-price bundle files its selections
- **WHEN** an order containing a dynamic-price bundle is captured
- **THEN** the v3 order carries one line per selection, at the quantities the order recorded, and no line for the bundle wrapper — the same item identifiers the cart was filed under

#### Scenario: Filed tax matches the tax charged
- **WHEN** an order containing any composite product is captured
- **THEN** the tax filed across the v3 order's lines equals the tax the Magento order charged, with a composite wrapper's tax never counted alongside its selections'

#### Scenario: Duplicate capture is benign
- **WHEN** capture is invoked for an order that was already captured (the v3 API reports the order identifier already exists)
- **THEN** the operation reports success without altering the existing TaxCloud order

#### Scenario: Failed capture reports failure
- **WHEN** the v3 order creation fails for any other reason
- **THEN** the operation reports failure and the error is logged with enough detail to diagnose (status, response detail), with credentials never logged
