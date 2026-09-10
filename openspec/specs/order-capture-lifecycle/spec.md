## Purpose

Governs when a Magento order's sale is recorded in TaxCloud and under what date — the event that triggers capture, the whole-order rule, how a repeat attempt is suppressed, how a failed attempt is retried, and the date the sale is filed under. These rules are transport-neutral: they hold identically whether the store transacts over V1 SOAP or v3 REST.

## Requirements

### Requirement: Capture happens once per order, at the store's configured trigger

An order SHALL be recorded in TaxCloud exactly once, when the lifecycle event matching the store's capture trigger first occurs: order placement, invoice payment, or shipment. Later events of the same kind SHALL NOT record the order again.

Every gate governing this decision — whether TaxCloud is enabled, which trigger is configured, and whether the store is calculations-only — SHALL resolve against the store of the order being processed, never the ambient store of the request, because invoices and shipments are routinely created from admin, cron and webhook contexts whose ambient store is the default store view.

A calculations-only store SHALL NOT record the sale in TaxCloud at all, and such an order SHALL be left with no recorded capture, so that later reversal operations correctly treat it as never captured.

#### Scenario: The configured trigger fires capture
- **WHEN** the lifecycle event matching the order's store capture trigger occurs for the first time on an enabled, non-calculations-only store
- **THEN** the order is recorded in TaxCloud as a completed sale, and the order records that it was captured

#### Scenario: Non-matching events do not capture
- **WHEN** a lifecycle event occurs that does not match the store's configured capture trigger
- **THEN** no TaxCloud call is made

#### Scenario: Gates resolve against the order's store
- **WHEN** an order belonging to a store that disables TaxCloud, selects a different capture trigger, or is calculations-only is processed while the ambient store is a different one
- **THEN** the order's own store settings decide the outcome, and the default store view's settings are never substituted

### Requirement: Capture is whole-order

Capture SHALL record the entire order as a single sale. Partial capture SHALL NOT be supported in any form: an order fulfilled across several invoices or shipments SHALL be recorded once, in full, and SHALL NOT be split into per-document sales in TaxCloud.

This holds regardless of transport. Neither the V1 SOAP nor the v3 REST capture operation carries items or amounts that could express a partial capture, and the capture trigger setting determines *when* the whole order is recorded, never *how much* of it is.

#### Scenario: A partially fulfilled order files in full
- **WHEN** an order is invoiced or shipped in parts and the store's trigger is invoice payment or shipment
- **THEN** the whole order is recorded in TaxCloud at the first such document, and no further sale is recorded when the remaining parts are invoiced or shipped

### Requirement: A recorded capture is never recorded twice

Once an order has been successfully recorded in TaxCloud, subsequent trigger events for that order SHALL NOT issue another capture call. Suppression SHALL be driven by the capture state recorded on the order, and SHALL NOT depend on counting the order's invoices or shipments, because the lifecycle events that trigger capture are dispatched at different points relative to their document being persisted and a count is therefore not a reliable indicator of which document is the first.

As a second line of defence, a capture call for an order that TaxCloud already holds SHALL be treated as success rather than as an error, so that a capture whose recorded state was lost cannot produce a duplicate sale.

#### Scenario: Later fulfillment documents do not re-capture
- **WHEN** a second or subsequent invoice or shipment is created for an order already recorded in TaxCloud
- **THEN** no capture call is made, and the reason is logged

#### Scenario: A repeat capture call is benign
- **WHEN** a capture call is nevertheless issued for an order TaxCloud already holds
- **THEN** the operation reports success and the existing TaxCloud order is left unaltered

### Requirement: A failed capture is retried at the next fulfillment document

When a capture attempt fails, the order SHALL NOT be marked as captured, and the next lifecycle event matching the store's capture trigger SHALL attempt the capture again. This SHALL behave identically for the invoice-payment and shipment triggers.

Retry is bounded by the order's remaining fulfillment documents; there is no background retry. An order whose only fulfillment document fails to capture therefore remains unrecorded, and each failed attempt SHALL be logged with enough detail to diagnose it.

Each retry SHALL re-resolve the store-scoped configuration at the time it runs, rather than reusing the resolution from the failed attempt, so that an order whose store changes its API type or capture trigger between two fulfillment documents is captured according to what is configured when the retry occurs.

#### Scenario: Failure on the first shipment retries on the second
- **WHEN** capture fails at an order's first shipment and a second shipment is later created
- **THEN** capture is attempted again for that order, and on success the order is recorded and marked captured

#### Scenario: Failure on the first invoice retries on the second
- **WHEN** capture fails at an order's first invoice payment and a second invoice is later paid
- **THEN** capture is attempted again for that order, with the same outcome as the shipment path

#### Scenario: Persistent failure is visible
- **WHEN** capture fails for every fulfillment document of an order
- **THEN** each attempt is logged as a failure, and the order is never marked as captured

### Requirement: The sale is filed under the triggering document's date

The completion date recorded in TaxCloud SHALL be the creation time of the document that triggered the capture: the order for the order-placement trigger, the invoice for the invoice-payment trigger, and the shipment for the shipment trigger. It SHALL NOT be the wall-clock time at which the capture call happens to be issued.

Where the transport also carries a separate transaction or authorization date, that date SHALL remain the order's placement time.

When no triggering document time is available, the current time SHALL be used as a fallback, so a capture is never filed without a completion date.

#### Scenario: Capture on shipment files under the shipment's date
- **WHEN** an order is captured on the shipment trigger
- **THEN** the sale is filed in TaxCloud with the shipment's creation time as its completion date, and the order's placement time as its transaction date

#### Scenario: A retried capture files under its own document's date
- **WHEN** capture fails at one fulfillment document and succeeds at a later one created in a subsequent filing period
- **THEN** the sale is filed under the later document's creation time, not under the time of the failed attempt or of the original order

#### Scenario: Missing document time falls back to now
- **WHEN** capture runs without an available triggering document time
- **THEN** the sale is filed with the current time as its completion date rather than being rejected
