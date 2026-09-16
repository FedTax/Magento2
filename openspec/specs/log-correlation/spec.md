## Purpose

Makes the TaxCloud log traceable: every record can be tied to the operation, cart, order and request that produced it, and reads as one greppable, parseable line naming the API used, so an order's history can be followed and extracted mechanically.

## Requirements

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

### Requirement: Every log record identifies the request that wrote it
Every TaxCloud log record SHALL carry, in its extra data, a `request` identifier fixed for the request, command or background process that wrote it, and the PHP process id where the host permits reading it. When the process id or a random source is unavailable, the record SHALL still be written, omitting only what could not be determined.

#### Scenario: Concurrent requests
- **WHEN** a checkout and an admin page write TaxCloud log records at the same time
- **THEN** each record names its own request, so the two requests' records can be separated

#### Scenario: Host disables getmypid
- **WHEN** `getmypid` is listed in `disable_functions`
- **THEN** log records are written with `request` and without `pid`, and no error is raised

### Requirement: Log records are single-line and name their API
Each TaxCloud log record SHALL occupy one line, so a line-oriented search for an order number finds every record carrying it: request and response payloads of both APIs SHALL be written as single-line JSON and wire traces collapsed onto one line. Operation records SHALL name the API used, `(v1 SOAP)` or `(v3 REST)`.

#### Scenario: SOAP lookup in Advanced logging
- **WHEN** a tax lookup runs over V1 SOAP with Advanced logging
- **THEN** its parameters are logged as one line of JSON carrying the correlation context, with credentials redacted, and its call is labelled `(v1 SOAP)`

### Requirement: Capture and refund outcomes are logged
Every capture and refund attempt SHALL end with an info record stating that the order was captured, or the refund recorded, in TaxCloud, or a warning stating that it was not, naming the order increment id.

#### Scenario: Failed capture
- **WHEN** TaxCloud rejects a capture
- **THEN** the log records the rejection and a warning that the order was not captured in TaxCloud

