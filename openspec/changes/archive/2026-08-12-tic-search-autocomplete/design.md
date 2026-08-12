## Context

See proposal.md — Why. Three facts about the existing code shape the approach.

**The four fields are not one kind of thing.** Product and category TICs are EAV
attributes rendered through Magento UI form components; the default and shipping
TICs are `system.xml` fields rendered by the old configuration form. "One
component in all four places" is therefore the hard part of this change, not the
search itself.

| Field | Where it lives | Rendering |
|---|---|---|
| Product `taxcloud_tic` | `InstallTaxcloudData` (EAV, **global** scope) | product form UI component |
| Category `taxcloud_tic` | `AddCategoryTicAttribute` (EAV, store scope) | `category_form.xml` UI component |
| `default_tic` | `system.xml:144` (default `00000`) | `system.xml` config form |
| `shipping_tic` | `system.xml:154` (default `11010`) | `system.xml` config form |

**The two sources differ in kind, not just in endpoint.**

| | v1 `GetTICs` | v3 `POST /tax/tic/search` |
|---|---|---|
| Shape | whole list, 779 entries, ~58 KB | query required, ranked results |
| Per TIC | `TICID`, `Description` (avg 43 chars) | `ticId`, `label`, `naturalLabel`, `description`, `documentation`, `rank`, `score` |
| Paging | none | `limit` (1–100, default 10), `cursor` |
| Rate limit | undocumented | **429 documented** |
| Connection scope | n/a | account-level — no `/connections/{id}` prefix |

Measured against the live API on 2026-08-10: 779 TICs, `ResponseType: 3`.

**Existing seams this reuses.** `RestClient::request()` already supports
account-level calls via `connectionScoped: false`. `Model\Cache\Type\Taxcloud`
is a registered, flushable cache type. `Controller/Adminhtml/Connection/Test`
is the established thin-controller pattern on the existing `taxcloud` admin
route.

## Goals / Non-Goals

**Goals:**
- One implementation of the interaction, mounted four times — not four
  implementations that currently agree.
- Search that cannot fail a save, a form load, or a tax calculation.
- Per-store dispatch that reuses the existing API-type resolution rather than
  inventing a parallel one.

**Non-Goals:**
- A general-purpose admin autocomplete widget for other modules.
- Any change to how TIC values are stored, validated, or resolved at
  calculation time.
- Serving lookup to anything other than the admin (no storefront, no webapi).

## Decisions

### D1: One shared behaviour module, two thin adapters, four mounts

**Revised during implementation.** This decision originally read "one component
extending `Magento_Ui/js/form/element/abstract`, hosted three ways, with the
config fields booting it via `x-magento-init` + `Magento_Ui/js/core/app`". That
is not possible: `abstract` hard-wires

```
imports: { value: '${ $.provider }:${ $.dataScope }' }
```

plus three `listens` on `${ $.provider }`. The configuration form is not a UI
form and has no data provider, so those resolve to nothing and the value never
binds. The trick works for *rendering* a UI component in config; it does not
give that component a value.

What ships instead keeps the intent — one implementation of the interaction —
while respecting the platform:

- `js/tic/behaviour.js` — every observable, state transition, request, ranking
  and piece of wording. This is the component in all but name.
- `js/form/element/tic.js` — extends `abstract`, mixes in the behaviour. Value
  comes from the form's data provider, so the label, scope label and
  "Use Default Value" chrome keep working. Serves **category** (declared in
  `category_form.xml`) and **product** (attached by a form modifier, since the
  product form generates its attribute fields from EAV metadata at runtime and
  has no XML file to edit).
- `js/config/tic.js` — extends `uiElement`, mixes in the same behaviour, and
  owns its `value` observable directly. Serves **`default_tic`** and
  **`shipping_tic`** through one `frontend_model` block; the control writes
  through to the real config input, so the configuration form posts the setting
  exactly as before.

The adapters exist solely because Magento has two different ways for a field to
obtain a value. They contain no behaviour of their own — roughly 25 lines each,
almost all of it `defaults`.

*Alternative considered:* a jQuery UI widget attached to a plain input,
initialised three ways. Rejected — the category and product forms are Knockout,
so a jQuery widget would fight the UI component lifecycle we get for free by
extending `abstract`.

*Alternative considered:* four separate implementations sharing only CSS.
Rejected outright — it is the thing the change exists to avoid.

*Alternative considered (post-revision):* give the config fields a minimal fake
data provider so a single `abstract`-based component could serve all four.
Rejected — a stub provider that exists only to satisfy an import is more
machinery, and more to break on upgrade, than a 25-line adapter.

### D2: A single lookup service, dispatched by `api_type`

`TicLookupInterface::search(string $query, $store): TicSuggestion[]`, with two
implementations behind a store-aware dispatcher — the same shape as
`Model\Gateway\Router`, and deliberately so: the switcher story established that
`api_type` decides transport for everything, and a picker that ignored it would
be the exception that erodes the rule.

The REST implementation calls `POST /tax/tic/search` (account-level). The SOAP
implementation matches locally against the cached `GetTICs` list.

