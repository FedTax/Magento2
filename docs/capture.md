# Capture

Calculating tax and *reporting the sale* are two different things. Tax is
calculated when the shopper checks out. Reporting — TaxCloud calls it capture —
is when the completed order is recorded in your TaxCloud account as a
transaction that can be filed.

## The setting

*Stores → Configuration → Sales → Tax →* **TaxCloud Settings** →
**Capture in TaxCloud**

| Option | The order is reported | Suits |
|---|---|---|
| On order creation | As soon as the order is placed | Most stores; the default |
| On payment | When an invoice is paid | Stores with cancellations, fraud, or offline payment |
| On shipment | When a shipment is created | Stores that only want to report goods that shipped |

This is about *when*, never about *how much*. The whole order is reported
whichever you choose — there is no partial capture.

## Choosing

**On order creation** is simplest. The sale is in TaxCloud immediately, and
cancelled orders are reversed when they are cancelled. The downside is churn:
orders that were never paid for still arrive, then get reversed.

**On payment** avoids that. Nothing reaches TaxCloud until money does. If you
take payment by check, bank transfer or purchase order — or if you get a
meaningful number of cancellations — this is usually the better choice.

**On shipment** is the most conservative: report only what actually went out.
Consider it if you take orders long before you fulfil them, or backorder
frequently.

!!! note "With online payment, creation and payment are nearly the same moment"
    Card payments are typically authorised and invoiced within seconds of the
    order. The difference between the first two options matters most for offline
    and deferred payment methods.

## Changing it later

Safe to change at any time. It affects orders from that point on; orders already
reported stay reported.

Changing from **On order creation** to **On payment** will not retrospectively
un-report anything.

## What is recorded

When an order is captured, the extension notes that on the order itself. That
local record is what lets a later cancellation know whether there is anything to
reverse, without asking TaxCloud again.

Capture is written to [the log](logs.md), so you can confirm exactly when an
order was reported.

## When capture does not happen

**Calculations-only mode.** If [Only do tax calculations without further TaxCloud
integration](settings.md#only-do-tax-calculations-without-further-taxcloud-integration)
is on, nothing is ever reported and the Capture setting disappears — there is
nothing left for it to schedule. Use it only when another system is reporting
the same sales to TaxCloud.

**The order never reached the trigger.** An order set to capture on payment that
is cancelled before invoicing is never reported. That is the point of the
setting.

**The call failed.** A TaxCloud outage at the moment of capture is written to
the log. The sale is in Magento but not in TaxCloud, so it will not be in a
filing — worth checking the log periodically if you have had connectivity
trouble.

!!! warning "An order that is never captured is never filed"
    TaxCloud files from the transactions it holds. You collected the tax from
    the customer either way, so an order missing from TaxCloud is tax you are
    holding with no return recording it.

## Next

- [Refunds and credit memos](refunds.md) — reversing part or all of a sale
- [Cancellations](cancellations.md) — orders that never complete
