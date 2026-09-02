# Filing your returns

Filing is where all of this ends up. The extension's job is to make sure that
what TaxCloud holds at the end of a period is an accurate record of what you
actually sold — everything else follows from that.

## What TaxCloud holds

Over a period, your account accumulates:

- **Every captured order** — when it was placed, where it shipped, what was on
  it, and the tax charged. See [Capture](capture.md).
- **Every refund and reversal** — credit memos and cancelled unpaid orders,
  reducing what is owed. See [Refunds](refunds.md) and
  [Cancellations](cancellations.md).
- **Every exemption applied** — which order, under which certificate.

A return is built from that. Which is the whole reason the extension bothers to
report orders at all, rather than just calculating a number at checkout.

## What you do

Filing itself is a TaxCloud service, arranged with TaxCloud — it is not
something you switch on in Magento. Depending on your plan you either:

- **Have TaxCloud file for you**, from the transactions in your account; or
- **File yourself**, using the reports in your TaxCloud dashboard.

Either way, the extension's part is the same: get the transactions in there
correctly and on time.

## Before the end of a period

A short check is worth the time:

**Do the totals broadly match?** Compare your TaxCloud transactions for the
period against your Magento sales for the same period. They should not be far
apart. A large gap means orders are not arriving.

**Any failures in the log?** [Reading the log](logs.md) — look for failed
captures and failed reversals. A failed capture is a sale missing from your
filing; a failed reversal is a refund missing from it.

**Any orders stuck before their capture trigger?** With capture set to *On
payment* or *On shipment*, orders that never got there were never reported. That
is usually correct — but a batch of orders invoiced late, or shipments not
recorded in Magento, will show up here as a gap.

**Do the exemptions look right?** An unexpected number of untaxed orders is
worth understanding before you file, not after.

!!! warning "The extension cannot tell you what you failed to report"
    It reports what it is asked to and logs what fails. It does not reconcile
    your TaxCloud account against your Magento orders. If a capture failed
    silently three weeks ago, nothing will surface it at filing time unless you
    look. Build the check into your month-end.

## Common causes of a gap

| Gap | Likely cause |
|---|---|
| Orders in Magento, not in TaxCloud | Failed captures, or a capture trigger they never reached |
| Refunds in Magento, not in TaxCloud | Failed reversals, or refunds issued outside Magento |
| Nothing in TaxCloud at all | Calculations-only mode, or TaxCloud not enabled on that store |
| Orders from one store only | Settings applied at the wrong scope — see [Multi-store setups](multi-store.md) |

## Questions about the return itself

What you owe, which states, whether you have nexus somewhere new — those are
questions for TaxCloud, not for this extension. They are a compliance service
with people who do this professionally; ask them.
