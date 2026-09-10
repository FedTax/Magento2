# Multi-store setups

Every TaxCloud setting can be set per website or per store view, which makes it
possible to run different stores on different configurations — and to try a
change on one store before applying it everywhere.

## How scope works

At the top left of any configuration page there is a **Store View** selector
with three levels:

| Level | Applies to |
|---|---|
| Default Config | Every store, unless something below overrides it |
| Website | Every store view in that website |
| Store View | That one store view |

To give one store view its own value: switch to it, clear the **Use Website** or
**Use system value** checkbox beside the field, enter the value, and save.

## The rule that matters

**Settings are resolved against the store the order belongs to** — not the store
you happen to be looking at.

This sounds obvious and is not, because so much TaxCloud work happens away from
the storefront. When an invoice is paid from the admin, when a scheduled task
processes a queue, when a payment provider calls back hours later — in all of
those the extension uses the settings of the store the *order* was placed on.

So an order from your US store view is reported with that store's credentials
even if an administrator on the Canadian store view is the one clicking Invoice.
You do not have to remember to switch store view before acting on an order.

## What this makes possible

**Roll out to one store at a time.** Enable TaxCloud on a single store view,
watch it, then extend it. The others keep working the way they always did.

**Run two API generations side by side.** One store view on V3 REST, another on
V1 SOAP, while you migrate — see [Choosing your API](choosing-your-api.md).

**Point a staging store somewhere else.** Give a staging store view a sandbox
endpoint and a test connection while production keeps the real ones.

**Vary tax codes by store.** The category TIC is store-view scoped, so the same
product can carry a different TIC in different stores.

**Different logging per store.** Turn on Advanced logging on one store view to
reproduce a problem, without flooding the log with everything else's traffic.

## Separate TaxCloud accounts per store

If two stores are separate legal entities with separate TaxCloud accounts, give
each store view its own credentials. Everything follows from there: each store's
orders, refunds and certificates go to its own account.

!!! warning "Check the scope selector before you save credentials"
    Entering credentials at Default Config applies them to every store that has
    not overridden them. If you meant them for one store only, switch the Store
    View selector first, then clear the inherit checkbox. Saving at the wrong
    scope is the most common way stores end up reporting to the wrong TaxCloud
    account.

## Things that are not per store

Two things are global, whatever the store view:

- **The TaxCloud cache type** — flushing it clears cached responses for all
  stores. See [Clearing the TaxCloud cache](clearing-the-cache.md).
- **The product TIC** — one value per product across every store. Use the
  [category TIC](assigning-tics.md) if you need it to differ by store view.
