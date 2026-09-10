## Why

Setting a Taxability Information Code today means knowing the number. All four
places a TIC can be set — the product attribute, the category attribute, the
store's Default TIC and its Shipping TIC — are plain text inputs with no list,
no picker and no validation. There are 779 TICs. A merchant either has the
TaxCloud site open in another tab, or guesses, and a wrong TIC is a silent tax
error that surfaces at filing time rather than at checkout.

Shipping is the sharpest case: 34 TICs mention shipping, delivery, handling or
freight, and telling `11010` ("Transportation, shipping, postage, and similar
charges") from `11001` ("Combined Shipping and Handling Charge") or `10014`
versus `10015` (shipping insurance, optional versus mandatory) is precisely the
judgement a bare number field cannot support.

The v3 API adds a semantic TIC search that makes discovery possible in-place,
and v1 has always had `GetTICs` — the full list is 779 entries in ~58 KB, small
enough to cache and search locally. So both API generations can back a picker;
neither needs the merchant to know a number.

## What Changes

- **One TIC autocomplete component, mounted in all four places.** A single
  Knockout UI element component serves the product attribute, the category
  attribute, the Default TIC config field and the Shipping TIC config field, so
  the interaction is identical wherever a TIC is set.
- **Two search backends behind one interface**, selected by the store's
  `api_type` exactly as gateway operations are:
  - REST stores → `POST /tax/tic/search` (semantic, ranked, paged)
  - SOAP stores → the `GetTICs` list, cached with a daily TTL, matched locally
- **Fields stay freeform.** The stored value remains the same string it is
  today. Search fills the field; it never constrains it.
- **A value not in the list is kept, with a non-blocking warning.** The merchant
  is told it was not found and that it will be saved exactly as entered. Saving
  is never blocked — a TIC issued after our cached list was built must not be
  rejected by our UI.
- **The selected TIC is always shown resolved.** A stored code displays its
  description beneath the field, so `40010` reads as "Candy" without a trip to
  the TaxCloud site.
- **An empty field shows what it falls back to** — the category TIC, or the
  store default. TIC resolution is a three-level cascade today with no visible
  trace. (The Shipping TIC has no cascade; it falls back only to its config
  default, so the hint states that instead.)
- **Search degrades to today's behaviour** whenever it cannot run: no
  credentials yet, invalid credentials, API unreachable, or a SOAP store whose
  list has not been fetched. The field stays a usable text input.

## Capabilities

### New Capabilities
- `tic-search`: how a TIC is discovered and set — the backends behind lookup,
  how results are ranked and presented, how freeform values survive, and how
  the feature degrades when lookup is unavailable.

### Modified Capabilities
<!-- None. Tax calculation reads the same attribute values it reads today; no
     gateway operation, routing rule, or resolution order changes. This change
     only alters how a value gets into the field. -->

## Non-goals

- **Not** changing how a TIC is stored, or the resolution cascade
  (product → category → store default). Storage stays a freeform string and
  `CategoryTicResolver` is untouched.
- **Not** making the TIC field required, validated on save, or restricted to
  known codes anywhere.
- **Not** building a browse-all-779 tree. `GetTICGroups` would support it on
  v1 but v3 has no equivalent, and a browse mode that exists on only one
  backend would break the single-pattern goal.
- **Not** back-filling, migrating or auditing TICs already stored. Existing
  values are displayed and resolved, never rewritten.
- **Not** adding TIC search to the storefront, the REST/GraphQL API, or
  anywhere outside the admin.
- **Not** promising identical results between backends — see the store-scoping
  note below.

## Store-scoping implications

The backend is chosen per store, from the same `api_type` setting that routes
gateway operations, and credentials resolve against that same store. An admin
editing a store-view-scoped field gets the lookup belonging to *that* store, not
the ambient one — the category attribute and both config fields (Default TIC and
Shipping TIC) are store-scoped, so this is a real case and not a theoretical
one.

The product attribute is global scope, so it resolves against the default
scope's configuration.

**The two backends do not return equivalent results, by design.** v3 is semantic
search over rich TIC metadata; v1 is text matching over a short description. A
query like "beans for espresso" finds Coffee on v3 and nothing on v1. This is
worth stating up front because it is the one place in the v1/v3 port where
flipping a store between transports legitimately changes what an admin sees —
unlike tax operations, where identical results are the acceptance criterion.

## Impact

Affected code:
- New: a TIC lookup service with SOAP and REST backends, dispatched by store
  `api_type`; a supporting admin controller; the shared UI component and its
  template.
- `Setup/Patch/Data/InstallTaxcloudData.php` and
  `Setup/Patch/Data/AddCategoryTicAttribute.php` — attribute rendering only;
  the attribute type, scope and stored values are unchanged.
- `view/adminhtml/ui_component/category_form.xml` — the field gains the shared
  component.
- `etc/adminhtml/system.xml` — the Default TIC and Shipping TIC fields each
  gain a `frontend_model` hosting the same component.
- `etc/di.xml`, `etc/cache.xml` — wiring and the cached TIC list.

Dependencies: the existing v3 REST client (the search endpoint is account-level,
so no connection-scoping work), the existing SOAP client, and the module's own
cache type for both the v1 list and v3 query results.

Not affected: tax calculation, the gateway interfaces, routing, credential
handling, and every stored TIC value.
