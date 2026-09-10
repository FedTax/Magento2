# Fix E2E REST-Pass Ordering and Refund Race

## Why

CI (2.4.8 row) failed with three issues the local runs dodged by scheduling luck. (1) Playwright ran `rest-setup` before the chromium pass — its project dependency only orders setup before `checkout-rest`, not after chromium — so the Bearer-mode admin spec later restored SOAP and the REST pass silently ran over SOAP. (2) The gateway-error watcher exposed a real race the old orderId collisions had been masking: TaxCloud records captures asynchronously, so a v1 `Returned` fired seconds after capture fails with "The order could not be found or has not been captured yet". (3) Chasing the residual failure surfaced a genuine runtime bug: REST capture of a configurable files the zero-priced child row as a duplicate v3 line — capture fires on `sales_order_place_after`, before the order is saved, when child rows have no `parent_item_id` yet and `getAllVisibleItems()` lets them through. Every refund of such an order then fails ("item ... appears on multiple cart lines").

## What Changes

- `rest-setup` gains `dependencies: ['chromium']`, making the pass order deterministic: chromium (SOAP) → rest-setup → checkout-rest (REST) → rest-teardown.
- The two refund journeys wait a grace period between order placement (capture) and the admin refund, mirroring the sandbox's async capture recording; comment documents why.
- The watcher fixture reports errors by throwing instead of `expect()` inside fixture teardown, removing Playwright's "Internal error: step id not found" noise.
- **Runtime fix**: `buildOrderPayload()` skips composite child rows via the in-memory `parent_item` object (set pre-save) as well as `parent_item_id`, so composite orders file exactly one line per purchasable item.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `rest-tax-operations`: the capture requirement gains the constraint that composite products file as their purchasable parent line only, including when capture runs before the order is persisted.

## Impact

`Model/Gateway/Rest/RestRequestBuilder::buildOrderPayload()` (composite-child guard) + unit test; `Test/E2E/playwright.config.ts`, the two refund journey specs, `Test/E2E/fixtures/taxcloudLog.ts`.

## Non-goals

- No module-side retry for "not captured yet" — a merchant refunding within seconds of capture is not a flow the module should special-case; the sandbox race is a test-timing concern.
