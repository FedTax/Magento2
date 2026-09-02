# How TICs work

A **TIC** — Taxability Information Code — is TaxCloud's code for *what kind of
thing you are selling*. It is the single most important piece of information you
give TaxCloud, and the one most likely to be wrong.

## Why a rate is not enough

Sales tax is not one rate per place. States tax categories of goods
differently, and they disagree with each other about which categories:

- Groceries are untaxed in many states, taxed at a reduced rate in some, and
  fully taxed in others.
- Clothing is exempt in Pennsylvania, exempt below a price threshold in New
  York, and taxable in most other places.
- Prescription drugs are almost universally exempt. Over-the-counter medicine
  usually is not.
- Digital goods — an ebook, a downloaded album — are treated as a distinct
  category in many states.

The rate for an address cannot tell you any of that. TaxCloud needs to know
*what* is being sold, and the TIC is how you say it.

## What a TIC looks like

A five-digit code. Some you will meet early:

| TIC | What it covers |
|---|---|
| `00000` | General goods and services — ordinary taxable items |
| `11010` | Shipping, postage only |
| `11000` | Shipping and handling |
| `20010` | Clothing |
| `40010` | Food and food ingredients |

There are hundreds. The full list, with descriptions, is at
[taxcloud.com/tic](https://taxcloud.com/tic), and every TIC field in the Magento
admin searches it as you type.

!!! tip "Search by what you sell, not by number"
    You do not need to know the numbers. Type "candy" or "software" into a TIC
    field and pick from the matching codes, each shown with its description.

## What happens if the TIC is wrong

Nothing visible. That is what makes it dangerous — there is no error, no warning
in the log, no failed order. Tax is calculated confidently and incorrectly.

- **Too general a code on an exempt item** — you charge tax that was never due.
  Customers pay more than they should, and you remit money you collected in
  error.
- **An exempt code on a taxable item** — you charge nothing, the state still
  expects the tax, and it comes out of your margin when it is noticed.

A store where every product keeps the default `00000` is only correct if
everything you sell really is ordinary taxable goods.

## Where a TIC can be set

Three places, most specific first:

1. **On a product** — this exact item.
2. **On a category** — everything in it, inherited by products with no TIC of
   their own.
3. **The store's Default TIC** — the fallback for everything else.

The details of how one is picked, and how to set them efficiently, are in
[Assigning TICs](assigning-tics.md).

## Getting the codes right

Choosing TICs is a judgement about your catalogue, and it is worth taking
seriously:

- Start with your **Default TIC** describing the bulk of what you sell.
- Group the exceptions into categories and set the TIC once per category.
- Use product-level TICs for genuine one-offs.
- If you are unsure which code fits a product, ask TaxCloud support. They do
  this all day, and the answer is cheaper than a correction later.

Shipping has its own considerations — see
[Shipping and handling](shipping-and-handling.md).
