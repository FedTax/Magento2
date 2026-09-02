## Why

The collector diagnostics shipped with unit coverage only, and the unit tests
inject the collector array. Nothing in the suite exercises the claim the whole
design rests on: that
`Magento\Quote\Model\Quote\Address\Total\Collector::getCollectors()['tax']`
reflects real DI state on a real install — the merged `sales.xml` *and* the
object-manager preference — which is why that probe was chosen over resolving
the core tax class directly.

Two further claims were reasoned from reading Magento source and never executed:
that the quote marker `Tax::collect()` sets survives from `collectTotals()` to
`sales_model_service_quote_submit_before` within one `placeOrder` request, and
that the diagnostics service is wired correctly enough to construct at all
(`CollectorFactory`, `PluginListInterface`, and the config-cache binding in
`etc/di.xml`). A DI misconfiguration in any of those fails at runtime while the
entire unit suite stays green.

## What Changes

- Add an integration test covering the diagnostics probe against the booted
  Magento: the verdict resolves, is healthy, and names
  `Taxcloud\Magento2\Model\Tax` as the active collector.
- Cover the failure mode by rebinding the tax total's preference on the live
  object manager, so the probe is exercised against a genuine competing
  preference rather than an injected array.
- Cover the runtime marker end to end: place a real order and assert the marker
  is present on the quote at submit time, and absent when another class owns the
  collector.
- Cover the acknowledgement round trip through real `core_config_data`: dismiss
  a conflict, confirm it is suppressed, confirm a changed conflict re-raises.
- Cover store scoping: a store view with TaxCloud disabled does not appear in
  the verdict.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
<!-- None. This adds coverage for behavior already specified by the
     tax-collector-diagnostics capability; no requirement changes, so the change
     sets skip_specs: true. -->

## Non-goals

- **No e2e coverage.** Exercising the admin banner in a browser would need a
  competing tax module installed into the e2e stack purely to render a message
  whose content the unit tests already pin. High setup cost, no claim covered
  that these tests do not already cover.
- **No fixture module on disk.** Installing a second Magento module to supply a
  competing `sales.xml` would need `setup:upgrade` and `di:compile` inside the
  test run. The preference rebinding below reaches the same code path — the
  probe reading what the object manager actually resolves — without it.
- **No change to production code.** An earlier revision made
  `VerifyTaxCollector::RATE_LIMIT_KEY_PREFIX` public so a test could clear one
  store's rate-limit entry; that test is gone and the constant is private again.
- If a test here fails on the module's own logic, the finding is real and gets
  its own change.

## Impact

**Store scoping.** The seeded second store view has TaxCloud disabled, which is
exactly the case the verdict must exclude; the existing
`setSecondStoreConfig()` helper drives it.

**Test isolation.** Rebinding a DI preference and evicting shared instances is
process-global. The tests must restore both, in the manner
`IntegrationTestCase::mutateSharedInstances()` already establishes, or every
later test in the run sees a competitor owning the tax collector. The verdict is
also cached in the config cache type, so each test must clear it rather than
read a neighbour's result.

**Code.** New `Test/Integration/Model/Diagnostics/TaxCollectorDiagnosticsTest.php`.
Possibly a small helper on `IntegrationTestCase` if preference rebinding proves
reusable. No production files.
