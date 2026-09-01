## Context

See proposal.md — Why. Requirements are in `specs/order-capture-lifecycle/spec.md` and the `rest-tax-operations` delta.

The state that shapes the approach:

- `Observer\Sales\Complete` is bound to three events and decides, per event, whether this is the capture moment. Its skip logic is currently split in two places: a document-count guard inside `getOrderFromObserver` (invoice `getSize() > 1`, shipment `getSize() > 1`) and the `taxcloud_captured` order attribute checked in `execute()`.
- The two counts are not comparable. `sales_order_invoice_pay` is dispatched from `Invoice::register()` *before* the invoice row is written, so the collection lags by one and `> 1` first bites on invoice #3. `sales_order_shipment_save_after` fires *after* the write, so `> 1` bites on shipment #2. Same guard, two different meanings.
- `OrderGatewayInterface::authorizeCapture($order)` is implemented three times in-repo — `Model\Api` (SOAP), `Model\Gateway\Rest\RestGateway`, and `Model\Gateway\Router` (dispatcher). The interface carries no `@api` annotation.
- Both transports stamp the filing date at call time: REST `completedDate` via `toRfc3339(null)`, SOAP `dateAuthorized`/`dateCaptured` via bare `date('c')`.
- `RestRequestBuilder::toRfc3339()` already accepts a Magento datetime string, treats it as UTC, and falls back to now on null/empty — the shape `getCreatedAt()` returns.

## Goals / Non-Goals

**Goals:**

- One dedupe rule, driven by recorded capture state, with identical behavior on the invoice and shipment triggers.
- The filing date derived from the triggering document, on both transports, with a defined fallback.
- No change to the transport call shapes beyond the date field, and no change to the gateway interface for existing callers.

**Non-Goals:**

- See proposal.md — Non-goals. At design level, additionally: no new database column, no schema change, and no change to how `taxcloud_captured` is written or read by the cancellation flow.

## Decisions

### D1. Delete the count guards rather than fix their off-by-one

`taxcloud_captured` becomes the sole dedupe. The counts could be made consistent (compare against a per-event offset), but they would still be the wrong instrument: they answer "how many documents exist?" when the question is "has this order been filed?" — and only the flag answers that. The flag is already authoritative for the cancellation path, and a lost flag write is caught by the transport-level duplicate handling, which both gateways already map to success. Two unreliable guards collapse into one reliable one.

A consequence worth naming: `getOrderFromObserver` currently doubles as the skip mechanism. With the counts gone its only remaining null case is an unrecognized event, so it reduces to plain order resolution and the skip decision lives entirely in `execute()`. That is a simplification the change enables, not extra scope.

*Alternative considered:* keep the counts and correct the invoice offset. Rejected — it preserves two sources of truth and leaves the shipment path still discarding failed captures.

### D2. Pass the completion time as an optional argument on `authorizeCapture()`

Signature becomes `authorizeCapture($order, $completedAt = null)`, threaded through `Router` to both gateways. Untyped with a `@param string|null` docblock, matching the surrounding style in these classes.

*Alternative considered:* let each gateway re-derive the triggering document from the order (e.g. read the newest invoice or shipment). Rejected — the observer already knows which document fired, re-deriving it means guessing, and it would couple the transport layer to Magento's fulfillment model. *Also considered:* stashing the time on the order as transient data. Rejected as an invisible side channel.

Adding an optional parameter to a non-`@api` interface with three in-repo implementers is safe for callers. Note this is a *method* parameter, not a constructor one, so the object-manager rule about optional constructor arguments never being auto-wired does not apply — no di.xml work.

### D3. The argument is a Magento datetime string, not a `DateTimeInterface`

`getCreatedAt()` returns a UTC datetime string, and `toRfc3339()` already consumes exactly that and falls back to now. Passing the string straight through means no conversion at the observer and no new failure mode at the REST builder.

SOAP converts with `gmdate('c', strtotime($completedAt . ' UTC'))`, preserving the offset-bearing format `date('c')` produces today. The two transports currently render dates differently (SOAP local-offset, REST `Z`); both are unambiguous instants, so this change deliberately does not normalize them — doing so would alter existing SOAP payloads for no behavioral gain.

`transactionDate` (REST) stays the order's placement time and is untouched.

### D4. The observer resolves the timestamp alongside the order

A sibling private method to `getOrderFromObserver` reads `created_at` from the same entity the event carries: the order for `sales_order_place_after`, the invoice for `sales_order_invoice_pay`, the shipment for `sales_order_shipment_save_after`. Keeps each method single-purpose and leaves `getOrderFromObserver`'s null contract intact.

### D5. Fallback to now is normative, not defensive

At `sales_order_place_after` the order is not yet persisted, so `getCreatedAt()` can legitimately be null — the same pre-persist window the REST capture already handles for composite line resolution. The fallback is therefore a real path on the order-creation trigger, not just a guard, which is why the spec states it as a requirement. REST inherits it from `toRfc3339`; SOAP needs it written explicitly.

### D6. No new state for failed captures

A retry is triggered only by the arrival of the next fulfillment document. Recording failures (a `taxcloud_capture_failed` column plus a cron to drain it) would close the remaining gap — an order whose only document fails is still never filed — but it is a different change with its own state model and operational surface. Deferred deliberately; see proposal.md — Non-goals.

## Risks / Trade-offs

- **A persistently failing capture now issues one call per fulfillment document instead of one per order** → Bounded by the document count, which is small, and every attempt is logged. Repeated log lines for a misconfigured connection are the desired signal, not noise to suppress.
- **Removing the count guards widens the path that reaches the config gates** → Unit tests re-pin the store-scope, enabled and calculations-only gates so the behavior is demonstrably unchanged rather than assumed.
- **A store that changes its capture trigger or API type between two fulfillment documents gets the new setting on retry** → Specified rather than prevented: the retry re-resolves store-scoped config, which is the correct reading of "capture per the store's current configuration". The flag still prevents a second filing of an order already captured under the old setting.
- **A retry that lands in a later period files under that later date** → This is the intended behavior (it is what makes the date change worth making), but it means a late-healing capture files in the period it healed, not the period it was first attempted. Consistent with taking the fulfillment date as the tax point.
- **SOAP `dateAuthorized` also moves to the document time** → It is currently the same wall clock as `dateCaptured`; keeping the two in step preserves the existing relationship rather than introducing a new asymmetry between them.

## Migration Plan

Code-only. No schema change, no configuration change, no data migration, and no `setup:upgrade` step. Deploying is a normal module update; rolling back is a revert, since nothing persists a new shape. Orders captured before the change are unaffected — their `taxcloud_captured` flag continues to suppress re-capture exactly as it does today.

## Open Questions

- Whether the remaining gap in D6 — an order whose only fulfillment document fails to capture is never filed — warrants a follow-up cron or CLI to re-send failed captures. Deferrable: it changes neither these specs, this approach, nor the task breakdown, and can be proposed on its own once there is evidence of how often captures actually fail in the field.
