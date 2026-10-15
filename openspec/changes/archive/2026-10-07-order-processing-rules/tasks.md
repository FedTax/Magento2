## 1. Reporting default setting

- [x] 1.1 Add a `ReportOrders` source model mapping `0 → Yes`, `1 → No`
- [x] 1.2 Relabel the `calculations_only` field in `etc/adminhtml/system.xml` as *Report orders to TaxCloud* with the new source model and comment; keep the config path, with an XML comment explaining the inversion
- [x] 1.3 Remove the `calculations_only` dependency from `capture_trigger`'s `depends`
- [x] 1.4 Add `TaxcloudConfig::isReportingByDefault($store)` and remove `isCalculationsOnly()` once all callers are migrated (group 5)

## 2. Rule persistence

- [x] 2.1 Declare `taxcloud_order_rule` and the `sales_order` columns `taxcloud_outcome`, `taxcloud_outcome_rule_id`, `taxcloud_outcome_rule_name` in `etc/db_schema.xml`; regenerate `db_schema_whitelist.json`
- [x] 2.2 Add the rule data interface, model, resource model (JSON filter columns encoded/decoded), collection and repository with `di.xml` preferences
- [x] 2.3 Add an action source/enum (`report`, `calculate_only`, `skip`)
- [x] 2.4 Add `RuleValidator` called from the repository save path: name required, action valid, `skip` with order prefixes rejected with an explanatory message; normalise prefixes (trim, drop empties, dedupe)
- [x] 2.5 Memoise the active, `sort_order`-sorted rule list per request in the repository

## 3. Evaluation

- [x] 3.1 Add the `MatchSubject` value object with `fromQuote()` (store, customer group, payment method, shipping address method, null order number) and `fromOrder()` factories
- [x] 3.2 Add the `Decision` value object (action, rule id, rule name, matched filter values, default flag)
- [x] 3.3 Implement `Evaluator`: first active match in order, empty filter = any, null subject value fails a non-empty filter, case-insensitive prefix, default decision from `isReportingByDefault()` of the subject's store
- [x] 3.4 Implement `QuoteSkipResolver` with per-quote memoisation keyed on the subject fingerprint; returns a decision only when the action is `skip` and TaxCloud is enabled for the quote's store

## 4. Checkout skip

- [x] 4.1 In `Model/Tax.php::collect()`, after the disabled-store check, hand skipped quotes to `parent::collect()`; keep `COLLECTED_FLAG` set before both checks
- [x] 4.2 Skip fee pricing for skipped quotes in the RDF quote collector
- [x] 4.3 Skip certificate resolution for skipped quotes in `Observer/Sales/RecordCertificate`

## 5. Outcome recording and reporting gates

- [x] 5.1 Implement `OutcomeRecorder` (disabled store / existing outcome → no-op; skipped quote → `skip`; else evaluate the order; write the three columns)
- [x] 5.2 Implement `OutcomeCommentBuilder` resolving store view, customer group, payment and shipping labels (codes in parentheses) and the outcome's meaning; add the comment non-notifying and not visible on the storefront, only for rule-decided non-`report` outcomes
- [x] 5.3 Add a `sales_model_service_quote_submit_before` observer calling the recorder with quote and order
- [x] 5.4 Call the recorder from `Observer/Sales/Complete` on `sales_order_place_after` before its gates
- [x] 5.5 Implement `ReportingPolicy::isReported()` (stored outcome → `report`; none → store default)
- [x] 5.6 Replace the calculations-only gate in `Complete`, `Refund` and `CancellationProcessor` with `ReportingPolicy`, keeping gate position and naming outcome/rule in the skip log line

## 6. Admin

