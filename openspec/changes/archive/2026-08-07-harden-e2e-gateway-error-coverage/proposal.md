# Harden E2E Gateway Error Coverage

## Why

The configurable-refund regression (fixed in `2026-08-07-fix-rest-refund-duplicate-line-items`) sailed through all three suites: unit and integration tests fed the refund conversion fabricated shapes instead of real Magento composite-product rows, and the e2e journeys are structurally blind to gateway failures — the module deliberately never blocks checkout or admin flows on a TaxCloud error, so a journey completes green while the operation silently fails. The only observable signal is a `tclogger.ERROR` line in `var/log/taxcloud.log`.

## What Changes

Three layers, no runtime behavior changes (tests and test fixtures only — `skip_specs`):

1. **E2E log-watcher fixture**: a Playwright fixture that snapshots `var/log/taxcloud.log` before each journey and fails the test when any new `.ERROR:` line appears during it. Opted into by the customer/refund journey specs (not the admin specs that deliberately provoke connection failures). Because the journeys already run in both the SOAP and REST project passes, every gateway operation — lookup, capture, refund, cancellation — becomes error-checked on both transports for whatever product types the journeys use.
2. **Configurable-product journey**: a checkout + credit-memo e2e spec using the seeded configurable product, running in both transport passes. With layer 1, the exact bug just fixed would have failed the suite pointing at the ERROR line.
3. **Integration regression test**: builds a real configurable order and credit memo (real Magento parent/child rows, not mocks) and asserts the v3 refund conversion emits unique item references.

## Capabilities

### New Capabilities

_None (skip_specs: test coverage only, no spec-level behavior changes)._

### Modified Capabilities

_None._

## Impact

- **Code**: `Test/E2E/fixtures/` (new log-watcher fixture), journey specs updated to opt in, one new e2e spec, `Test/E2E/playwright.config.ts` (include the new spec in the REST pass), one new integration test under `Test/Integration/Rest/`. No module runtime code.
- **CI**: no new secrets or env; the e2e job already has filesystem access to the install's `var/log`.
- **Tests**: e2e suites gain failure sensitivity to swallowed gateway errors; runtime cost is one extra journey per transport pass.

## Non-goals

- No change to the module's swallow-errors-never-block-checkout behavior (that is correct production behavior).
- No log assertions in admin specs that intentionally provoke failures (wrong-connection tests).
- No unit-test restructuring beyond what the previous change already added.
