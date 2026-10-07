## Context

See proposal.md for motivation and specs/order-processing-rules/spec.md for the
behaviour contract. Current state that shapes the approach:

- Whether an order is reported is one store-scoped flag,
  `tax/taxcloud_settings/calculations_only`, read through
  `TaxcloudConfig::isCalculationsOnly($store)` at exactly three gates:
  `Observer/Sales/Complete.php` (capture), `Observer/Sales/Refund.php` (return),
  `Model/Order/CancellationProcessor.php` (cancel reversal).
- Tax calculation is our preference over core's quote tax collector,
  `Model/Tax.php`. When TaxCloud is disabled for the quote's store it already
  hands the quote to core's native calculation via `parent::collect()`.
- Address verification runs inside the Lookup (on `taxcloud_lookup_before` /
  `taxcloud_rest_lookup_before`) and exemption certificates are applied inside
  the Lookup, so anything that never issues a Lookup never verifies or exempts.
  Two TaxCloud contributions live outside the Lookup: the Colorado Retail
  Delivery Fee quote collector (`Model/RetailDeliveryFee/Total/Quote`) prices
  from config, and `Observer/Sales/RecordCertificate` resolves a certificate at
  quote submission.
- `QuoteManagement::submitQuote()` sets the order's increment id from the
  reserved id *before* dispatching `sales_model_service_quote_submit_before`, so
  at that event the order number, quote and order are all in hand. The module
  already hooks that event for certificate recording, RDF persistence and
  collector verification.
- Custom `sales_order` columns are not carried by the quote→order fieldset
  (`ToOrder::convert()` keeps only interface keys); the module writes them from
  a submit-time observer instead.

## Goals / Non-Goals

**Goals:**
- One pure, unit-testable rule evaluator used identically at checkout and at
  placement.
- Every TaxCloud touchpoint on a quote honours *Skip TaxCloud* through a single
  decision, so the tax charged and the stored outcome cannot disagree.
- Reporting gates read one per-order policy instead of the store flag.
- No data migration; existing stored values keep their meaning.

**Non-Goals:**
- A generic conditions engine (`Magento\Rule`) or an extension point for custom
  filters.
- Showing the outcome in the order grid or the order-view TaxCloud block — the
  history comment and diagnostics cover explainability for now.
- Caching rules across requests.

## Decisions

### 1. Keep the config path; invert the presentation

`calculations_only` stays the stored path and value. The admin field is
relabelled *Report orders to TaxCloud* with a new source model whose options
map `0 → Yes`, `1 → No`. `TaxcloudConfig` gains `isReportingByDefault($store)`
(`!calculations_only`) and every caller of `isCalculationsOnly()` moves to the
reporting policy (Decision 6); `isCalculationsOnly()` is removed.

- *Alternative:* a new `report_orders` path plus a data patch copying inverted
  values at every scope. Rejected: breaks deploy scripts and `config.php`
  values, and the migration has to walk every scope for no user-visible gain.
- The field's `config_path` stays explicit so the name mismatch is visible in
  one place, with a comment explaining it.
- `capture_trigger` loses its `depends` on `calculations_only`: a *Report* rule
  can now send orders from a store view whose default is *No*, so the trigger
  must stay configurable there. (Per the e2e depends trap, any e2e teardown that
  relied on the field being hidden must be revisited.)

### 2. Rules as one table with JSON filter columns

New table `taxcloud_order_rule`:

| column | type | notes |
|---|---|---|
| `rule_id` | int PK | |
| `name` | varchar(255) | required |
| `is_active` | smallint | |
| `sort_order` | int | evaluation order, ascending |
| `action` | varchar(32) | `report` / `calculate_only` / `skip` |
| `store_ids` | text (JSON int array) | empty = any |
| `customer_group_ids` | text (JSON int array) | empty = any |
| `payment_methods` | text (JSON string array) | method codes |
| `shipping_methods` | text (JSON string array) | `carrier_method` codes |
| `order_prefixes` | text (JSON string array) | matched case-insensitively |
| `created_at` / `updated_at` | timestamp | |

Standard model / resource model / collection / repository with a data
interface under `Api/Data`.

