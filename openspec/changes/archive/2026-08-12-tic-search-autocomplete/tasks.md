## 1. Lookup service and backends

- [x] 1.1 Add `TicSuggestion` — normalised `{code, label, detail, score}`, with `detail`/`score` optional so one shape serves both sources (design D2)
- [x] 1.2 Add `TicLookupInterface::search(string $query, $store): TicSuggestion[]` — returns `TicSearchResult` rather than a bare array, so "no matches" and "could not look" stay distinguishable (task 1.7 / spec: degrades without obstructing work); adds `resolve()` for the stored-code display
- [x] 1.3 REST backend: `POST /tax/tic/search` via `RestClient::request()` with `connectionScoped: false` (account-level — no `/connections/{id}` prefix), mapping `ticId`/`label`/`naturalLabel`/`documentation`/`score`
- [x] 1.4 SOAP backend: fetch `GetTICs`, match locally against `TICID` + `Description`
- [x] 1.5 Store-aware dispatcher selecting the backend from `api_type`, mirroring `Model\Gateway\Router`; resolve against the passed store, never the ambient one
- [x] 1.6 Exact-code entry ranks first on both backends (spec: "searching by code")
- [x] 1.7 Every failure path returns "unavailable" rather than throwing — no credentials, rejected credentials, transport error, 429

## 2. Caching

- [x] 2.1 Cache the v1 list per credential set with a daily TTL in `Model\Cache\Type\Taxcloud` (design D3)
- [x] 2.2 Cache v3 results by normalised query + store, short TTL, to blunt the documented 429
- [x] 2.3 Confirm both are cleared by `bin/magento cache:clean taxcloud` — no new cache type registered (the cache type is a `TagScope`, so it stamps its own tag on every write; entries also carry `taxcloud_tic`)

## 3. Admin endpoint

- [x] 3.1 Add the lookup controller on the existing `taxcloud` admin route, following the thin-shell pattern of `Controller/Adminhtml/Connection/Test`
- [x] 3.2 Override `_isAllowed()` to admit `Magento_Tax::config_tax` **or** `Magento_Catalog::products` **or** `Magento_Catalog::categories` — a catalog manager editing a product TIC has no tax-config permission and must not get a silently empty search box (design D5)
- [x] 3.3 Return suggestions only; no credential value in any response (spec: "does not disclose credentials")

## 4. Shared UI component

- [x] 4.1 Knockout element component extending `Magento_Ui/js/form/element/abstract`, plus its template — one implementation, reused by every mount (design D1)
- [x] 4.2 Result row: fixed-width code column, description as primary text, `detail` as secondary line, `score` where present; collapses cleanly to one line when the backend supplies neither
- [x] 4.3 Resolved state — a recognised stored code displays its description beneath the field
- [x] 4.4 Not-found state — amber, informational, "will be saved exactly as entered"; visually distinct from the unavailable state
- [x] 4.5 Inherit hint for an empty field: product/category show the resolved fallback and its origin; `shipping_tic` states it falls back to its configured default only (design D6)
- [x] 4.6 Unavailable state — plain text input plus a note; the credentials-not-saved case gets its own wording, since the config screen shows TIC and credential fields together during first-run setup
- [x] 4.7 Debounce (~250 ms), cancel in-flight requests on query change, minimum query length (design D4)
- [x] 4.8 Keyboard support: arrows, Enter to select, Esc to dismiss; the input stays freely editable throughout

## 5. Mount the component in all four places

- [x] 5.1 Category — `view/adminhtml/ui_component/category_form.xml` (wired early as the verification mount; the other three still pending)
- [x] 5.2 Product — form modifier pointing the dynamically-rendered EAV attribute at the component
- [x] 5.3 `default_tic` — `frontend_model` block hosting the component via `x-magento-init` + `Magento_Ui/js/core/app`
- [x] 5.4 `shipping_tic` — same block, different field metadata
- [x] 5.5 Verified in a real admin: category (`taxcloud_tic`), product (`product[taxcloud_tic]`, `[GLOBAL]`), `default_tic` and `shipping_tic` all render the same control; config inputs keep their `groups[...][value]` names so the form still posts them; `45999` saved unchanged, so freeform survives

## 6. Unit tests

