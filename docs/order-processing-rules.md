# Order processing rules

Not every order should be handled by TaxCloud the same way. Orders imported
from Amazon or eBay have their tax collected and paid by the marketplace.
Wholesale orders may be invoiced from an accounting system that already reports
them to TaxCloud. Order processing rules let you decide, order by order, how
much of TaxCloud each one uses.

*Stores → Taxes →* **TaxCloud Order Rules**

## What a rule can do

Each rule has one action:

| Action | Tax at checkout | Reported to TaxCloud |
|---|---|---|
| **Report to TaxCloud** | Calculated by TaxCloud | Yes — captured, refunded and cancelled as normal |
| **Calculate only** | Calculated by TaxCloud | No — never captured, refunded or cancelled |
| **Skip TaxCloud** | Calculated by your Magento tax rules, as if TaxCloud were off | No |

*Reported* means the sale is recorded in your TaxCloud account, where it is
filed — see [Capture](capture.md).

**Skip TaxCloud** turns off everything TaxCloud does for the order, not just the
tax amount: address verification, [exemption certificates](exemptions-setup.md),
the [Colorado Retail Delivery Fee](colorado-retail-delivery-fee.md) and
[Canadian tax](canadian-tax.md). The order is taxed by the rules in
*Stores → Taxes → Tax Rules*. If you have none, it is not taxed at all.

!!! warning "A sale that is not reported is not filed"
    TaxCloud files returns from the sales it holds. Use **Calculate only** or
    **Skip TaxCloud** only for orders whose tax someone else reports and pays —
    a marketplace that collects as marketplace facilitator, or an accounting
    system connected to TaxCloud. Otherwise the tax you collected on them goes
    unfiled.

## Which orders a rule applies to

A rule has five filters. Leave a filter empty to match every order.

| Filter | Matches orders… |
|---|---|
| **Store views** | placed in one of the selected store views |
| **Customer groups** | from a customer in one of the selected groups (*NOT LOGGED IN* is guests) |
| **Payment methods** | paid with one of the selected methods |
| **Shipping methods** | shipped with one of the selected methods |
| **Order number starts with** | whose order number starts with one of the prefixes, such as `AMZ-`. Letter case is ignored |

When you select several values in one filter, an order needs to match only one
of them. When you fill in several filters, an order has to match all of them.
For example, a rule with customer group *Wholesale* and shipping method
*Freight* applies only to wholesale orders shipped by freight.

The payment and shipping lists include methods that are not offered at
checkout. Marketplace importers usually give their orders a payment or shipping
method of their own — for example *M2E Pro Payment* — and that is often the
simplest way to recognise imported orders.

## The order of the rules

Rules are checked from the top of the list down. The first active rule that
matches an order decides what happens to it; the rules below it are not
consulted. Drag a rule by its handle to move it.

Orders that match no rule follow the store view's
[Report orders to TaxCloud](settings.md#report-orders-to-taxcloud) setting:
reported if it is *Yes*, calculated only if it is *No*.

Because the first match wins, you can make an exception to a broad rule by
putting a narrower one above it:

| # | Customer group | Payment method | Action |
|---|---|---|---|
| 1 | Wholesale | Check / Money order | Report to TaxCloud |
| 2 | Wholesale | *(any)* | Calculate only |

Wholesale orders are left to your accounting system — except those paid by
check, which you invoice from Magento and want reported.

A **Report to TaxCloud** rule also works the other way round: in a store view
set not to report, it sends the orders it matches.

## When the decision is made

Each order's outcome is decided once, when the order is placed, and kept with
the order. Editing, reordering or deleting rules later changes what happens to
new orders only. An order placed before you changed a rule is still captured,
refunded and cancelled the way it was decided.

**Skip TaxCloud is decided earlier, at checkout**, because the tax has to be
calculated before the order exists. Two things follow from that:

- **A Skip rule cannot filter on the order number.** The order has no number
  yet when tax is calculated. The rule form refuses to save one, and a rule with
  an order number prefix cannot stop a Skip rule below it from applying at
  checkout.
- **Storefront customers can see tax change.** Magento shows tax before the
  customer picks a payment method. If a Skip rule filters on a payment or
  shipping method, the tax changes when the customer chooses that method. Skip
  rules suit imported orders best, which arrive with their methods already set.

## Seeing why an order was not reported

When a rule decides that an order is **Calculate only** or **Skip TaxCloud**,
the order gets a comment in its history — not shown to the customer — naming
the rule and what it matched:

> TaxCloud: Tax was calculated by TaxCloud, but this order is not reported to
> TaxCloud. Order rule "Wholesale" matched customer group Wholesale, shipping
> method Freight - Truck (freight_truck).

Orders decided by the store view setting get no comment, so a store view that
does not report does not fill every order's history with the same line. The
[diagnostics file](diagnostics.md) for an order also shows its outcome and the
rule that decided it.

## Examples

**Amazon orders imported with M2E Pro.** Amazon collects and pays the tax on
these as marketplace facilitator, and sets the tax amount itself.

| Payment methods | Action |
|---|---|
| M2E Pro Payment | Skip TaxCloud |

**Orders imported with a numbering prefix.** Your importer numbers eBay orders
`EBAY-…` and you only need them kept out of TaxCloud.

| Order number starts with | Action |
|---|---|
| `EBAY-` | Calculate only |

**Wholesale invoiced from your ERP.** The ERP reports wholesale sales to
TaxCloud itself.

| Customer groups | Action |
|---|---|
| Wholesale | Calculate only |

## Who can change rules

Rules need the **TaxCloud Order Rules** permission (*System → Permissions →
User Roles → Role Resources*, under *Stores → Taxes*). Grant it with care: a
rule decides which sales reach your tax filings.