- *Alternative:* link tables per filter (as SalesRule does for websites/groups).
  Rejected: a merchant has a handful of rules, filters are never queried from
  SQL — the evaluator loads all active rules and matches in PHP — and link
  tables multiply the persistence code for nothing.
- Deleted store views, groups or methods leave dangling values in the JSON; they
  can never match and the form drops unknown values on next save. Acceptable.

### 3. A pure evaluator over a normalised subject

`OrderRule\Evaluator::evaluate(MatchSubject $subject): Decision` walks the
active rules in `sort_order` and returns the first match (rule id, name,
action, and the matched filter values for the comment) or a *default* decision
carrying the store view's reporting default.

`MatchSubject` is a small value object — store id, customer group id, payment
method code (nullable), shipping method code (nullable), order number
(nullable) — built by `SubjectReader::fromQuote()` (order number always null)
and `fromOrder()`; an injected reader rather than static factories, which the
Magento coding standard rejects. Matching rules: empty filter → match; non-empty filter with a
null subject value → no match; prefix compares with `stripos(...) === 0`.

The evaluator receives the rule list from a repository memoised per request
(one query per request regardless of how many times totals collect).

- *Alternative:* evaluating directly against quote/order objects. Rejected: two
  code paths to keep equivalent, and quote vs order getters differ (shipping
  method lives on the quote address).

### 4. One skip decision per quote, consulted by every touchpoint

`OrderRule\QuoteSkipResolver::resolve(Quote $quote): ?Decision` returns the
skip decision (or null) for the quote's current state, memoised on the quote as
transient data keyed by the subject's fingerprint so repeated collects with
unchanged inputs don't re-evaluate, and a changed payment/shipping method
re-evaluates. Consumers:

- `Model/Tax.php::collect()` — right after the disabled-store check: if skipped,
  `parent::collect()` and return. The diagnostic `COLLECTED_FLAG` stays set
  before both checks, so collector verification is unaffected.
- RDF quote collector — returns before pricing the fee.
- `RecordCertificate` — returns before resolving a certificate.

Address verification, exemption application and Canadian tax need no gate: all
three only happen inside a Lookup, which a skipped quote never issues.

Only the evaluator result *if its action is `skip`* is acted on at checkout;
`report` and `calculate_only` both mean "calculate with TaxCloud", so a quote
only needs to know whether it is skipped.

- *Alternative:* a quote column persisting the decision. Rejected: the decision
  must follow the quote as payment/shipping change, and the submit-time recorder
  can read the same resolver in the same request.

### 5. Record the outcome at submission, with a placement fallback

New `sales_order` columns: `taxcloud_outcome` (varchar 32, nullable),
`taxcloud_outcome_rule_id` (int, nullable, **no** FK so rule deletion leaves
history intact), `taxcloud_outcome_rule_name` (varchar 255, nullable —
snapshot).

`OrderRule\OutcomeRecorder::record(Order $order, ?Quote $quote)`:
1. If TaxCloud is disabled for the order's store, or the order already has an
   outcome, do nothing.
2. If a quote is given and `QuoteSkipResolver` says skipped → outcome `skip`
   with that rule.
3. Otherwise evaluate `MatchSubject::fromOrder($order)` → first match or
   default.
4. Write the three columns; if a *rule* decided a non-`report` outcome, add a
   non-notifying, non-visible status history comment built by
   `OutcomeCommentBuilder` from the decision's matched filter values (labels
   resolved from store/group/payment/shipping sources, codes in parentheses).

Called from a new `sales_model_service_quote_submit_before` observer (quote +
order in hand, order not yet saved, so columns and comment save with it) and
from `Complete` on `sales_order_place_after` before its gates, which covers
orders placed without `QuoteManagement` and makes observer ordering irrelevant.

- *Alternative:* deciding at the first capture trigger. Rejected: with the
  invoice/shipment triggers that is days later, after possible rule edits —
  exactly what the spec forbids.

### 6. One reporting policy for all three gates