- [x] 6.1 Add ACL resource `Taxcloud_Magento2::order_rules` under `Magento_Tax::manage_tax` and a menu item under *Stores › Taxes*
- [x] 6.2 Add `RuleSummary` producing the readable filter summary
- [x] 6.3 Build the list page (controller, layout, block, template): drag handle, name, summary, action, active, edit/delete, default-behaviour note linking to the TaxCloud config section
- [x] 6.4 Wire jQuery UI `sortable` to post the id order to a `SaveOrder` controller (form key, ACL) that rewrites `sort_order`
- [x] 6.5 Build the `taxcloud_order_rule_form` UI component form with its data provider: name, active, store views, customer groups, payment methods, shipping methods, prefixes, action; skip-action notice; prefix field disabled for skip
- [x] 6.6 Add New/Edit/Save/Delete controllers; new rules appended at the end of the order; validation errors returned to the form

## 7. Diagnostics

- [x] 7.1 Render `calculations_only` as *Report orders to TaxCloud* with the inverted value in `SettingsSection`/`SummaryRenderer`
- [x] 7.2 Add the ordered rule list (summary, action, active) to the settings output and summary
- [x] 7.3 Add the outcome columns to `OrderSection`, rendering a missing outcome as placed before rules existed

## 8. Tests

- [x] 8.1 Unit tests: `ReportOrders` source model, `isReportingByDefault()` per store and default
- [x] 8.2 Unit tests: `RuleValidator`, repository JSON round-trip, per-request memoisation
- [x] 8.3 Unit tests: `MatchSubject` factories (virtual quote without shipping, missing payment), `Evaluator` (OR within, AND across, empty = any, order, inactive, prefix case, default decision per store)
- [x] 8.4 Unit tests: `QuoteSkipResolver` (re-evaluates on payment change, memoises unchanged, disabled store, quote store not ambient)
- [x] 8.5 Unit tests: skip paths in `Tax::collect()`, RDF collector, `RecordCertificate`
- [x] 8.6 Unit tests: `OutcomeRecorder` (skip from quote wins over a prefix rule, order evaluation, existing outcome untouched, disabled store), `OutcomeCommentBuilder` (comment only for rule-decided non-report)
- [x] 8.7 Unit tests: `ReportingPolicy` and rewrite the existing calculations-only tests in `CompleteTest`, `RefundTest`, `CancellationProcessorTest`, `CaptureTriggerTest`, `TaxcloudConfigTest`, `AddressTest`, `TaxTest` to the new gates
- [x] 8.8 Unit tests: `RuleSummary`, `SaveOrder` controller, diagnostics sections
- [x] 8.9 Check all new test code against PHPUnit 9.5 / 10.5 / 12.5; run `make test`, `make lint`, `make phpstan` and judge by exit code
- [x] 8.10 (Approved by maintainer: all of it. Integration 255 green; e2e 75 green incl. SOAP + REST passes) Propose integration coverage to the maintainer (rewrite `CalculationsOnlyStoreViewTest` around outcomes; quote submit records outcome + comment; skipped quote taxed natively with no Lookup; legacy order follows store setting) and e2e coverage (rules screen CRUD + drag reorder; storefront skip by payment method); add only what is agreed

## 9. Documentation

- [x] 9.1 Rewrite the setting section in `docs/settings.md` as *Report orders to TaxCloud*, update the summary and config-path tables, and drop the "hidden when" note from *Capture in TaxCloud*
- [x] 9.2 Add `docs/order-processing-rules.md` (when to use it, filters, first-match order, the three actions, what Skip turns off, checkout timing and prefix limits, order comment, worked Amazon/wholesale examples) and its `mkdocs.yml` nav entry
- [x] 9.3 Replace calculations-only wording in `capture.md`, `refunds.md`, `cancellations.md`, `filing.md`, `common-problems.md`; add a common problem for "order not in TaxCloud" pointing to the order comment
- [x] 9.4 Note the inverted `calculations_only` path in README developer material if config internals are listed there (checked: README lists no config internals — nothing to change)
- [x] 9.5 Add the `CHANGELOG.md` entry
