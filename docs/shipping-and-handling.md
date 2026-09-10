# Shipping and handling

Shipping is taxed in some states and not others, and several distinguish
postage from handling. The extension reports your shipping charge to TaxCloud
as its own line, with its own [TIC](tics.md), so those rules can be applied.

## The setting

*Stores → Configuration → Sales → Tax →* **TaxCloud Settings** →
**Shipping TIC**

| TIC | Use it when |
|---|---|
| `11010` | You charge **postage only** — you pass through what the carrier charges |
| `11000` | You charge **shipping and handling** — your charge includes packing, materials, labour |

The default is `11010`.

## Which one describes your store

Ask what your shipping charge actually is.

If you use live carrier rates — the customer pays what UPS or USPS quotes —
that is postage, `11010`.

If you charge a flat rate, a per-item fee, or anything with a margin in it, that
almost certainly includes handling, `11000`. A "free shipping over $50" offer
where you absorb the cost does not change this: what matters is what the charge
represents when there is one.

!!! warning "The distinction is not cosmetic"
    A number of states tax handling but exempt separately stated postage.
    Declaring handling as postage under-collects in those states; declaring
    postage as handling over-collects. If you genuinely charge both and could
    separate them, ask TaxCloud how they would like it reported.

## How shipping appears on the order

The shipping charge is sent as an additional line item alongside the products,
priced at what the customer pays for shipping. TaxCloud returns tax for it the
same way as for any other line, and Magento shows it in the order totals as it
normally would.

**Discounts are accounted for.** If a promotion reduces the shipping charge, the
reduced amount is what is reported.

**Free shipping reports nothing.** With no shipping charge there is no shipping
line to tax.

## Refunds

When you refund shipping on a credit memo, the shipping line is reversed with
it. If you refund only products and leave shipping with the customer, only the
product lines are reversed. See [Refunds and credit memos](refunds.md).

## When several items ship together

Magento charges shipping once for the order, and the extension reports it as one
line with one TIC. It does not attempt to split a shipping charge across a
cart of mixed taxable and exempt goods.

Most stores never need more than this. If yours does — a cart where the split
would make a material difference, and you have a rule for how it should be
divided — that behaviour can be customised. See
[Extending the extension](extending.md), and expect to involve a developer.
