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

- [ ] 3.1 Add the lookup controller on the existing `taxcloud` admin route, following the thin-shell pattern of `Controller/Adminhtml/Connection/Test`
- [ ] 3.2 Override `_isAllowed()` to admit `Magento_Tax::config_tax` **or** `Magento_Catalog::products` **or** `Magento_Catalog::categories` — a catalog manager editing a product TIC has no tax-config permission and must not get a silently empty search box (design D5)
- [ ] 3.3 Return suggestions only; no credential value in any response (spec: "does not disclose credentials")

## 4. Shared UI component

- [ ] 4.1 Knockout element component extending `Magento_Ui/js/form/element/abstract`, plus its template — one implementation, reused by every mount (design D1)
- [ ] 4.2 Result row: fixed-width code column, description as primary text, `detail` as secondary line, `score` where present; collapses cleanly to one line when the backend supplies neither
- [ ] 4.3 Resolved state — a recognised stored code displays its description beneath the field
- [ ] 4.4 Not-found state — amber, informational, "will be saved exactly as entered"; visually distinct from the unavailable state
- [ ] 4.5 Inherit hint for an empty field: product/category show the resolved fallback and its origin; `shipping_tic` states it falls back to its configured default only (design D6)
- [ ] 4.6 Unavailable state — plain text input plus a note; the credentials-not-saved case gets its own wording, since the config screen shows TIC and credential fields together during first-run setup
- [ ] 4.7 Debounce (~250 ms), cancel in-flight requests on query change, minimum query length (design D4)
- [ ] 4.8 Keyboard support: arrows, Enter to select, Esc to dismiss; the input stays freely editable throughout

## 5. Mount the component in all four places

- [ ] 5.1 Category — `view/adminhtml/ui_component/category_form.xml`
- [ ] 5.2 Product — form modifier pointing the dynamically-rendered EAV attribute at the component
- [ ] 5.3 `default_tic` — `frontend_model` block hosting the component via `x-magento-init` + `Magento_Ui/js/core/app`
- [ ] 5.4 `shipping_tic` — same block, different field metadata
- [ ] 5.5 Verify all four render the identical control, and that no field gained a validator or lost its freeform behaviour

## 6. Unit tests

- [ ] 6.1 REST backend: request shape, response mapping, empty results, 429 → unavailable
- [ ] 6.2 SOAP backend: local matching, ranking, exact-code-first
- [ ] 6.3 Dispatcher: `api_type` decides the backend, resolved for the passed store; mixed-fleet case
- [ ] 6.4 Every failure path yields "unavailable", never an exception escaping to the caller
- [ ] 6.5 Caching: v1 list fetched once across repeated searches; v3 repeat query served from cache
- [ ] 6.6 Controller: each of the three ACL resources grants access; a session with none is refused
- [ ] 6.7 Controller: no credential value appears in any response payload
- [ ] 6.8 Verify added test code runs on PHPUnit 9.5, 10.5 and 12.5 (local is 12.5 only — check by inspection)

## 7. Quality gates

- [ ] 7.1 `make phpstan` clean at level 5 with no new baseline entries
- [ ] 7.2 `make lint` clean on all touched files
- [ ] 7.3 Full unit suite green

## 8. Empirical verification

- [ ] 8.1 Resolve the lookup service through real DI on the dev stack and confirm both backends return sane results for a known query
- [ ] 8.2 Confirm the live v3 search returns the expected shape (verified once already on 2026-08-10: 779 TICs from `GetTICs`, `ResponseType: 3`)
- [ ] 8.3 Load each of the four fields in a real admin and confirm the control renders, searches, and saves — the product form modifier and the config-form component host are the two mounts most likely to fail silently

## 9. Documentation

- [ ] 9.1 CHANGELOG entry describing TIC search, naming both backends
- [ ] 9.2 State in the entry that results differ between v1 and v3 by design, so support does not read it as a defect

## 10. Wider test coverage (propose, do not assume)

- [ ] 10.1 Propose to the maintainer: an integration test for the DI-wired dispatcher against a live install, and e2e coverage on the product form (the version-sensitive modifier) plus one config field — implement only once confirmed
