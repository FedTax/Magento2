# Tasks: fix-e2e-rest-pass-ordering-and-refund-race

## 1. Fix

- [x] 1.1 Deterministic pass order: rest-setup depends on chromium
- [x] 1.2 Grace wait between capture (order placement) and admin refund in the two refund journeys
- [x] 1.3 Watcher fixture throws instead of expect() in teardown (removes step-id noise)

- [x] 1.4 Runtime fix: `buildOrderPayload()` drops composite child rows (in-memory parent_item guard, pre-save safe) with a unit test pinning the unsaved-order shape

## 2. Verify

- [x] 2.1 Full local e2e run green with deterministic ordering (setup listed after chromium)
