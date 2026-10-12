## Why

When a TaxCloud lookup fails and the store falls back to Magento's own tax rates, the exemption certificate already resolved for that cart is thrown away: Magento's fallback only knows the customer tax class, so an exempt customer is charged full manual-rate tax. Separately, the order's exemption record is written by re-resolving the certificate at placement, independently of how tax was actually computed — so the same order shows the TaxCloud Exemption block *and* carries tax. A merchant reported exactly this (CXRE-232, an exempt customer charged tax while the order displayed the certificate; their ERP correctly charged none).

## What Changes

- When a lookup fails on either transport (v1 SOAP or v3 REST) and a certificate was resolved for the cart, the result SHALL be no tax — the outcome TaxCloud would have returned — instead of Magento's rates. This applies whether or not *Fallback to Magento Tax Rates* is on, and is logged at warning level naming the certificate, so Basic logs show why an order with a failed lookup carries no tax.
- Carts with no resolved certificate keep today's behavior: Magento rates with fallback on, no tax with fallback off.
- An order SHALL record a certificate only if it actually carries no tax. If a certificate resolves at placement but the order has tax on it (e.g. the certificate was attached between the last totals calculation and checkout, or certificate resolution failed during totals and succeeded at placement), the record is not written and a warning names the order and certificate. The exemption record can then never contradict the order's own totals.
- Merchant docs: the fallback setting and "How an order becomes exempt" pages describe the new behavior.
- Released as 1.5.2 (from v1.5.1) and carried forward into 1.6.0.

## Non-goals

- Making Magento's native fallback engine itself certificate-aware, or mapping certificates to Magento customer tax classes. The fix decides *before* invoking the fallback.
- Changing fail-closed resolution: if the customer's certificates cannot be retrieved, the cart is still taxed (and, with fallback on, at Magento's rates). Exempting on an unverifiable claim stays off the table.
- Correcting orders already placed. Existing orders that were taxed under fallback despite a certificate are not rewritten.
- Taxing shipping in the fallback, or any other fallback fidelity gap.
- Canadian destinations — certificates never apply to them, so nothing changes there.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `exemption-certificates`: an applied certificate survives a failed lookup (no tax rather than fallback rates); the order record requires that the order actually be untaxed.
- `rest-tax-operations`: the "failed lookup falls back per store configuration" scenario gains the exempt-cart exception.

## Store scoping

No new settings. The existing *Fallback to Magento Tax Rates* and logging gates continue to resolve against the quote's store; certificate resolution already uses the quote's store, and the order-record check uses the order's store. No ambient-store reads are introduced.

## Impact

- `Model/Gateway/Rest/RestGateway.php` — `lookupFallback()` and its two call sites.
- `Model/Api.php` — the two SOAP fallback branches in `lookupTaxes()`.
- `Observer/Sales/RecordCertificate.php` — skip and warn when the order carries tax.
- Unit tests for both gateways and the observer; `CHANGELOG.md` (1.5.2), `composer.json`/`etc/module.xml` version bump; `docs/settings.md`, `docs/how-an-order-becomes-exempt.md` (and `docs/common-problems.md` if it covers fallback/exemption).
- No API, schema, or configuration changes; the usual `setup:upgrade` after a version bump.