`TicSuggestion` normalises both sources to `{code, label, detail, score}`;
`detail` and `score` are absent for SOAP. The UI renders whatever is present, so
one template serves both.

*Alternative considered:* fetching the v1 list even for REST stores to get a
uniform experience. Rejected — it makes every REST store pay a SOAP call for a
worse result set, and requires v1 credentials that a fresh v3 store will not
have.

### D3: Two caches, different reasons

- **The v1 list**: one entry per store's credentials, daily TTL, in the module's
  cache type. 58 KB is small enough to hold whole and search in PHP; this is
  what makes v1 lookup network-free per keystroke.
- **v3 query results**: keyed by normalised query + store, short TTL. Not for
  bulk — purely to blunt the documented 429 when an administrator retypes or
  backspaces through a term.

Both go through `Model\Cache\Type\Taxcloud`, so `bin/magento cache:clean
taxcloud` already clears them and no new cache type is registered.

*Alternative considered:* caching the v1 list in `core_config_data` or a table.
Rejected — a cache type is flushable by existing operational muscle memory and
disappears cleanly if the module is removed.

### D4: Debounce and cancel in the browser, not just cache on the server

The v3 endpoint documents a 429; a per-keystroke request would earn it. The
component debounces (~250 ms), cancels the in-flight request when the query
changes, and requires a minimum query length before searching at all.

A 429 that does happen is surfaced as the ordinary "lookup unavailable" state —
never as an error against the value the administrator has typed.

### D5: The endpoint's ACL must cover catalog roles, not just tax config

`Controller/Adminhtml/Connection/Test` guards with
`ADMIN_RESOURCE = 'Magento_Tax::config_tax'`. Copying that here would be a
quiet bug: a catalog manager with `Magento_Catalog::products` but no tax-config
permission edits product TICs legitimately, and would get a search box that
silently returns nothing.

So the lookup controller overrides `_isAllowed()` to admit a session holding
**any** of `Magento_Tax::config_tax`, `Magento_Catalog::products`, or
`Magento_Catalog::categories` — the three ways to legitimately reach a TIC
field — rather than a single `ADMIN_RESOURCE`.

Credentials never reach the browser: the component posts a query and receives
suggestions, and TaxCloud is contacted server-side, exactly as the connection
test already works.

### D6: Resolution and freeform storage are untouched

The component writes the same string the input wrote before. `ProductTicService`
and `CategoryTicResolver` are not modified, and no validator is added to any of
the four fields. The "not found" state is a UI affordance computed from lookup;
it has no persistence and no effect on save.

The inherit hint reads the existing resolution chain for product and category.
`shipping_tic` has no chain — it falls back only to its `config.xml` default —
so its hint says that instead of implying an inheritance that does not exist.

### D7: Store resolution differs per field, because the fields differ

The category attribute and both config fields are store-scoped, so lookup
resolves against the store being edited. The **product attribute is global
scope**, so it resolves against the default scope.

This asymmetry is inherited from the existing attribute definitions, not
introduced here. It is called out so that a multi-store merchant on mixed
transports seeing different search behaviour on the product form than the
category form reads as a known consequence rather than a defect. Changing the
product attribute's scope would alter stored data and belongs to its own change.

## Risks / Trade-offs

- **The two backends return different results for the same query** → stated in
  the spec as intended, and the one place in the v1→v3 port where a transport
  flip legitimately changes what an administrator sees. Documented in the
  CHANGELOG so support is not surprised.
- **v3 429 under fast typing** → debounce, cancellation, minimum query length,
  short-TTL result cache, and a graceful degrade if it still happens.
- **The v1 list ages for up to a day** → a newly issued TIC may be reported "not
  found" while remaining perfectly saveable, which is exactly why the warning is
  non-blocking. Flushing the cache type refreshes it on demand.
- **Product form modifier is version-sensitive** → modifiers are a public
  extension point but touch a busy core form; covered by e2e on the product
  form specifically, not only on the simpler category form. Verified working in
  a real admin during implementation.
- **Config-form UI component hosting is the least common of the mounts** → the
  one most likely to break on an upgrade; the block stays as thin as possible
  so a future fix is local. Verified working during implementation, but only
  after the value-binding problem in D1 forced a second adapter.
- **Deployment: this change adds a DI preference and a new top-level `Ui/`
  directory** → the preference needs a real `setup:di:compile`
  (`setup:upgrade --keep-generated` preserves the stale container and the
  endpoint 500s with "Cannot instantiate interface"), and a running dev install
  that symlinks module directories individually needs a manual link for `Ui/`.
  Both cost time during implementation; both are release-note material.
- **779 entries searched in PHP per request** → trivially fast at this size, but
  it is a linear scan; if the list ever grows by an order of magnitude this
  becomes an index, not a scan.

## Migration Plan

Additive and code-only. No schema change, no data patch, no new configuration.
Existing TIC values are untouched and continue to resolve exactly as before.

Rollback is a revert: the fields return to plain text inputs with their stored
values intact, since storage never changed.

## Open Questions

- Whether to surface v3's `documentation` inline in the results list or only for
  the highlighted suggestion. Deferrable: it is a template detail that changes
  no interface, no caching, and no task, and is best settled by looking at real
  result density once the component renders.
