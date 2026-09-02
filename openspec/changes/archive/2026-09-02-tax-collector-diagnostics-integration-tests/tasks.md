## 1. Harness

- [x] 1.1 Add a `rebindTaxCollector()` helper that swaps the object-manager preference for `Magento\Tax\Model\Sales\Total\Quote\Tax`, evicts the shared instances that cache it, and restores both in teardown
- [x] 1.2 Add a competing-collector double under `Test/Integration/Doubles/` extending the core tax total, so the rebinding produces a class that is neither ours nor core-namespaced
- [x] 1.3 Clear the cached verdict between tests so one test's result cannot serve another's

## 2. Probe against real DI

- [x] 2.1 Verdict resolves through the object manager and is healthy on the stock install, naming `Taxcloud\Magento2\Model\Tax`
- [x] 2.2 `Collector::getCollectors()['tax']` is the class our `etc/di.xml` preference names — the mechanism claim the design rests on
- [x] 2.3 With the preference rebound to a competitor, the verdict is unhealthy and names that class
- [x] 2.4 A store view with TaxCloud disabled does not appear in the verdict

## 3. Runtime marker — REVERTED AFTER CI

- [ ] 3.1 Placing a real order leaves `Tax::COLLECTED_FLAG` on the quote at submit time
- [ ] 3.2 With the collector rebound to a competitor, the observer warns, naming the affected store

**Status:** written, verified green locally, and then removed — they turned CI red
across all three community Integration jobs (52 failures). Reproducing on a clean
install showed why, and it is worth recording because it is not obvious.

Exercising the competitor path meant rebinding the tax-total DI preference and
evicting the collector graph so the change took. That works. What it also does is
force `Collector` and `TotalsCollectorList` to be rebuilt in the middle of the
run — and `Collector` keeps a CONCRETE `Tax` instance while
`TotalsCollectorList` keeps that `Collector`. The rebuilt pair is wired to
whichever SOAP mock was installed at that moment, and that mock is torn down when
the test ends. Every later test's lookups then go to a dead client and report
"no lookup happened" — fifty tests away from the cause. Adding the collector
classes to the base class's SOAP eviction set makes it worse (64 failures): the
suite depends on that list being built once, early, and never rebuilt.

The local suite hid all of this because the install had accumulated state; only a
clean install reproduced it.

**Conclusion:** the competitor and interception paths cannot be exercised against
real DI in this shared-process suite, and are covered by unit tests instead. The
integration tests that remain are read-only and mutate no global state.

## 4. Acknowledgement round trip

- [x] 4.1 Acknowledging a conflict writes to `core_config_data` at default scope and suppresses the notification
- [x] 4.2 A changed conflict re-raises the notification despite the stored acknowledgement
- [x] 4.3 A healthy verdict clears the stored acknowledgement

## 5. Verification

- [x] 5.1 Run the integration suite and confirm the new tests pass
- [x] 5.2 Confirm the full integration suite still passes — the DI rebinding must not leak into later tests
- [x] 5.3 Run PHPStan and PHPCS over the new test file
- [x] 5.4 No `docs/INTEGRATION_TESTS.md` change needed — the helpers are private to the one test class and add no suite-wide convention
