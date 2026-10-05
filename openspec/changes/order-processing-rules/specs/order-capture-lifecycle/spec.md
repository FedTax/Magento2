## MODIFIED Requirements

### Requirement: Capture happens once per order, at the store's configured trigger

An order SHALL be recorded in TaxCloud exactly once, when the lifecycle event matching the store's capture trigger first occurs: order placement, invoice payment, or shipment. Later events of the same kind SHALL NOT record the order again.

Every gate governing this decision — whether TaxCloud is enabled, which trigger is configured, and whether the order is to be reported — SHALL resolve against the order being processed, never the ambient store of the request, because invoices and shipments are routinely created from admin, cron and webhook contexts whose ambient store is the default store view.

Whether the order is to be reported SHALL be decided by the processing outcome stored on the order: only an order whose outcome is *Report* SHALL be recorded. An order with no stored outcome, placed before order processing rules existed, SHALL be recorded only when its store view's *Report orders to TaxCloud* setting is *Yes*. An order that is not to be reported SHALL NOT be recorded in TaxCloud at all, and SHALL be left with no recorded capture, so that later reversal operations correctly treat it as never captured.

#### Scenario: The configured trigger fires capture
- **WHEN** the lifecycle event matching the order's store capture trigger occurs for the first time on an enabled store, for an order whose stored outcome is *Report*
- **THEN** the order is recorded in TaxCloud as a completed sale, and the order records that it was captured

#### Scenario: Non-matching events do not capture
- **WHEN** a lifecycle event occurs that does not match the store's configured capture trigger
- **THEN** no TaxCloud call is made

#### Scenario: A non-reported outcome is never captured
- **WHEN** the capture trigger fires for an order whose stored outcome is *Calculate only* or *Skip TaxCloud*
- **THEN** no TaxCloud call is made, the reason is logged, and the order records no capture

#### Scenario: A legacy order follows its store's setting
- **WHEN** the capture trigger fires for an order with no stored outcome on a store view whose *Report orders to TaxCloud* setting is *No*
- **THEN** no TaxCloud call is made and the order records no capture

#### Scenario: Gates resolve against the order's store
- **WHEN** an order belonging to a store that disables TaxCloud, selects a different capture trigger, or does not report orders is processed while the ambient store is a different one
- **THEN** the order's own store settings and stored outcome decide the result, and the default store view's settings are never substituted