- [x] 6.1 REST backend: request shape, response mapping, empty results, 429 → unavailable
- [x] 6.2 SOAP backend: local matching, ranking, exact-code-first
- [x] 6.3 Dispatcher: `api_type` decides the backend, resolved for the passed store; mixed-fleet case
- [x] 6.4 Every failure path yields "unavailable", never an exception escaping to the caller
- [x] 6.5 Caching: v1 list fetched once across repeated searches; v3 repeat query served from cache. **This test found a real defect** — with the `taxcloud` cache type disabled in admin, the v1 backend re-fetched all 779 TICs on every search; fixed by memoizing the catalogue (and its failures) per request in `SoapTicLookup`
- [x] 6.6 Controller: each of the three ACL resources grants access; a session with none is refused
- [x] 6.7 Controller: no credential value appears in any response payload
- [x] 6.8 Verify added test code runs on PHPUnit 9.5, 10.5 and 12.5. Two real incompatibilities hit and fixed: a helper named `result()` collides with `TestCase::result()` (final in 12), and `@dataProvider` alone is ignored by 12 — the repo's convention is annotation **and** `#[DataProvider]` attribute together

## 7. Quality gates

- [x] 7.1 `make phpstan` clean at level 5 with no new baseline entries
- [x] 7.2 `make lint` clean on all touched files
- [x] 7.3 Full unit suite green

## 8. Empirical verification

- [x] 8.1 Resolve the lookup service through real DI on the dev stack and confirm both backends return sane results for a known query
- [x] 8.2 Confirm the live v3 search returns the expected shape (verified once already on 2026-08-10: 779 TICs from `GetTICs`, `ResponseType: 3`)
- [x] 8.3 All four verified in a real admin (Adobe Commerce 2.4.9, Spectrum): category `candy`→`40010`; product `coffee`→`90408` with inherit hint; `default_tic` `20000`→Clothing and `shipping_tic` `11010` auto-resolved on load; `handling`→`11000`/`11020`/`11001`; unknown `45999` saved to the database unchanged

## 9. Documentation

- [x] 9.1 CHANGELOG entry describing TIC search, naming both backends
- [x] 9.2 State in the entry that results differ between v1 and v3 by design, so support does not read it as a defect

## 10. Wider test coverage (propose, do not assume)

- [x] 10.1 Confirmed by the maintainer and implemented:
  - `Test/Integration/Model/Tic/TicLookupWiringTest.php` — DI preference resolves to the router, the lookup controller instantiates (the exact 500 seen during implementation), the modifier class is autoloadable (the exact ReflectionException seen), the modifier rewrites real meta without disturbing it, and three live API calls. **8 tests, 19 assertions, green**
  - `Test/E2e/specs/admin/admin-tic-search.spec.ts` — product form (modifier attached, search → select → resolved, unknown code kept) and both config fields (control present, config field `name` preserved so the setting still posts, saved codes auto-resolved, search works). **2 tests, green**
  - Noted while writing: the product form modifier pool is an adminhtml-area virtualType and the integration bootstrap does not load adminhtml `di.xml`, so its registration is asserted against the declaration rather than a resolved instance

## 11. Post-review fixes (found by the maintainer against a real store)

- [x] 11.1 **Exact code was not ranked first.** `RestTicLookup::search()` applied `exactCodeFirst` only on the cold path, so any cached query returned TaxCloud's raw order — searching `20000` listed `10000` first. Ranking now happens on the way out of the cache. Regression test added covering cold **and** warm paths, with a cache that actually stores; the existing tests missed it because every fake cache always missed
- [x] 11.2 **Suggestion labels were invisible on the configuration fields.** The config form sets `font-size: 0` on its field containers (the old inline-block whitespace trick) and expects children to redeclare it. `.taxcloud-tic-label` was the only text element without an explicit `font-size`, so it collapsed to zero height while every explicitly-sized sibling in the same row kept rendering. Explicit `font-size` and `color` added on the label and on the row
- [x] 11.3 E2E now asserts a suggestion is **visible and non-empty**, not merely present — asserting existence passed throughout 11.2. That assertion reproduced the bug locally within minutes of being written
- [x] 11.4 **Loading indicator** while a lookup is in flight, cleared on any outcome. Writing it surfaced two related defects, both fixed: deleting below the minimum query length, and choosing a suggestion, did not abandon an in-flight request — so a late answer could repopulate a list the admin had already dismissed
- [x] 11.5 Considered and **rejected** a lexical boost for partial words (searching `clothin` ranks `PELLONS` above `Clothing`, because v3 search is semantic and a truncated word embeds vaguely). The maintainer chose to keep TaxCloud's ranking, so design D2 stands unchanged
