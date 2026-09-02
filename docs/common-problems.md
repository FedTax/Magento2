# Common problems

Symptoms first, in rough order of how often they come up.

## No tax is charged at all

**Is TaxCloud enabled?** *Stores → Configuration → Sales → Tax →* **TaxCloud
Settings** → **Enabled**. Check you are looking at the right store view — see
[Multi-store setups](multi-store.md).

**Is the origin address complete?** Missing or malformed origin ZIP stops the
lookup happening. It needs the full ZIP+4.

**Are the credentials valid?** Click **Verify Credentials**. Rejected
credentials mean every lookup fails.

**Is the destination in the US?** Orders shipping elsewhere are left to Magento.

**Is the product's tax class `None`?** Those are never sent to TaxCloud. See
[Assigning TICs](assigning-tics.md).

**Is the customer exempt?** Check whether a certificate was applied — see
[How an order becomes exempt](how-an-order-becomes-exempt.md).

**Did the call fail?** With [fallback](settings.md#fallback-to-magento-tax-rates)
off, a failed lookup means no tax. [The log](logs.md) will say.

**Is another tax extension installed?** Only one extension can calculate tax.
If another one has taken over, TaxCloud still shows as enabled and its
credentials still verify, but it is not calculating. See
[Another extension is calculating tax](extension-conflicts.md).

## No tax on the cart page

Expected. Until a shopper has entered a shipping address there is nothing to
calculate against. Tax appears once they reach checkout and give an address.
See [At checkout](checkout.md).

## The tax amount looks wrong

**Check the TIC first.** This is the usual answer. A product taxed as general
goods when it is clothing or food will be confidently wrong. See
[How TICs work](tics.md).

**Check the origin ZIP+4.** A five-digit ZIP can span several jurisdictions.

**Check the shipping TIC.** `11010` and `11000` are taxed differently in several
states — see [Shipping and handling](shipping-and-handling.md).

**Is it cached?** A figure from before your change may still be being served.
[Flush the TaxCloud cache](clearing-the-cache.md) and try again.

**Is fallback masking the problem?** With [fallback](settings.md#fallback-to-magento-tax-rates)
on, a failed lookup silently produces Magento's own rate instead of TaxCloud's.
The number looks plausible and is not TaxCloud's. Check the log.

## Percentages differ slightly from TaxCloud's dashboard

Normal — rounding. The **amounts** should match exactly. If the amounts differ,
that is worth investigating.

## Credentials are rejected

**Re-copy them.** A trailing space or a truncated paste is the usual cause.

**Right API type?** V1 credentials in the V3 fields will not work. The fields
change with **API Type**.

**Right connection?** *Integrations → Custom API* in TaxCloud. A well-formed
Connection ID that TaxCloud does not recognise means the wrong connection.

**Right scope?** Credentials saved at Default Config while you are testing a
specific store view — or the reverse — is a common mix-up.

## "Could not reach TaxCloud"

Not a credentials problem: your server cannot get out to TaxCloud. Ask your host
about outbound HTTPS, a firewall, or a proxy. It affects everything the
extension does.

## Orders are missing from TaxCloud

**Which capture trigger?** With *On payment* or *On shipment*, orders that never
reached that point were never reported. See [Capture](capture.md).

**Calculations-only mode?** Then nothing is ever reported, by design.

**Did capture fail?** Check [the log](logs.md) around the order.

**Which store?** Orders from a store view with different credentials go to a
different TaxCloud account.

## A refund is not showing in TaxCloud

**Was there a credit memo?** Refunding through your payment provider alone does
not reach TaxCloud. Record it as a credit memo.

**Was the order captured on a different API?** An order created under V1 SOAP
cannot be refunded under V3 REST, or vice versa. See
[Refunds](refunds.md#refunding-an-order-from-before-an-api-switch).

**Was the order ever captured?** Nothing to reverse if it was not.

## An exempt customer was charged tax

Work through the checklist in
[How an order becomes exempt](how-an-order-becomes-exempt.md). The most common
causes are: they were not signed in, the certificate is listed but not attached,
or the certificate does not cover the destination state.

## A certificate change is not taking effect

Certificates are cached for an hour. Use **Refresh from TaxCloud** in the
customer's certificate panel, or
[flush the TaxCloud cache](clearing-the-cache.md).

## Checkout has become slow

**Is the TaxCloud cache type disabled?** Then every page load calls TaxCloud.
See [Clearing the TaxCloud cache](clearing-the-cache.md).

**Is Cache Lifetime set to 0?** Same effect, from the settings side.

**Is TaxCloud responding slowly?** Advanced logging records per-call timing.
[API Timeout](settings.md#api-timeout-seconds) caps how long a shopper waits.

## The TaxCloud settings section is missing

The extension is not installed or not enabled. Check with
`bin/magento module:status Taxcloud_Magento2` — see
[Installing the extension](installing.md).

## The exemption certificates tab is missing

Either [exemptions are off](exemptions-setup.md), or your admin role lacks the
**TaxCloud Exemption Certificates** permission.

## Still stuck

Gather this before contacting TaxCloud support:

- The order number and roughly when it happened
- What you expected and what you got
- A log extract with **Logging** set to `Enable - Advanced` while reproducing it
  — see [Reading the log](logs.md)
- Your API Type, and whether the store is on a test or production connection
