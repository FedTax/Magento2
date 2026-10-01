# At checkout

What the extension does while a shopper is buying, and why tax appears when it
does.

## When tax is calculated

Tax is calculated once there is an address to calculate it against. In practice:

- **Cart page, before any address is entered** — no tax. There is no
  destination yet, so there is nothing to work out.
- **Cart page, once the shopper fills in *Estimate Shipping and Tax*** — an
  estimated tax shows in the cart totals. See *Estimated tax on the cart page*,
  below.
- **Checkout, once a shipping address and shipping method are entered** — tax is
  calculated from the full address and shown in the totals.
- **Checkout of a download-only order, once a billing address is entered** — tax
  is calculated against the billing address. Such an order has no shipping step,
  so there is no shipping address to use.
- **Back on the cart page afterwards** — tax shows, calculated from the address
  given at checkout.

Tax is recalculated whenever something that could change it changes: the
address, the shipping method, quantities, a coupon.

## Estimated tax on the cart page

The cart page's *Estimate Shipping and Tax* box asks only for a country, a
state (or province) and a ZIP (or postal code). That is enough for TaxCloud to
return the rate for that ZIP, so the cart shows an estimated tax as soon as the
shopper fills it in. You do not switch anything on for this.

The estimate is based on the ZIP alone. Most ZIPs sit inside one set of tax
jurisdictions, and there the estimate matches the tax charged at checkout. Some
ZIPs cross a city or district boundary, and there the rate depends on which side
of the line the street address falls. In ZIP 80020 in Colorado, for example,
the estimate is 8.15%, while an address in Broomfield, in the same ZIP, is
charged 7.15% at checkout. So the estimate can be a little higher or lower than
the final figure.

The tax the customer actually pays is always calculated at checkout, from the
full shipping address they enter there. An estimate is never sent to TaxCloud
as an order.

Canadian estimates work the same way once [Canadian tax](canadian-tax.md) is
turned on.

## What is sent to TaxCloud

For each calculation, the extension sends:

- **Where it ships from** — your [origin address](quick-start.md#1-set-your-origin-address).
- **Where it goes** — the customer's shipping address, or their billing address
  for an order that ships nothing. See *Digital and downloadable products*,
  below.
- **Each line item** — its SKU, its [TIC](tics.md), the price after discounts,
  and the quantity.
- **The shipping charge**, as its own line with the
  [Shipping TIC](shipping-and-handling.md).
- **The [Colorado Retail Delivery Fee](colorado-retail-delivery-fee.md)**, as
  its own line, when the order qualifies and collection is turned on. The fee
  appears as a separate "Colorado Retail Delivery Fee" row in the totals — part
  of the order total, never mixed into the tax line.
- **Who the customer is** — so their exemption certificates can be checked.
  Guests are reported under the [Guest Customer
  ID](settings.md#guest-customer-id).

TaxCloud returns the tax for each line, and Magento shows the total.

## Digital and downloadable products

Downloads, licences, virtual products and virtual gift cards are never
delivered anywhere, so there is no shipping address to tax them against. Which
address is used depends on what else is in the basket:

- **A basket of only these items** is taxed against the **billing address**.
  Magento skips the shipping step for such an order entirely, and the billing
  address is the address you hold for that buyer.
- **A basket that also contains something shippable** is taxed against the
  **shipping address** — every line, downloads included. The order goes to one
  place, is charged one rate, and is reported to TaxCloud as one sale.

You do not configure any of this and there is nothing to switch on. An order is
never split into two sales because it mixes downloads with physical goods.

!!! note "Whether a download is taxable at all is a separate question"
    Which states tax downloads, licences and subscriptions varies a great deal,
    and that is decided by the [TIC](tics.md) you assign to the product — not by
    which address the sale is taxed against. Assign digital products a TIC that
    describes what they are, exactly as you would for a physical product.

Your Magento **Tax Calculation Based On** setting (*Stores → Configuration →
Sales → Tax → Calculation Settings*) does not affect any of this. That setting
belongs to Magento's own tax tables. TaxCloud always taxes a sale where it is
delivered, and falls back to the billing address only when nothing is
delivered.

## Address verification

If [Verify Address](settings.md#verify-address) is enabled, a US shipping address
is standardised by TaxCloud first — most usefully to get the ZIP+4 from a
five-digit ZIP. A more precise address means a more precise rate, since
jurisdiction boundaries do not follow five-digit ZIPs.

Cart-page estimates are not verified: they have no street address to verify.

Turn it off if another extension already validates addresses.

## Orders that are not sent

Not every order goes to TaxCloud:

- **Destinations outside the US.** Other orders are left to Magento, with one
  exception: Canadian addresses, once [Canadian tax](canadian-tax.md) is turned
  on.
- **Invalid or unusable addresses.** An address that cannot produce a valid ZIP
  is not sent. It is recorded in [the log](logs.md).
- **Products with tax class `None`.** Deliberately excluded — and, as
  [Assigning TICs](assigning-tics.md) warns, this is rarely what you want.

## Caching

Identical requests are not re-sent. A lookup for the same cart to the same
address is served from cache for as long as [Cache
Lifetime](settings.md#cache-lifetime) allows — 24 hours by default. This keeps
checkout fast when a shopper reloads or steps back and forth.

The consequence: after changing a TIC or your origin address, an immediate test
may return the old figure. [Flush the TaxCloud
cache](clearing-the-cache.md) when testing changes.

## When TaxCloud cannot be reached

If the call fails or times out, what happens depends on [Fallback to Magento Tax
Rates](settings.md#fallback-to-magento-tax-rates):

- **Off** (the default) — no tax is applied to that order.
- **On** — Magento's own tax rules apply for that order.

Either way it is written to the log. Neither is a good outcome, which is why the
choice is worth making deliberately rather than leaving it at the default
without thinking about it.

## After the order is placed

Placing the order is not the same as reporting it to TaxCloud. When that happens
is controlled by [Capture in TaxCloud](capture.md).
