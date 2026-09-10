## 1. Gateway contract

- [x] 1.1 Add the optional `$completedAt` parameter to `Api/OrderGatewayInterface::authorizeCapture()`, documenting it as a Magento datetime string (UTC) that falls back to the current time when null (design D2, D3)
- [x] 1.2 Thread the parameter through `Model/Gateway/Router::authorizeCapture()` without altering its store-resolution behavior
- [x] 1.3 Update `Test/Unit/Model/Gateway/RouterTest.php` to pin that the completion time is forwarded unchanged to the selected transport

## 2. Transport payloads

- [x] 2.1 `Model/Gateway/Rest/RestRequestBuilder::buildOrderPayload()` — accept the completion time and use it for `completedDate`, keeping `transactionDate` as the order's placement time and preserving the existing now-fallback in `toRfc3339()`
- [x] 2.2 `Model/Gateway/Rest/RestGateway::authorizeCapture()` — accept the parameter and pass it to the builder; confirm the exempt re-create path in `returnOrder()` still supplies its own (unchanged) behavior
- [x] 2.3 `Model/Gateway/RequestBuilder::buildAuthorizeCaptureParams()` — derive `dateAuthorized` and `dateCaptured` from the completion time via `gmdate('c', strtotime($completedAt . ' UTC'))`, keeping the offset-bearing format and falling back to `date('c')` when null (design D3, D5)
- [x] 2.4 `Model/Api::authorizeCapture()` — accept the parameter and pass it to the builder; leave `authorizeCaptureWithCartId()` (the exempt re-create path) on its current behavior

## 3. Capture observer

- [x] 3.1 Remove the invoice `getInvoiceCollection()->getSize() > 1` and shipment `getShipmentsCollection()->getSize() > 1` guards from `Observer/Sales/Complete::getOrderFromObserver()`, reducing it to plain order resolution (design D1)
- [x] 3.2 Add a sibling method resolving the triggering document's `created_at` — order, invoice or shipment per event — returning null when unavailable (design D4)
- [x] 3.3 Pass the resolved time to `authorizeCapture()`, leaving the existing gate order intact: order resolved first, then logger store binding, then enabled / capture-trigger / calculations-only against the order's store, then the `taxcloud_captured` check
- [x] 3.4 Rewrite the stale comment block explaining the count-based dedupe so it describes the flag-only rule and why counts were unreliable

## 4. Specs and documentation

- [x] 4.1 Confirm `openspec validate capture-parity-first-fulfillment --strict` passes after any spec edits made during implementation
- [x] 4.2 Check whether `docs/` describes the capture trigger or partial-fulfillment behavior and update it to state the whole-order rule if so
- [x] 4.3 Add a CHANGELOG entry under Fixed covering the lost-capture-on-retry fix and the filing-date correction

## 5. Unit tests

- [x] 5.1 `Test/Unit/Observer/Sales/CompleteTest.php` — capture retried on the second shipment after a failed first shipment, and on the second invoice after a failed first invoice (the two paths that behave differently today)
- [x] 5.2 `CompleteTest.php` — no capture call on later fulfillment documents once `taxcloud_captured` is set, for both triggers
- [x] 5.3 `CompleteTest.php` — the completion time passed to the gateway is the order's, invoice's, and shipment's `created_at` for the order-creation, payment and shipment triggers respectively, and null when the document carries no timestamp
- [x] 5.4 `CompleteTest.php` — re-pin the store-scope, enabled and calculations-only gates against the order's store with a different ambient store, and that a calculations-only order is left with no recorded capture
- [x] 5.5 `Test/Unit/Model/Gateway/Rest/RestRequestBuilderTest.php` (or the existing REST builder test) — `completedDate` reflects a supplied time, falls back to now when null, and `transactionDate` stays the order's placement time in both cases
- [x] 5.6 `Test/Unit/Model/Gateway/RequestBuilderTest.php` — `dateAuthorized`/`dateCaptured` reflect a supplied time and fall back to now when null, in the format the SOAP API is sent today
- [x] 5.7 Review every added test against PHPUnit 9.5, 10.5 and 12.5 (no version-specific assertion or mock APIs), then run `make test-unit-version` across the matrix

## 6. Verification

- [x] 6.1 `make test-unit` green
- [x] 6.2 `make lint` and `make phpstan` clean, with no new baseline entries
- [x] 6.3 Propose integration and e2e coverage to the maintainer as a separate decision — the e2e suite already exercises capture, so assess whether tightening its assertions (filed completion date, retry after an induced failure) is enough before adding new cases

## 7. Integration coverage (approved 6.3 follow-up)

- [x] 7.1 Add `createPartialShipment()` to `Test/Integration/IntegrationTestCase.php` — ship part of an order so a second shipment event is reachable
- [x] 7.2 `CaptureOnShipmentTest` — a capture failing on the first partial shipment is retried on the second, ending flagged and filed exactly once
- [x] 7.3 `CaptureOnShipmentTest` — the retry stops once the order is captured: a third shipment does not file again
- [ ] 7.4 Run `make integration-test` green — NOT RUN LOCALLY. Deferred to CI on the PR by maintainer decision; the stack reprovision did not finish in-session, so the two new integration cases in `CaptureOnShipmentTest` have never been executed.
- [x] 7.5 No new e2e cases — the suite touches capture only while setting up refunds, and the date/retry assertions are cheaper and stricter at the unit and integration levels