`OrderRule\ReportingPolicy::isReported(Order $order): bool` — stored outcome
present → `=== report`; absent (legacy order) → `isReportingByDefault($order
store)`. `Complete`, `Refund` and `CancellationProcessor` replace their
`isCalculationsOnly()` gate with it, keeping its position and log line shape
(`(outcome: calculate_only, rule "Wholesale")` / `(store not reporting)`). The
cancel gate stays before `wasCapturedInTaxcloud()` for the same leak-prevention
reason documented there today.

### 7. Admin: a sortable list page and a UI-component form

- Menu *Stores › Taxes › TaxCloud Order Rules*, ACL resource
  `Taxcloud_Magento2::order_rules` under `Magento_Tax::manage_tax`.
- **List page:** a block + template rendering all rules as a table (drag handle,
  name, filter summary, action, active, edit/delete) with jQuery UI `sortable`;
  dropping posts the new id order (form key) to a `SaveOrder` controller that
  rewrites `sort_order`. A note under the table states the default behaviour
  with a link to the TaxCloud configuration section.
  - *Alternative:* a UI listing. Rejected: listings have no drag-reorder, and
    paging/filtering add nothing for a handful of rules.
  - *Alternative:* a dynamic-rows form with `dndConfig`. Rejected: rows would
    need inline editing of five multiselects — cramped and harder to explain
    than a list plus a form.
- **Edit form:** UI component form `taxcloud_order_rule_form` — name, active,
  store views (store tree source), customer groups, payment methods
  (`Magento\Payment\Model\Config\Source\Allmethods`), shipping methods
  (`Magento\Shipping\Model\Config\Source\Allmethods`, the source the RDF field
  already uses), prefixes (textarea, one per line or comma-separated), action.
  A notice component shown when action = skip carries the storefront warning.
  New rules are appended at the end of the list.
- **Validation** lives in the repository's save path (`RuleValidator`), so the
  controller, any API caller and tests share it: name required, action valid,
  `skip` + non-empty prefixes rejected with an explanatory message. The form
  disables the prefix field when action = skip as a convenience only.
- Filter summary text is produced by one `RuleSummary` service shared by the
  list page and the diagnostics bundle (store view names from the store
  manager; groups, payment and shipping from the form's option sources).
- Core's payment option source keys grouped methods by code, which the
  multiselect cannot render; `Source\PaymentMethods` flattens it. The Skip
  warning is an `html` component, switched via its `visible` observable (it has
  no `show()`/`hide()`).

### 8. Diagnostics

`SettingsSection`/`SummaryRenderer` keep the `calculations_only` key but render
it as *Report orders to TaxCloud* with the inverted value. A new rules block
(settings JSON + summary) lists rules in order with summary, action and active
state. `OrderSection` adds the three outcome columns, rendering a missing
outcome as "none (placed before order processing rules)".

## Risks / Trade-offs

- [An importer creates orders without `QuoteManagement` and without `place()`]
  → no outcome is recorded and the order behaves as legacy (store default). The
  docs name the payment-method/prefix approach and point to the order comment as
  the way to confirm a rule fired.
- [Payment-filtered skip rules make storefront tax change at the payment step]
  → specified and warned in the form; docs recommend skip rules for imported
  orders.
- [Inverted Yes/No over a path named `calculations_only` confuses developers]
  → comment at the field and constant; the README's config table (developer
  material) notes the inversion.
- [A Report rule in a default-No store view captures orders that used to be
  invisible to TaxCloud] → only when the merchant creates such a rule;
  `capture_trigger` is now always visible so its timing is configurable.
- [Rule evaluation on every totals collection] → one memoised query per request
  and an in-PHP match over a few rows; negligible next to the Lookup it may
  avoid.
- [A rule edited while a customer is mid-checkout] → the submit-time recorder
  reads the same request's skip decision, so the stored outcome always matches
  the tax actually charged.

## Migration Plan

- Declarative schema adds the table and three `sales_order` columns
  (`db_schema_whitelist.json` regenerated). No data patch.
- Upgrade is behaviour-neutral until a rule is created: existing orders have no
  outcome and follow the store setting; new orders with no rules get a
  `default` outcome equal to today's behaviour.
- Rollback: reverting the module leaves unused columns and table; the config
  value was never rewritten, so the previous version reads it unchanged.
