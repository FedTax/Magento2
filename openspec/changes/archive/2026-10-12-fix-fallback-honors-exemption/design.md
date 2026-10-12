## Context

Both lookup paths (`Model/Api.php` for v1 SOAP, `Model/Gateway/Rest/RestGateway.php` for v3 REST) resolve the customer's certificate *before* calling TaxCloud and put its id into the request. If the call fails, they hand the cart to `MagentoTaxFallback` (fallback on) or return the zero result (fallback off). `MagentoTaxFallback` drives Magento's native tax calculation, which knows only the customer tax class — the resolved certificate id is dropped on the floor. REST funnels both failure branches through a private `lookupFallback()`; SOAP repeats the decision inline in two branches (exception, and non-OK `ResponseType`).

`Observer/Sales/RecordCertificate` runs on `sales_model_service_quote_submit_before`, after the quote has been converted, and re-resolves the certificate to write `taxcloud_certificate_id` / `taxcloud_certificate_snapshot` onto the order. It never looks at the order's tax, so a fallback-taxed order still gets the record.

Field case (CXRE-232): exempt customer, order shows the TaxCloud Exemption block, Magento charged tax. On 1.4.0/1.5.0 the HTTP 100 misread made large-cart lookups fail routinely, so this was reachable on every big exempt order; on 1.5.1 it remains reachable on any genuine outage, timeout or error response.

## Goals / Non-Goals

**Goals:**
- A failed lookup for a cart with an applied certificate yields no tax on both transports, independent of the fallback setting, with a Basic-visible log line naming the certificate.
- An order's certificate record is never written onto an order that carries tax.

**Non-Goals:**
- Certificate awareness inside `MagentoTaxFallback`, customer-tax-class mapping, or fallback shipping tax.
- Exempting when certificates cannot be retrieved (fail closed stays).
- Repairing existing orders.

## Decisions

### 1. Decide at the gateway's failure point, not inside the fallback

The gateway already holds `$certificateID` when a lookup fails; the fallback engine does not and should not. Each gateway gets one private `lookupFallback($itemsByType, $shippingAssignment, $quote, $storeId, array $zeroResult, ?string $certificateID)`:

1. `$certificateID !== null` → warn `TaxCloud lookup failed; certificate <id> is applied, so charging no tax instead of Magento tax rates` and return `$zeroResult`.
2. otherwise, unchanged: fallback enabled → Magento rates; else `$zeroResult`.

REST changes its existing helper's signature and both call sites. SOAP's two inline branches collapse into the same helper (keeping each branch's existing error log before the call), so the two transports cannot drift again.

*Alternative considered:* pass the certificate into `MagentoTaxFallback::calculate()` and short-circuit there. Rejected — it would make a class whose contract is "Magento's rates" sometimes not return Magento's rates, and the fallback-disabled branch would still need its own check.

*Alternative considered:* a shared collaborator class for the failure policy. Rejected for a patch release: it adds DI wiring (and a stale-compiled-DI risk on merchants who skip `setup:di:compile`) for two four-line methods. Both helpers carry the same message text and are pinned by mirrored unit tests.

### 2. The zero result is the existing one

`[product => [], shipping => 0]` is already what fallback-disabled returns, and `Model/Tax::collect()` reads absent product amounts as `0`. No new result shape. It is not cached (failed lookups never are), so the next totals collection retries TaxCloud — the desired behavior once the outage ends.

### 3. The order record requires an untaxed order

`RecordCertificate` resolves as today; when a certificate comes back, it checks the converted order's tax first. If `round(max(tax_amount, base_tax_amount), 4) > 0`, it skips `OrderCertificateRecord::record()` and warns `Not recording certificate <id> on order <increment id>: the order carries tax of <amount>, so it was not exempted`. The check reads the order, so it is inherently scoped to the order's store; the logger is the existing `GatewayLogger` (warning passes Basic).

Checking both store and base amounts guards a store-currency rounding to zero while base does not. The Colorado Retail Delivery Fee is its own total and never part of `tax_amount`, so an exempt Colorado order with the fee still records its certificate.

*Alternative considered:* stash the certificate actually used by the last lookup on the quote and record that. Rejected — the class docblock already explains the quote has no column to carry it across requests, and the tax-amount check expresses the invariant the record exists to prove ("this sale was untaxed") directly.

## Risks / Trade-offs

- [A merchant with fallback on now collects nothing from an exempt customer during an outage, where before they over-collected] → That is the correct outcome; the certificate was already validated against the destination, and the warning names it for audit.
- [The record check could skip a genuinely exempt order whose tax is non-zero for another reason (a third-party module adding tax)] → Such an order did charge the customer tax, so recording an exemption would be false; the warning makes the case visible.
- [SOAP refactor touches a path with no recent changes] → Mirrored unit tests cover both SOAP failure branches with and without a certificate, and with fallback on and off.

## Migration Plan

Patch release 1.5.2 from `v1.5.1`, then the same commit cherry-picked (`-x`) onto `1.6.0`. No schema, config or DI changes. The version bump in `etc/module.xml` means merchants run `bin/magento setup:upgrade` as for any update; `setup:di:compile` is unaffected by this change (constructor signatures are unchanged). Rollback is reinstalling 1.5.1.

## Open Questions

None.
