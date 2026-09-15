## ADDED Requirements

### Requirement: TaxCloud log records carry an operation correlation context
While a TaxCloud operation — tax lookup, address verification, capture, refund, cancellation or order details — is running, every TaxCloud log record SHALL carry `correlation_id` and `operation`, and `quote_id` and `order_increment_id` when known, in its context array and rendered in the formatted line as machine-parseable JSON.

#### Scenario: Capture of an order
- **WHEN** an order is captured
- **THEN** the capture's log lines include `"order_increment_id":"<increment id>"` and share one `correlation_id`

### Requirement: Context never leaks between operations
Starting an operation SHALL replace the bound context, except that starting an operation for the same order (or, with no order, the same quote) SHALL keep its correlation id. Log calls with no context bound SHALL be written unchanged.

#### Scenario: Consecutive orders in one process
- **WHEN** a cron run captures two orders in sequence
- **THEN** no log line of the second capture carries the first order's increment id or correlation id
