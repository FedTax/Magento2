# Tasks — add-co-retail-delivery-fee

## 1. Configuration

- [x] 1.1 Add the `colorado` group to `etc/adminhtml/system.xml` (enable toggle, motor-vehicle methods multiselect, fee amount, RDF TIC) with merchant-facing comments (liability context, annual rate change, DOR link)
- [x] 1.2 Add defaults to `etc/config.xml` (`co_rdf_enabled=0`, `co_rdf_amount=0.31`, `co_rdf_tic=11098`)
- [x] 1.3 Add backend model validating the fee amount range (0.00–2.00) on save
- [x] 1.4 Add backend model rejecting the RDF TIC on `default_tic` and `shipping_tic` saves
- [x] 1.5 Add store-scoped accessors to `TaxcloudConfig` (`isCoRdfEnabled`, `getCoRdfDeliveryMethods`, `getCoRdfAmount`, `getCoRdfTic`), all taking explicit `$store`

## 2. Fee service (eligibility + amount authority)

- [x] 2.1 Create `Model/RetailDeliveryFee/FeeService` with `isEligible(Quote, ShippingAssignment)`, `getAmount($store)`, `getTic($store)`, and the `co-rdf` sentinel constant — gates: enabled for quote's store, CO destination, ≥1 taxable tangible item, shipping method in configured list
- [x] 2.2 Unit-test eligibility: each gate individually, store-scope resolution (quote's store vs ambient), exemption certificate does not exempt, eligibility flips with cart changes

## 3. Quote total collector + persistence

- [x] 3.1 Add `db_schema.xml` columns `taxcloud_rdf_amount` / `base_taxcloud_rdf_amount` on `quote_address`, `sales_order`, `sales_invoice`, `sales_creditmemo`; regenerate `db_schema_whitelist.json`
- [x] 3.2 Create the quote total collector (charges configured amount when eligible, adds to grand total, keeps out of tax total), registered in new `etc/sales.xml` after `tax`
- [x] 3.3 Copy the fee from quote to order via `Observer\Sales\PersistRetailDeliveryFee` on `sales_model_service_quote_submit_before` (a fieldset cannot: core's ToOrder converter filters to `OrderInterface` keys and drops the custom column)
- [x] 3.4 Expose the fee in checkout `total_segments`
- [x] 3.5 Unit-test the collector: eligible charges once, ineligible contributes nothing and clears a previously-charged fee, grand total includes fee, tax total excludes it

## 4. Lookup line (both transports)

- [x] 4.1 Append the fee line in `RequestBuilder::buildLookupCartItems` when eligible (sentinel ItemID, configured TIC/amount, qty 1, full amount — no discount netting); confirm the REST delegation path maps it via `toV3LineItem`
- [x] 4.2 Add the explicit `co-rdf` branch to `CartItemResponseHandler::applyProcessedItemsToResult` (discard, never route to product/shipping buckets)
- [x] 4.3 Unit-test: eligible request contains exactly one fee line (v1 shape and mapped v3 shape), ineligible contains none, discounted cart leaves fee line at full amount, response echo does not pollute buckets or raise on the sentinel index

## 5. Capture

- [x] 5.1 Include the fee line in the v1 capture (`AuthorizedWithCapture`) cart when the order stores a fee amount
- [x] 5.2 Include the fee line in v3 `RestRequestBuilder::buildOrderPayload` (price = stored amount, tax 0)
- [x] 5.3 Log a reconciliation warning when an order with a fee was placed under Magento-rate fallback (fee never priced through a lookup)
- [x] 5.4 Unit-test both capture payloads and the fallback flag path

## 6. Refunds

- [x] 6.1 Creditmemo total collector: credit the fee only when the memo returns all remaining items (full return), contribute 0 on partial returns
- [x] 6.2 v1 `Returned` path: include the fee line on full returns; drive `returnCoDeliveryFeeWhenNoCartItems=true` for the no-cart-items full-return case (replace the hardcoded `false` where the order carries a fee and the return is full)
- [x] 6.3 v3 refund payload: include the fee item reference on full returns only
- [x] 6.4 Invoice total collector so invoices settle the fee amount
- [x] 6.5 Unit-test: full return credits + reverses, partial return does neither, no-cart-items full return drives the flag

## 7. Presentation

- [x] 7.1 Checkout/cart summary line (layout XML + KO component + template), hidden when zero
- [x] 7.2 Customer + admin order/invoice/creditmemo view totals line from the persisted amount
- [x] 7.3 Order/invoice email totals line
- [x] 7.4 `etc/pdf.xml` totals entry for printed invoices/creditmemos
- [x] 7.5 i18n entry for the fixed label "Colorado Retail Delivery Fee"

## 8. Verification & quality

- [x] 8.1 Run the full unit suite; check new test code against PHPUnit 9.5/10.5/12.5 idioms (no version-specific APIs)
- [x] 8.2 Run `make phpstan` (level 5) and fix anything new (no baseline additions)
- [x] 8.3 Propose integration/e2e coverage to the maintainer (checkout charge + capture assertion; full vs partial refund) — do not implement unprompted

## 9. Documentation

- [x] 9.1 New merchant-facing docs page for the Colorado settings group (per `docs/writing-documentation.md`), added to `mkdocs.yml` nav
- [x] 9.2 Update any existing docs page whose described behavior changes (settings reference; shipping TIC page if it mentions 11098)
- [x] 9.3 Update `README.md` settings table
- [x] 9.4 Add `CHANGELOG.md` entry
