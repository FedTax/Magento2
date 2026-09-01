## Why

An order that is invoiced or shipped in parts is captured in TaxCloud exactly once, at the first fulfillment document. That is the intended behavior and matches how TaxCloud's own Shopify integration files (earliest fulfillment timestamp, whole order), so no change to *what* gets filed is wanted. But two details of *how* it is filed diverge from that model:

- **A failed capture is handled inconsistently, and on the shipment path it is lost.** The dedupe is split between a document-count guard and the `taxcloud_captured` flag. If capture fails at the first shipment, the count guard suppresses every later shipment and the order is never filed at all. On the invoice path the same guard lags by one document, so a failure gets exactly one accidental retry. Neither behavior was designed; both fall out of two event types with different persistence timing being guarded the same way. There is no cron or CLI to re-send a capture, so a lost capture stays lost.
- **The filing date is the observer's wall clock, not the fulfillment's.** Today those are the same instant, so this is currently invisible. It stops being invisible the moment a capture is retried: a retry a week later would file the sale into the wrong period — the same period-attribution problem that prompted the review, arriving by a different route.

Writing the Shopify-parity decision into the specs at the same time gives the recurring "should we support partial capture?" question a documented answer instead of one that lives in chat history.

## What Changes

- **`taxcloud_captured` becomes the sole capture dedupe.** Both document-count guards in the capture observer are removed. A successful capture still suppresses every later fulfillment document; a *failed* capture is now retried on the next one, uniformly across the payment and shipment triggers. A duplicate `orderId` is already treated as benign success on both transports, so a lost flag write cannot cause a double filing.
- **Capture is filed under the triggering document's own timestamp.** The capture call accepts an optional completion time, supplied by the observer from the entity that triggered it — the order for the order-creation trigger, the invoice for payment, the shipment for shipment — falling back to now when omitted. This applies to both transports: v3 REST `completedDate` and V1 SOAP `dateAuthorized`/`dateCaptured` are all currently stamped with the wall clock at call time.
- **The whole-order rule is stated positively in the specs**, transport-neutrally, with the Shopify-parity rationale: whole order, at the first fulfillment event, filed under that event's date, retried until it lands.

Not a breaking change. The gateway interface gains an optional parameter, so existing callers are unaffected; the interface is not marked `@api` and every implementer is in-repo.

## Capabilities

### New Capabilities

- `order-capture-lifecycle`: The transport-neutral rules governing when an order is captured in TaxCloud and under what date — the capture trigger, the whole-order rule, the dedupe-and-retry contract, and the timestamp the sale is filed under. This behavior is shared by both transports and is currently specified only incidentally, inside the REST capability; it needs a home of its own so the SOAP path is covered by the same requirements.

### Modified Capabilities

- `rest-tax-operations`: The order-capture requirement changes on two points — `completedDate` is taken from the triggering fulfillment document rather than set at capture time, and the whole-order/no-partial-capture statement is re-pointed at `order-capture-lifecycle` as the transport-neutral owner of that rule rather than restating it.

## Non-goals

- **Partial capture, in any form.** Not splitting an order across invoices or shipments, not per-document sub-orders, not `orderId` suffixes. Neither transport can express a partial capture, and the split-order workaround the WooCommerce plugin uses for shipping packages is deliberately not adopted here.
- **No change to refunds, cancellation reversal, or order details.** `orderId = incrementId` stays exactly as load-bearing as it is now; nothing downstream of capture is touched.
- **No background retry.** Retry happens only when a later fulfillment document arrives. An order with a single failed shipment and no further documents is still not filed — closing that gap needs a cron or CLI and is a separate decision.
- **No change to the capture trigger setting** or to which event each option maps to.

## Impact

**Affected code** (all in `app/code/Taxcloud/Magento2`):

- `Observer/Sales/Complete.php` — remove both count guards; carry the triggering invoice/shipment through to the capture call instead of discarding it.
- `Api/OrderGatewayInterface.php` — `authorizeCapture()` gains an optional completion-time parameter.
- `Model/Gateway/Router.php` — pass it through.
- `Model/Api.php` and `Model/Gateway/RequestBuilder.php` — SOAP: `dateAuthorized`/`dateCaptured` from the supplied time.
- `Model/Gateway/Rest/RestGateway.php` and `Model/Gateway/Rest/RestRequestBuilder.php` — REST: `completedDate` from the supplied time. `transactionDate` stays the order's placement time.

**Store scoping:** no new configuration reads are introduced, and the change must not move any existing one. The observer resolves `enabled`, `capture_trigger` and calculations-only against the *order's* store before any capture decision, and binds the gateway logger to that store — removing the count guards must leave that ordering intact. Because a retry now runs on a later event, each retry re-resolves store-scoped config from scratch rather than reusing the first event's resolution; a store that changes its API type or capture trigger between two fulfillment documents is handled by whatever is configured at the time of the retry. Unit coverage re-pins the store-scope and calculations-only gates so their behavior is demonstrably unchanged.

**Testing:** unit tests are in scope — observer retry-after-failure on both trigger paths, skip-after-success, and the timestamp source per trigger; builder tests on both transports for the supplied-date and now-fallback cases. All added test code must be compatible with PHPUnit 9.5, 10.5 and 12.5. Integration and e2e coverage is deliberately left out of this change: the e2e suite already exercises capture and may only need tightened assertions, which is proposed separately.

**Risk:** a persistently failing capture now produces one failed API call per fulfillment document instead of one per order. Bounded by the document count, visible in the log, and preferable to silently never filing.
