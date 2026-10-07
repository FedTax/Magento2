## Why

Whether TaxCloud records a store view's sales is currently all-or-nothing: the
`calculations_only` switch either reports every order in the store view or none.
Merchants routinely need a split inside one store view (DEV-10301):
marketplace-imported orders (Amazon, eBay) are taxed and remitted by the
marketplace as facilitator, so reporting them double-files the sale; wholesale
orders are reported by a connected ERP or accounting system; some payment or
shipping methods (e.g. in-store pickup via a POS) are reported elsewhere. For
imported orders, even calculating with TaxCloud is wrong — the marketplace's tax
must stand.

## What Changes

- The store-view setting *Only do tax calculations without further TaxCloud
  integration* is relabelled **Report orders to TaxCloud** (Yes/No) and becomes
  the default outcome for orders that match no rule. It keeps its existing config
  path and stored values (`calculations_only = 1` is shown as *No*, `0` as *Yes*),
  so no data migration is needed and values set from deploy scripts or
  `config.php` keep working unchanged.
- New **order processing rules**: an ordered list (drag-to-reorder in the admin)
  evaluated top to bottom, first active match wins, no nesting.
  - Filters, each optional (empty = any): store views, customer groups, payment
    methods, shipping methods, order number prefix ("starts with"). Values within
    a filter are OR'd; filled filters within a rule are AND'd.
  - Actions: **Report** (normal behavior), **Calculate only** (TaxCloud
    calculates, the sale is never reported), **Skip TaxCloud** (no TaxCloud
    involvement at all; the quote is taxed by Magento's native tax rules exactly
    as if TaxCloud were disabled for that store).
- **Skip TaxCloud** is decided at checkout, on the quote. A skip rule cannot use
  the order number prefix filter (the number does not exist yet) — the rule form
  refuses to save it. A skip rule may use payment or shipping method filters, with
  a form warning that storefront tax can change once the customer selects the
  matching method.
- Skipping also bypasses everything else TaxCloud contributes to that order:
  address verification, exemption certificates, the Colorado Retail Delivery Fee
  and Canadian tax.
- The outcome (action + matched rule) is **decided once per order and stored on
  it**. Capture, refunds and cancellations act on the stored outcome instead of
  the store flag. Editing, reordering or deleting rules never changes an existing
  order's outcome. Orders placed before this change carry no stored outcome and
  keep today's behavior (their store's setting decides).
- When an order's outcome is not *Report*, a non-customer-visible **order history
  comment** records which rule matched and on what.
- Diagnostics show the renamed setting, the configured rules, and an order's
  stored outcome.

## Non-goals

- Nested or grouped conditions (AND/OR trees in the style of Cart Price Rules).
- Order status as a filter — status changes over an order's life and is not a
  stable property to decide on.
- Product- or SKU-level rules; reporting stays whole-order.
- Re-evaluating rules for orders placed before the change or before a rule edit.
- Partial reporting of an order (capture remains whole-order).
- Any rule effect on orders already captured when the change is installed.

## Store-scoping implications

- **Report orders to TaxCloud** keeps default/website/store-view scope and is
  read against the quote's or order's store, never the ambient store.
- Rules are global records with an explicit *store views* filter (empty = all);
  evaluation matches against the quote's/order's store id, so admin, cron, API
  and webhook contexts decide by the entity's store.
- The setting keeps its config path, so every scope's existing override carries
  over as-is: a store view that was calculations-only is non-reporting after
  upgrade without any migration.
- The stored per-order outcome makes later decisions (capture at invoice or
  shipment from admin/cron) independent of any scope at all.

## Capabilities

### New Capabilities
- `order-processing-rules`: the store-view reporting default, rule definition
  and ordering, matching semantics, the three actions, checkout-time skip,
  per-order outcome recording, the explanatory order comment, and the admin
  screens that manage rules.

### Modified Capabilities
- `order-capture-lifecycle`: the calculations-only store gate is replaced by the
  order's stored processing outcome — only orders whose outcome is *Report* are
  captured; others are left with no recorded capture.

## Impact

- **Config:** `etc/adminhtml/system.xml` (relabel with an inverted Yes/No source
  model on the same path; `capture_trigger` depends unchanged), `TaxcloudConfig`
  (a reporting-default accessor alongside/replacing `isCalculationsOnly`). No
  data patch.
- **Schema:** new rules table; outcome columns on `quote` and `sales_order` plus
  the quote→order copy (fieldset) for them.
- **Tax collection:** `Model/Tax.php` gains a skip path alongside the existing
  disabled-store path; address verification and exemption lookups must honour it.
- **Order lifecycle:** `Observer/Sales/Complete.php`, `Observer/Sales/Refund.php`,
  `Model/Order/CancellationProcessor.php` switch from the store flag to the
  stored outcome / captured state; a new step decides and records the outcome at
  placement.
- **Admin:** new menu entry, ACL resource, rules grid with drag-reorder, rule
  edit form (UI components), store-view setting relabel.
- **Diagnostics:** `SummaryRenderer` settings list, settings/order bundle output.
- **Tests:** unit tests for matching, ordering, actions, the inverted setting and every
  gate swap; existing calculations-only tests rewritten. Integration and e2e
  coverage to be proposed and agreed before adding.
- **Docs:** `docs/settings.md`, new *Order processing rules* page plus
  `mkdocs.yml` nav, and the calculations-only wording in `capture.md`,
  `refunds.md`, `cancellations.md`, `filing.md`, `common-problems.md`;
  `CHANGELOG.md`.
