# Tasks: harden-e2e-gateway-error-coverage

## 1. E2E log watcher

- [x] 1.1 Log-watcher fixture: resolve the install's `var/log/taxcloud.log` (MAGENTO_INSTALL_DIR, falling back to the compose default sibling), snapshot size before the test, fail on new `.ERROR:` lines after it, with the offending lines in the failure message
- [x] 1.2 Opt the customer/refund journey specs into the fixture (checkout specs + credit-memo spec); leave the deliberate-failure admin specs on the base test

## 2. Configurable journey

- [x] 2.1 E2E spec: configurable product checkout (select a variant, guest checkout to the seeded TX address, tax row present and totals consistent) followed by admin invoice + credit memo, under the log watcher
- [x] 2.2 Include the new spec in both transport passes (chromium naturally; add to the checkout-rest testMatch if not already covered by pattern)

## 3. Integration regression

- [x] 3.1 Integration test: place a real configurable-product order, create its credit memo, and assert `RestRequestBuilder::buildRefundItems()` emits unique item references with the parent quantity

- [x] 3.2 Root-cause fix for the errors the watcher surfaced: seed a per-run timestamp prefix onto the order increment sequence, so e2e orders never collide with orders a previous install already filed on the shared sandbox account

## 4. Verify

- [x] 4.1 Unit/PHPStan/lint green; integration suite green (incl. the new test); e2e run green with the watcher active on both passes
- [x] 4.2 Sanity-check the watcher actually detects: temporarily assert against a log line pattern known to exist (or replay reasoning from the fixed bug) to prove the fixture fails when an ERROR appears
