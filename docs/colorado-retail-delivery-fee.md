# Colorado Retail Delivery Fee

Colorado charges a flat **Retail Delivery Fee** on every delivery by motor
vehicle to a Colorado address that includes at least one taxable physical item.
It is one fee per order — not per item — and the state resets the amount every
July 1. The extension can collect it at checkout, show it as its own line, and
include it in the sales data TaxCloud files, so the fee ends up on your
Colorado Retail Delivery Fee return.

Collection is off until you turn it on.

## Are you liable?

You generally do **not** owe the fee if:

- your Colorado retail sales in the previous year were **$500,000 or less**, or
- you have no physical presence in Colorado and your sales there do not exceed
  **$100,000**.

The extension does not check this for you — turning the setting on is your
declaration that your store is liable. If you are unsure, ask your tax advisor
or see the
[Colorado Department of Revenue's fee page](https://tax.colorado.gov/retail-delivery-fee).

## Turning it on

Go to *Stores → Configuration → Sales → Tax →* **TaxCloud Settings** and open
the **Colorado Retail Delivery Fee** group.

| Setting | Default | What it does |
|---|---|---|
| **Collect Retail Delivery Fee** | `Disable` | The switch. While disabled, no order is ever charged the fee. |
| **Motor-Vehicle Delivery Methods** | — (none) | The shipping methods that count as delivery by motor vehicle. Only orders shipped with a selected method are charged. |
| **Fee Amount** | `0.31` | The exact amount charged per eligible order, in US dollars. |
| **Retail Delivery Fee TIC** | `11098` | How the fee line is identified to TaxCloud. Leave it alone unless TaxCloud tells you otherwise. |

All four settings follow the store view you are configuring, like every other
TaxCloud setting — see [how scope works](settings.md#how-scope-works).

Select every shipping method that puts a vehicle on the road: your carriers
(UPS, FedEx, USPS, flat rate…). Leave in-store pickup and similar non-delivery
methods unselected — orders using them are never charged, which is exactly
what Colorado's rules call for.

!!! warning "The fee amount is yours to keep current"
    Colorado resets the fee every **July 1** — check the
    [current rate](https://tax.colorado.gov/retail-delivery-fee) and update
    **Fee Amount** here when it changes. TaxCloud does not calculate or correct
    this amount: whatever you enter is exactly what your customers are charged
    and what is remitted with your return. The field refuses obviously wrong
    values (below $0.00 or above $2.00), but keeping it at the current rate is
    on you.

## What your customers see

When an order qualifies — shipped to a Colorado address by one of your selected
methods, with at least one taxable physical item in the cart — a separate line
labelled **Colorado Retail Delivery Fee** appears in the cart and checkout
totals, on the order confirmation and emails, and on printed invoices. The fee
is part of the order total but is never mixed into the sales tax line.

Orders that do not qualify show no line at all. If the customer changes their
address, shipping method, or cart so the order no longer qualifies, the fee
disappears on its own.

A customer with a tax exemption certificate still pays the fee — Colorado's
fee applies to the delivery, and a sales-tax exemption does not remove it.

## Refunds and cancellations

The fee follows the delivery, not the items:

- **Full refund or cancellation** — the fee is refunded to the customer and
  removed from what TaxCloud remits, automatically.
- **Partial refund** (the delivery happened, some items come back) — the fee is
  not refunded, matching Colorado's rules. The credit memo simply does not
  include it.

## Filing

Nothing extra to do. The fee rides along in the order data TaxCloud already
receives, identified by its TIC, and TaxCloud files the Colorado Retail
Delivery Fee return from it. See [Filing your returns](filing.md).
