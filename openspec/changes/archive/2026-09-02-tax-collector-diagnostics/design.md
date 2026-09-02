## Context

See `proposal.md` — Why. The mechanism this change diagnoses is a single line in
`etc/di.xml`:

```xml
<preference for="Magento\Tax\Model\Sales\Total\Quote\Tax" type="Taxcloud\Magento2\Model\Tax" />
```

Magento declares the tax total in `vendor/magento/module-tax/etc/sales.xml`:

```xml
<item name="tax" instance="Magento\Tax\Model\Sales\Total\Quote\Tax" sort_order="450">
```

`Magento\Quote\Model\Quote\Address\Total\Collector` reads that merged config and
builds each entry with `$this->_totalFactory->create($class)` — i.e. through the
object manager. `getCollectors()` then returns the sorted, instantiated array that
checkout actually iterates. That single accessor is the whole static probe: because
`$class` comes from the merged `sales.xml` and `create()` applies DI preferences, one
call detects both a competing `sales.xml` entry and a competing preference. The
`Collector` constructor also accepts a `$store`, so the probe is naturally store-scoped.

Two conditions escape it, because they leave our class in the slot: an `around`
plugin on `collect()` that skips `$proceed`, and a collector ordered after 450 that
overwrites `tax`. Those need their own probes.

The module already has the pieces the surfaces attach to: `Console/Command/` with a
registered `CommandList` entry, `etc/events.xml` with an observer on
`sales_model_service_quote_submit_before` (which fires whether or not our collector
ran), and `Model/Config/TaxcloudConfig::isEnabled($store)`.

## Goals / Non-Goals

**Goals:**
- One diagnostics service producing a single verdict value object, consumed by all
  three surfaces, so admin, CLI, and log never disagree.
- Follow Magento's own tax-misconfiguration notification pattern closely enough that
  the banner is familiar and the dismissal link behaves the way merchants expect.
- Zero diagnostic work on the storefront request path.

**Non-Goals:**
- Detecting tax mutated outside the collector chain.
- Any per-store dismissal UI. Store scope enters through the fingerprint instead.

## Decisions

### D1: `Collector::getCollectors()` as the static probe, not `ObjectManager::get()`

**Chosen:** resolve `Magento\Quote\Model\Quote\Address\Total\CollectorFactory`, create
per store, read `getCollectors()['tax']`, and compare with `instanceof
Taxcloud\Magento2\Model\Tax`.

**Alternative rejected:** `ObjectManager::get(\Magento\Tax\Model\Sales\Total\Quote\Tax::class)`
and check the class. This only observes the preference. A competitor who re-points the
`tax` item's `instance` in its own `sales.xml` beats the preference outright and this
check reads green — a false healthy verdict on a store that is silently under-collecting,
which is worse than no check at all.

`getCollectors()` costs the construction of every quote total model (~10–15 light
objects) plus a merged-config read Magento already caches. Acceptable in admin and CLI;
see D3 for why it never runs on the storefront.

### D2: Three probes, one verdict object

`Model/Diagnostics/CollectorVerdict` is an immutable value object: the active collector
class, the interception list, the later-collector list, the affected store ids, and a
computed-failure reason. `Model/Diagnostics/TaxCollectorDiagnostics::verdict()` builds it.

- **Ownership** — D1.
- **Interception** — `PluginListInterface::getNext(\Magento\Tax\Model\Sales\Total\Quote\Tax::class, 'collect')`,
  reading the `around` bucket. Our own plugins are excluded by namespace.
