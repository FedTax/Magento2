## Why

Colorado imposes a flat Retail Delivery Fee (RDF) on every retail delivery by motor vehicle to a Colorado location containing at least one item of taxable tangible personal property. Merchants using this module currently have no way to collect it correctly: the only workaround (setting Shipping TIC = 11098) un-taxes the entire shipping charge and files it as fee revenue. Live probes of both TaxCloud transports (v1 SOAP and v3 REST), confirmed by TaxCloud support, established the actual contract: **TaxCloud does not price the fee** — TIC 11098 marks a zero-rated fee line whose amount the integration must supply; TaxCloud returns it untaxed, excludes it from discounts, and files it on the CO RDF return from the captured cart. The module must therefore implement the fee end-to-end: eligibility, amount, presentation, persistence, capture, and refunds.

## What Changes

- New store-scoped "Colorado" configuration group under the TaxCloud tax settings: enable toggle (default off), motor-vehicle shipping-method multiselect, fee amount (default 0.31, validated), and RDF TIC (default 11098).
- The tax collector evaluates RDF eligibility on every collect pass (feature enabled, CO destination, ≥1 taxable tangible item, mapped shipping method) and, when eligible, charges the configured fee.
- Both transports' lookup requests carry the fee as a discrete zero-rated line (sentinel item id, configured TIC, configured amount, quantity 1), never discounted, in the same Lookup as the rest of the cart — no extra API call.
- The lookup response handler routes the echoed fee line to a dedicated bucket; its (always zero) tax never lands on product/shipping codes and the fee never enters the Magento tax total.
- New "Colorado Retail Delivery Fee" total segment on cart, checkout, order view (storefront + admin), emails and PDFs; included in the grand total; hidden when not applicable.
- Fee amount persists quote → order → invoice → credit memo via dedicated columns, stored separately from tax, shipping and product totals.
- Order capture payloads (both transports) include the fee line so TaxCloud files it; a lookup fallback that placed an order without recording the fee is logged/flagged.
- Refunds: full return/cancellation refunds and reverses the fee in TaxCloud (driving the existing `returnCoDeliveryFeeWhenNoCartItems` flag on SOAP); partial returns never refund it.
- Guard: the module's product/shipping TIC configuration paths must not silently accept the RDF TIC (a product line carrying TIC 11098 is zero-taxed and mis-filed as fee revenue).
- Merchant documentation: new docs page for the Colorado settings group, README settings update.

## Capabilities

### New Capabilities

- `co-retail-delivery-fee`: end-to-end collection, presentation, persistence, capture and refund of the Colorado Retail Delivery Fee as a module-priced, zero-rated TIC 11098 line item.

### Modified Capabilities

<!-- None. Existing lookup, capture and refund requirements are unchanged; the fee is an additive line owned by the new capability. -->

## Impact

- **Config**: `etc/adminhtml/system.xml` (new nested group), `etc/config.xml` (defaults), `TaxcloudConfig` (new store-scoped accessors).
- **Collection**: `Model\Tax` collect path, new total collector registered in `etc/sales.xml`, checkout KO/UI component wiring, `db_schema.xml` columns on quote/order/invoice/creditmemo (+ fieldset copy), extension attributes as needed.
- **Transports**: v1 `Model/Gateway/RequestBuilder` (lookup + capture + returned params), v3 `Model/Gateway/Rest/RestRequestBuilder` (cart line items + order payload + refund grouping), `CartItemResponseHandler` routing.
- **Presentation**: layout/templates/email/PDF for the new total line, admin order view.
- **Tests**: unit coverage (eligibility, line building both transports, discount immunity, TIC guard, persistence, refund gating) green on PHPUnit 9.5/10.5/12.5; integration/e2e coverage proposed separately per test policy.
- **Docs**: new merchant-facing page + `mkdocs.yml` nav entry + README.
- **Store scoping**: every new gate and value (enable, methods, amount, TIC) resolves against the store of the entity being processed (quote/order/invoice/creditmemo), never the ambient store — checkout, admin, cron and webhook contexts all flow through entity-store reads.

## Non-goals

- Obtaining the fee amount from TaxCloud at runtime — no such API exists on either transport (verified empirically and confirmed by TaxCloud support); a TaxCloud-side feature request is tracked separately and the config-driven amount absorbs it if it ever ships.
- Auto-updating the amount on Colorado's annual July 1 rate change (merchant updates config).
- Auto-determining the merchant's small-business exemption (≤$500k prior-year CO retail sales; merchant asserts liability via the enable toggle).
- Delivery-fee equivalents for other states (Minnesota's TIC 11097 etc.) — though the design should leave them a configuration-level follow-up rather than a rebuild.
- Customer-level exemption-certificate interaction: certificates do not exempt the RDF; no new exemption behavior is added.
