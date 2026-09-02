# Choosing your API

TaxCloud has two generations of API, and the extension supports both. The
**API Type** setting picks which one your store uses.

## Which one am I on?

*Stores → Configuration → Sales → Tax →* **TaxCloud Settings** → **API Type**.

- **New installations** default to **V3 REST**.
- **Stores upgraded from an older version** that already had V1 credentials are
  left on **V1 SOAP (legacy)**. Upgrading never moves you — that is deliberate,
  so a routine version bump cannot change how your store transacts.

## Which one should I use?

**V3 REST**, unless you have a reason not to. It is TaxCloud's current API and
where new capability lands. V1 SOAP is fully supported and is not being switched
off underneath you, but it is the older path.

Reasons to stay on V1 SOAP for now:

- You have custom code or a third-party extension hooked into the extension's
  events. Those hooks are API-specific and need updating — see
  [Extending the extension](extending.md).
- You are in the middle of a busy trading period. There is one sharp edge when
  switching, below, and it is easier to handle when order volume is low.

## What is different

Day to day, nothing. The same tax is calculated, the same orders are recorded,
the same refunds are reversed. The differences that can bite:

**Orders belong to the API that created them.** An order captured over V1 SOAP
cannot be refunded over V3 REST, and the reverse. If you refund a pre-switch
order after switching, TaxCloud answers "order not found", the reversal fails,
and it is written to the log. Nothing is double-counted, but the refund is not
reflected in TaxCloud until you deal with it.

**Refund amounts are worked out differently.** On V3 REST the extension sends
quantities and TaxCloud derives the amounts from the order it already holds. On
V1 SOAP the extension sends the amounts. The result should match; the mechanism
differs.

**Credentials differ.** Each API type has its own fields, and the ones you are
not using are hidden. Your V1 API ID and API Key keep working on V3 REST — the
extension exchanges them automatically — so you do not have to generate anything
new to switch.

## Switching a live store

1. **Pick a quiet window.** Ideally when you have no orders still awaiting a
   refund.
2. **Enter the new credentials first.** Set **API Type** to the new value, fill
   in the fields that appear, and click **Verify Credentials** before saving
   anything else.
3. **Save, then place a test order** and confirm it appears in your TaxCloud
   dashboard.
4. **Watch the log** for a few days — *[Reading the log](logs.md)* — for failed
   reversals on older orders.

!!! tip "Refunding an order from before the switch"
    Set **API Type** back to the old value, issue the credit memo, then set it
    forward again. The order is reversed against the API that created it. Since
    the setting is per store view, you can do this without touching your other
    stores.

Switching back is equally safe. No data is migrated in either direction, and
nothing in your TaxCloud account is rewritten.

## Trying one store view first

**API Type** is store-scoped, so you can move a single store view to V3 REST and
leave the rest on V1 SOAP while you watch it. See
[Multi-store setups](multi-store.md).