- **Overwrite** — walk `getCollectors()` past the `tax` key; report entries outside the
  `Magento\` namespace. Advisory: running later does not prove a collector writes tax,
  so this is worded as "may overwrite" rather than as a failure.

Only stores where `TaxcloudConfig::isEnabled($storeId)` is true are evaluated. A store
with TaxCloud disabled cannot make the verdict unhealthy — a merchant deliberately
running another provider on one store view is not misconfigured.

### D3: Runtime detection is a quote flag, not a second probe

`Taxcloud\Magento2\Model\Tax::collect()` sets `$quote->setData('taxcloud_tax_collected', true)`
at the very top — before the `isEnabled()` early return, since the question is whether
our class got the slot, not whether it was switched on. `Observer/Sales/VerifyTaxCollector`
on `sales_model_service_quote_submit_before` reads the flag off the quote it is already
handed.

**Alternative rejected:** running the D1 probe at order placement. It would build the
collector list on a checkout request to learn something the collector itself already
knows.

This is also strictly better evidence: a missing flag caused by an `around` plugin
skipping `$proceed` is a direct observation, where D2's plugin probe can only report that
an `around` plugin exists and infer the rest. The flag is a transient data key, not a
`db_schema.xml` column, so it is never persisted.

Enablement resolves against `$quote->getStoreId()`, matching the comment already in
`collect()` about admin and API contexts.

### D4: Notification modeled on `Magento\Tax\Model\System\Message\Notifications`

Same four-part shape as Magento's own tax warning:

| Magento | Ours |
| --- | --- |
| `Model/System/Message/Notifications.php` (aggregator, `MessageInterface`) | `Model/System/Message/Notifications.php` |
| `Model/System/Message/Notification/RoundingErrors.php` etc. (`NotificationInterface`) | `CollectorOverridden`, `CollectorIntercepted`, `CollectorOverwritten`, `DiagnosticsUnavailable` |
| `Controller/Adminhtml/Tax/IgnoreTaxNotification.php` | `Controller/Adminhtml/Notification/Ignore.php` |
| `Magento\Framework\Notification\MessageList` in `etc/adminhtml/di.xml` | same |

Severity `SEVERITY_CRITICAL`, matching Magento's treatment of tax misconfiguration.
`getText()` follows their layout: bold headline, "Store(s) affected:", a documentation
link (`taxcloud/notification/info_url`, defaulted in `etc/config.xml` to the new
troubleshooting page, mirroring `tax/notification/info_url`), and the dismissal link.

### D5: Dismissal stores a fingerprint, not a boolean

This is the one deliberate divergence from Magento. `IgnoreTaxNotification` writes
`tax/notification/ignore_<section>` = `1` at default scope: once ignored, ignored
forever, regardless of whether the underlying configuration changed.

For us that reintroduces the exact silence this change exists to remove. A merchant
dismisses "Avalara owns the tax collector" during a planned migration; months later a
different module takes the slot and the banner never returns.

**Chosen:** `Controller/Adminhtml/Notification/Ignore` writes a SHA-256 fingerprint of the
verdict — sorted responsible class names plus the sorted affected store ids — to
`taxcloud/notification/acknowledged_collector_state` at default scope, via
`Magento\Config\Model\ResourceModel\Config::saveConfig()`. `isDisplayed()` recomputes the
fingerprint and compares. Any change in the responsible class or the affected store set
produces a different fingerprint and re-raises the banner unprompted.

Including the store ids is what gives store awareness without a per-store dismissal UI:
a conflict spreading to a new store view re-raises.

The path has no `system.xml` field — it is written only by the controller, exactly as
Magento's `ignore_*` flags are.

### D6: Verdict cached in `App\Cache\Type\Config`

`isDisplayed()` runs on every admin page render, so the verdict is cached. `Config` is
the right cache type for three reasons: `Collector` already caches its sorted codes
there; `setup:upgrade` and any DI or module change flush it, so a stale verdict cannot
outlive the thing that would change it; and Magento's own dismissal controller cleans
exactly this type, so our dismissal invalidates the cached verdict for free.

Negative verdicts are cached too, so a failing store does not recompute on every page.
On a large multi-store install the verdict is computed lazily per store rather than
looping every store up front.

### D7: Dismissal silences the banner only

The CLI always reports the true verdict and always exits non-zero when unhealthy —
support needs the truth regardless of what the merchant clicked.

The order-placement log line is likewise unaffected by dismissal, for a reason that only
became clear while implementing: making it acknowledgement-aware would mean computing a
fingerprint, which means computing the verdict, on the order placement path — exactly
what the "diagnostics never burden the storefront" requirement forbids. The rate limit
already solves the noise problem the downgrade was meant to solve, so the observer logs
at `warning` and reads no verdict at all. It reports only what it can know for free: this
store has TaxCloud enabled and TaxCloud's collector did not run. Naming the class that
won is the CLI's and the banner's job, where computing the verdict is cheap.

### D8: Guard the probe

Instantiating the collector list instantiates *third-party* collectors, including the
competitor's. A heavy or throwing constructor there would otherwise surface as a stack
trace on the admin dashboard. `verdict()` catches `Throwable` and returns a verdict whose
state is "could not compute", carrying the reason — which is itself reportable and
notifiable, rather than swallowed.

## Risks / Trade-offs

- **Third-party collector constructors run during the probe** → D8 guard; verdict is
  computed in admin/CLI only, never on the storefront.
- **`getCollectors()` cost on a cold cache in a multi-store install** → lazy per-store
  computation (D6) plus caching of both healthy and unhealthy verdicts.
- **Overwrite detection is advisory and can produce noise** — a benign non-Magento
  collector ordered after 450 is common (loyalty, gift card, fee modules) → reported as
  informational, never on its own enough to make the verdict unhealthy, and covered by
  the fingerprint so it can be dismissed once.
- **A healthy verdict can still mean zero tax** (bad credentials, `fallback_to_magento`)
  → explicit wording requirement in the spec; command output and banner both say the
  check covers collector ownership only.
- **Fingerprint churn on unrelated store additions** — adding a store view where TaxCloud
  is enabled changes the store set and re-raises a dismissed banner → accepted
  deliberately: a new store view genuinely is a store that was never assessed.
- **Quote flag is transient** — if some future code path collects totals without our
  collector and then submits, the warning is correct by construction; there is no case
  where the flag is missing but our collector ran.

## Migration Plan

No data migration and no schema change. `taxcloud/notification/acknowledged_collector_state`
starts unset, which reads as "nothing acknowledged" — correct default. Rollback is
removing the module version; nothing outside `core_config_data` is written, and the one
row it may write is inert if the classes are gone.
