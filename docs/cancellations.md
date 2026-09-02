# Cancellations

An order that is cancelled before anyone pays for it should not appear in your
sales tax filing. The extension handles that automatically.

## What happens

When you cancel an order that has **no invoice**, the extension reverses it in
TaxCloud, so the sale is not reported and you do not remit tax on money you
never took.

This matters most with offline and deferred payment: check or money order, bank
transfer, cash on delivery, purchase orders — anywhere an order can exist for a
while before payment, and be cancelled in between.

## The conditions

The reversal happens when all of these are true:

1. **The whole order is cancelled**, not part of it.
2. **The order has no invoices.** An invoiced order that is being refunded goes
   through the [credit memo flow](refunds.md) instead.
3. **The order had actually been reported to TaxCloud.** If it never was — for
   example, capture is set to *On payment* and payment never came — there is
   nothing to reverse.

The extension records at capture time whether an order reached TaxCloud, so
cancelling does not require asking TaxCloud again.

## Cancelling versus refunding

| Situation | What to do | What reaches TaxCloud |
|---|---|---|
| Order never invoiced | Cancel it | The sale is reversed |
| Order invoiced and paid, money going back | Credit memo | A refund is reported |
| Order invoiced, then cancelled | Credit memo | A refund is reported |

If in doubt: has anyone been invoiced? If yes, it is a refund.

## Orders placed before this behaviour existed

Very old orders — placed before the extension started recording capture on the
order — do not carry that record. For those, the extension asks TaxCloud whether
the order was ever captured.

That query uses a TaxCloud API that is licence-gated. If your account does not
have access to it, the extension cannot confirm the order was captured, and it
treats it as not captured — meaning **no reversal is sent**.

!!! warning "Cancelling a very old, unpaid order may not reverse it"
    If you cancel an order from before this record existed and your TaxCloud
    plan does not include the order-details API, the sale stays reported. The
    extension does this deliberately: an unnecessary reversal would understate
    what you owe, which is the worse error. It is written to
    [the log](logs.md).

    Check your TaxCloud dashboard after cancelling a legacy order, and reverse
    it there by hand if it is still showing. Recent orders are unaffected.

## Partial cancellation

There is no partial cancellation. Cancelling part of an order is not something
Magento does — remove the items and refund instead, if the order is invoiced.

## Calculations-only mode

With [calculations-only
mode](settings.md#only-do-tax-calculations-without-further-taxcloud-integration)
on, nothing is reported to TaxCloud and nothing is reversed. Cancellations are
between you and whichever other system reports your sales.
