## Why

TaxCloud claims the tax slot in Magento with a single di.xml preference on
`Magento\Tax\Model\Sales\Total\Quote\Tax`. Tax in Magento is winner-take-all:
if another module wins that slot — via a competing preference, a competing
`sales.xml` entry, or an `around` plugin that skips `$proceed` — TaxCloud's
`collect()` never runs. No calculation, no capture, no error. The extension
still shows as installed, enabled, and green on Test Connection.

This failure is entirely silent today, which is how it reached a merchant: the
store under-collected tax until someone noticed by hand. There is no admin
setting to build here — Magento has no tax-provider priority concept — so the
fix is detection, not configuration.

## What Changes

- Add a diagnostics service that determines, per store, whether TaxCloud is the
  tax total collector Magento will actually run, and names the class that won
  if it is not.
- Detect three distinct ways TaxCloud can be displaced: a different class
  occupying the `tax` total collector slot, an `around` plugin on `collect()`
  intercepting the call, and a later collector overwriting the computed tax.
- Record at runtime whether `Taxcloud\Magento2\Model\Tax::collect()` actually
  ran for a quote, and log a warning at order placement when it did not while
  TaxCloud is enabled for that store.
- Show a dismissable admin banner naming the conflict, modeled on Magento's own
  tax misconfiguration notification (`Magento\Tax\Model\System\Message\Notifications`).
  Dismissal stores a fingerprint of the acknowledged conflict rather than a
  permanent boolean, so a different or newly appearing conflict re-raises the
  banner on its own.
- Add `bin/magento taxcloud:diagnose`, which reports the full verdict per store
  and is unaffected by banner dismissal.
- Add a merchant-facing troubleshooting page covering "TaxCloud isn't
  calculating", reading the diagnose output, and the documented `<sequence>`
  recipe for resolving a conflict with a specific named module.

## Capabilities

### New Capabilities
- `tax-collector-diagnostics`: detecting whether TaxCloud is the active tax
  total collector for a store, and surfacing that verdict through an admin
  banner with fingerprinted acknowledgement, a CLI command, and a runtime log
  line at order placement.

### Modified Capabilities
<!-- None. The existing connection-test capability is untouched: it verifies
     credentials, which is a separate question from which collector runs. -->

## Non-goals

- **No "tax provider priority" setting.** Magento has no such concept, and a
  setting in this module would be structurally incapable of firing in the
  scenario it was requested for: if a competitor wins the preference,
  `Taxcloud\Magento2\Model\Tax` is never instantiated, so no config value of
  ours is ever read.
- **No shipped `<sequence>` entries for competing tax modules** (Avalara,
  Vertex, TaxJar, Sovos). Where those modules are absent the entries are a
  no-op; where they are present they silently hijack the tax slot from a
  merchant who may have installed the other module deliberately. The
  `<sequence>` remedy ships as a documented recipe applied against the module
  actually conflicting, not as a blind default.
- **No change to how TaxCloud claims the slot.** Replacing the di.xml
  preference with an `around` plugin is a separate architectural question that
  was previously declined; this change diagnoses the current mechanism rather
  than replacing it.
- **No detection of tax mutated outside the collector chain** (for example an
  observer rewriting totals at order save). Rare, and out of scope here.
- **Green is not a correctness claim.** A passing verdict means TaxCloud's
  collector runs, not that tax is correct — bad credentials or
  `fallback_to_magento` swallowing an error still produce zero tax. Admin
  wording must not conflate the two.

## Impact

**Store scoping.** The verdict has two halves with different scopes, and the
implementation must keep them apart. Which class occupies the collector slot is
global (DI preferences and `sales.xml` are not store-scoped), but whether
TaxCloud is *supposed* to be running is per store via
`TaxcloudConfig::isEnabled($storeId)`. The service therefore iterates only the
stores where TaxCloud is enabled, and `Magento\Quote\Model\Quote\Address\Total\Collector`
is constructed per store, since it accepts a store and applies store-scoped
total sort order. Reported store lists feed the dismissal fingerprint, so a
conflict newly affecting an additional store re-raises an already-dismissed
banner. The runtime check resolves against the *quote's* store, not the ambient
request store, matching `Model\Tax::collect()`.

**Performance.** The storefront must not pay for diagnostics. The runtime check
is a flag written in `collect()` and read at order placement — no collector
construction on the checkout path. The admin banner reads a verdict cached in
`Magento\Framework\App\Cache\Type\Config`, the same cache type the dismissal
controller cleans and that any module or DI change invalidates.

**Code.** New `Model/Diagnostics/`, `Model/System/Message/`,
`Console/Command/DiagnoseCommand.php`, `Controller/Adminhtml/Notification/Ignore.php`,
`Observer/Sales/VerifyTaxCollector.php`; one flag write in `Model/Tax::collect()`;
new `etc/adminhtml/di.xml` `MessageList` entry, `etc/events.xml` observer,
`etc/config.xml` defaults, `etc/acl.xml` unchanged (reuses the existing config
resource).

**Risk.** The static probe instantiates third-party total collectors, including
a competitor's. A heavy or throwing constructor there would surface on an admin
page load, so the probe is guarded and a failure to compute becomes its own
reportable state rather than a stack trace.

**Docs.** New `docs/troubleshooting.md` plus an `mkdocs.yml` nav entry (the site
currently has only Home and Development), README, and CHANGELOG.
