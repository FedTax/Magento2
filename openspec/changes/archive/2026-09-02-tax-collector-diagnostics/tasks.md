## 1. Diagnostics core

- [x] 1.1 Add `Model/Diagnostics/CollectorVerdict.php` — immutable value object: active collector class, interceptor list, later-collector list, affected store ids, computed-failure reason, plus `isHealthy()` and `fingerprint()` (SHA-256 of sorted responsible classes + sorted store ids)
- [x] 1.2 Add `Model/Diagnostics/TaxCollectorDiagnostics.php` — ownership probe via `CollectorFactory::create(['store' => $store])->getCollectors()['tax']`, compared with `instanceof Taxcloud\Magento2\Model\Tax`
- [x] 1.3 Add the interception probe: `PluginListInterface::getNext(Magento\Tax\Model\Sales\Total\Quote\Tax::class, 'collect')`, `around` bucket, excluding our own namespace
- [x] 1.4 Add the overwrite probe: walk `getCollectors()` past the `tax` key, report non-`Magento\` entries as advisory
- [x] 1.5 Restrict evaluation to stores where `TaxcloudConfig::isEnabled($storeId)` is true; a disabled store cannot make the verdict unhealthy
- [x] 1.6 Guard `verdict()` with a `Throwable` catch returning a "could not compute" verdict carrying the reason (design D8)
- [x] 1.7 Cache the verdict (healthy and unhealthy) in `Magento\Framework\App\Cache\Type\Config`, computed lazily per store

## 2. Runtime detection

- [x] 2.1 Set `taxcloud_tax_collected` on the quote at the top of `Model/Tax::collect()`, before the `isEnabled()` early return
- [x] 2.2 Add `Observer/Sales/VerifyTaxCollector.php` — read the flag, resolve `isEnabled()` against the quote's store, log a warning when enabled and the flag is absent
- [x] 2.3 Rate limit the warning per store via the module cache type; log at `warning` and compute no verdict on this path (design D7)
- [x] 2.4 Register the observer on `sales_model_service_quote_submit_before` in `etc/events.xml`

## 3. Admin notification

- [x] 3.1 Add `Model/System/Message/NotificationInterface.php` and the `Notifications` aggregator implementing `Magento\Framework\Notification\MessageInterface` at `SEVERITY_CRITICAL`
- [x] 3.2 Add the four notifications: `CollectorOverridden`, `CollectorIntercepted`, `CollectorOverwritten`, `DiagnosticsUnavailable`
- [x] 3.3 Build `getText()` in Magento's layout — bold headline, "Store(s) affected:", documentation link from `taxcloud/notification/info_url`, dismissal link
- [x] 3.4 Register the aggregator on `Magento\Framework\Notification\MessageList` and inject the children, in `etc/adminhtml/di.xml`
- [x] 3.5 Add `taxcloud/notification/info_url` default in `etc/config.xml`, pointing at the troubleshooting page

## 4. Dismissal

- [x] 4.1 Add `Controller/Adminhtml/Notification/Ignore.php` — write the verdict fingerprint to `taxcloud/notification/acknowledged_collector_state` at default scope via `Config\Model\ResourceModel\Config::saveConfig()`, clean the `config` cache type, redirect to referer
- [x] 4.2 Gate `isDisplayed()` on a fingerprint comparison, so a changed responsible class or store set re-raises the banner (design D5)
- [x] 4.3 Reuse the existing ACL resource for the controller; add no `system.xml` field for the acknowledgement path

## 5. CLI

- [x] 5.1 Add `Console/Command/DiagnoseCommand.php` (`taxcloud:diagnose`) — per-store table of active collector, interception, later collectors
- [x] 5.2 Exit non-zero on an unhealthy verdict; ignore the acknowledgement entirely
- [x] 5.3 Qualify healthy output: collector ownership only, not credentials or calculation
- [x] 5.4 Register the command in the existing `CommandList` entry in `etc/di.xml`

## 6. Tests

- [x] 6.1 Unit tests for `TaxCollectorDiagnostics` — all four verdict states, store filtering, and the `Throwable` guard, with injected collector arrays and plugin lists
- [x] 6.2 Unit tests for `CollectorVerdict::fingerprint()` — stable across ordering, changes with class or store set
- [x] 6.3 Unit tests for the observer — flag present/absent, disabled store, quote-store resolution, rate limiting
- [x] 6.4 Unit tests for the notification and the dismissal gate — displayed, dismissed, re-raised on a changed fingerprint
- [x] 6.5 Unit test for the command's exit status
- [x] 6.6 Verify all new test code runs on PHPUnit 9.5, 10.5 and 12.5
- [x] 6.7 Integration coverage of the real `Collector` resolution proposed to the maintainer (see wrap-up); not added pending confirmation, per the project's test-coverage policy

## 7. Documentation

- [x] 7.1 Add `docs/extension-conflicts.md` under the existing Troubleshooting section — "TaxCloud isn't calculating", reading the diagnose output, and the `<sequence>` recipe for a named conflicting module; cross-link from `common-problems.md`
- [x] 7.2 Add the page to `mkdocs.yml` nav
- [x] 7.3 Update `README.md` with the `taxcloud:diagnose` command
- [x] 7.4 Add a `CHANGELOG.md` entry

## 8. Verification

- [x] 8.1 Run the unit suite and PHPStan level 5 (`make phpstan`); add no new baseline entries
- [x] 8.2 Confirm no diagnostics code runs on the storefront path
