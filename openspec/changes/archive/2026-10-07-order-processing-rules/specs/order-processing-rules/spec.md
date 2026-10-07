## Purpose

Lets a merchant decide, order by order, how much of TaxCloud an order uses — calculated and reported, calculated only, or not touched at all — through a store-view default and an ordered list of rules matched on the order's store view, customer group, payment method, shipping method and order number.

## ADDED Requirements

### Requirement: Each store view has a reporting default

Every store view SHALL have a *Report orders to TaxCloud* setting (Yes/No) that decides the outcome of any order matching no rule: *Yes* means the order is calculated by TaxCloud and reported; *No* means it is calculated by TaxCloud and never reported. The setting SHALL be configurable at default, website and store-view scope, SHALL default to *Yes*, and SHALL be resolved against the store of the quote or order being processed, never the ambient store of the request.

The setting SHALL keep the stored values and configuration path of the setting it replaces, so that a scope configured as calculations-only before this change reads as *No* afterwards without any migration, and values written by deployment scripts or file-based configuration keep their meaning.

#### Scenario: A calculations-only store view reads as not reporting
- **WHEN** a store view had the calculations-only setting enabled before this change
- **THEN** its *Report orders to TaxCloud* setting shows *No*, and its orders that match no rule are calculated but not reported

#### Scenario: The default applies only when no rule matches
- **WHEN** an order matches no active rule
- **THEN** its outcome is the *Report orders to TaxCloud* value of the order's store view

#### Scenario: The default resolves against the order's store
- **WHEN** an order from a store view set to *No* is processed while the ambient store is a store view set to *Yes*
- **THEN** the order's own store view setting decides the outcome

### Requirement: Rules are evaluated in order and the first match wins

Order processing rules SHALL form a single ordered list shared by all store views. Rules SHALL be evaluated from the top of the list down, the first active rule whose filters all match SHALL decide the outcome, and no later rule SHALL be consulted. Inactive rules SHALL be ignored. Rules SHALL have no effect for a store view on which TaxCloud is disabled.

#### Scenario: An earlier rule shadows a later one
- **WHEN** an order matches both the first and the third rule in the list
- **THEN** the first rule's action decides the outcome

#### Scenario: An inactive rule is skipped
- **WHEN** the only rule an order matches is inactive
- **THEN** the order's outcome is its store view's reporting default

#### Scenario: Reordering changes which rule wins
- **WHEN** an administrator moves a rule above another rule the same order also matches
- **THEN** orders evaluated afterwards are decided by the moved rule

### Requirement: Rule filters combine as OR within a filter and AND across filters

A rule SHALL offer five filters: store views, customer groups, payment methods, shipping methods, and order number prefixes. An empty filter SHALL match every order. A filter holding several values SHALL match when the order has any one of them. A rule SHALL match only when every non-empty filter matches. An order number prefix SHALL match when the order number starts with it, ignoring letter case.

The store-view filter SHALL be matched against the store of the quote or order being evaluated.

#### Scenario: Several values in one filter
- **WHEN** a rule's payment filter lists two payment methods and an order uses either of them
- **THEN** the payment filter matches

#### Scenario: Several filters in one rule
- **WHEN** a rule filters on customer group *Wholesale* and shipping method *Freight*, and an order is from a *Wholesale* customer shipped by a different method
- **THEN** the rule does not match

#### Scenario: Empty filters match everything
- **WHEN** a rule fills only the customer group filter
- **THEN** it matches every order from that group regardless of store view, payment, shipping method or order number

#### Scenario: Order number prefix
- **WHEN** a rule's prefix filter is `amz-` and an order's number is `AMZ-114-2231`
- **THEN** the prefix filter matches

### Requirement: A rule's action decides how much of TaxCloud the order uses

Each rule SHALL carry exactly one action:

- **Report** — the order is calculated by TaxCloud and reported to TaxCloud exactly as it would be with no rules at all.
- **Calculate only** — the order is calculated by TaxCloud at checkout and is never reported: no capture, refund or cancellation is sent for it.
- **Skip TaxCloud** — TaxCloud takes no part in the order: its tax is calculated as if TaxCloud were disabled for the store, and nothing is ever reported.

A *Report* rule SHALL be honoured even when the store view's default is *No*, so a merchant can send selected orders from an otherwise non-reporting store view, or exempt a narrower set of orders from a broader rule placed below it.

#### Scenario: A Report rule overrides a non-reporting default
- **WHEN** a store view's default is *No* and an order matches a *Report* rule
- **THEN** the order is captured, refunded and cancelled in TaxCloud as normal

#### Scenario: A Report rule carves an exception out of a broader rule
- **WHEN** rule 1 is *Wholesale + Check → Report*, rule 2 is *Wholesale → Calculate only*, and a wholesale order is paid by check
- **THEN** the order is reported

#### Scenario: Calculate only
- **WHEN** an order's outcome is *Calculate only*
- **THEN** its tax is calculated by TaxCloud at checkout, and no capture, refund or cancellation is ever sent to TaxCloud for it

### Requirement: Skip TaxCloud is decided at checkout

Whether a quote is skipped SHALL be decided each time its totals are calculated, by evaluating the rules against the quote: its store view, customer group, payment method and shipping method as they stand at that moment. A quote whose first matching rule is *Skip TaxCloud* SHALL be taxed exactly as if TaxCloud were disabled for its store, and SHALL make no TaxCloud request of any kind: no tax lookup, no address verification and no exemption certificate lookup. Nothing TaxCloud adds to a quote — the Colorado Retail Delivery Fee, Canadian tax, an applied exemption certificate — SHALL be added to a skipped quote.

Rules with an order number prefix filter SHALL never match during checkout evaluation, because the order number does not exist yet.

#### Scenario: A skipped quote is taxed natively
- **WHEN** a quote's first matching rule is *Skip TaxCloud*
- **THEN** its tax comes from the store's native tax configuration and no TaxCloud request is made

#### Scenario: The decision follows the quote as it changes
- **WHEN** a storefront customer selects a payment method that a *Skip TaxCloud* rule filters on, after tax was already shown for the cart
- **THEN** the tax is recalculated natively, and switching back to another payment method returns the quote to TaxCloud calculation

#### Scenario: A prefix rule cannot block a skip at checkout
- **WHEN** a rule with an order number prefix filter sits above a *Skip TaxCloud* rule that the quote matches
- **THEN** the quote is skipped, because the prefix rule cannot match before the order is numbered

### Requirement: Skip rules are restricted to filters known at checkout

A rule with the *Skip TaxCloud* action SHALL NOT be saved with an order number prefix filter; the administrator SHALL be told why. A *Skip TaxCloud* rule that filters on payment or shipping method SHALL be saved, and the rule form SHALL warn that storefront tax can change when the customer selects a matching method.

#### Scenario: Skip with a prefix is refused
- **WHEN** an administrator saves a *Skip TaxCloud* rule with an order number prefix
- **THEN** the rule is not saved and the form explains that order numbers are not known at checkout

#### Scenario: Skip on payment method warns
- **WHEN** an administrator edits a *Skip TaxCloud* rule that filters on payment or shipping method
- **THEN** the form shows a warning that storefront tax may change when the customer picks that method, and the rule can still be saved

### Requirement: An order's outcome is decided once and stored on it

When an order is placed, its outcome SHALL be decided and stored on the order together with the rule that decided it, or the fact that the store view default decided it. An order whose quote was skipped at checkout SHALL have the outcome *Skip TaxCloud*. Otherwise the rules SHALL be evaluated against the placed order, with all five filters available.

Every later decision about the order — capture, refund, cancellation — SHALL use the stored outcome. Editing, reordering, deactivating or deleting rules, or changing the store view default, SHALL NOT change the outcome of an order already placed. The name of the deciding rule SHALL remain available for an order even after that rule is deleted.

#### Scenario: A rule edit does not affect a placed order
- **WHEN** an order was placed with the outcome *Calculate only* and the rule that matched it is then changed to *Report*
- **THEN** the order is still not captured when it is later invoiced or shipped

#### Scenario: A skipped checkout stays skipped
- **WHEN** a quote was skipped at checkout
- **THEN** the placed order's outcome is *Skip TaxCloud*, even if a rule with an order number prefix above the skip rule matches the new order number

#### Scenario: Orders created without checkout are evaluated at placement
- **WHEN** an order is created without its totals being calculated by checkout, and it matches a *Calculate only* rule on its order number prefix
- **THEN** its stored outcome is *Calculate only*

### Requirement: Orders placed before rules existed keep their previous behaviour

An order with no stored outcome — placed before this capability was installed — SHALL be reported or not according to its store view's *Report orders to TaxCloud* setting at the time each reporting operation runs, exactly as before. Rules SHALL never be applied to such an order retroactively.

#### Scenario: A legacy order on a reporting store view
- **WHEN** an order placed before the upgrade, on a store view set to *Yes*, is shipped after the upgrade
- **THEN** it is captured according to the store's capture trigger, regardless of which rules it would match

### Requirement: Reporting operations follow the stored outcome

Only orders whose outcome is *Report* SHALL be captured, refunded or cancelled in TaxCloud. Orders whose outcome is *Calculate only* or *Skip TaxCloud* SHALL never cause a TaxCloud capture, return or cancellation call, and SHALL be left without a recorded capture.

#### Scenario: Refund of a non-reported order
- **WHEN** a credit memo is created for an order whose outcome is *Calculate only*
- **THEN** no TaxCloud return is sent, and the reason is logged

#### Scenario: Cancellation of a non-reported order
- **WHEN** an order whose outcome is *Skip TaxCloud* is cancelled
- **THEN** no TaxCloud cancellation is sent

### Requirement: An order records why a rule kept it from TaxCloud

When a rule decides an outcome other than *Report*, an order history comment SHALL be added at placement, not visible to the customer and not notifying them, naming the rule, the filters that matched with their values, and what the outcome means for the order. No comment SHALL be added when the outcome is *Report*, or when the outcome comes from the store view default.

#### Scenario: Comment for a rule-decided outcome
- **WHEN** an order is placed and the rule *Wholesale* (customer group *Wholesale*, shipping *Freight*) decides *Calculate only*
- **THEN** the order history shows a non-customer-visible comment stating that TaxCloud calculated the tax but the order is not reported, because of rule *Wholesale* matching customer group *Wholesale* and shipping method *Freight*

#### Scenario: Comment for a skipped order
- **WHEN** an order placed from a skipped quote is decided by rule *Amazon*
- **THEN** the comment states that TaxCloud was not used, that tax was calculated by the store's own tax rules, and which rule and filter values caused it

#### Scenario: No comment for the default
- **WHEN** an order matches no rule on a store view whose default is *No*
- **THEN** no TaxCloud comment is added

### Requirement: Administrators manage rules from a dedicated screen

Administrators with the permission for TaxCloud order processing rules SHALL be able to list, create, edit, activate, deactivate and delete rules. The list SHALL show each rule's name, a readable summary of its filters, its action and whether it is active, in evaluation order, and SHALL let the administrator change that order by dragging rules. The new order SHALL apply to orders evaluated afterwards. The list SHALL state that orders matching no rule follow each store view's *Report orders to TaxCloud* setting.

#### Scenario: Reordering by drag
- **WHEN** an administrator drags the third rule to the top of the list
- **THEN** the list shows it first, and it is the first rule evaluated for subsequent orders

#### Scenario: The screen is permission-controlled
- **WHEN** an administrator whose role lacks the order processing rules permission opens the screen
- **THEN** access is denied

#### Scenario: A rule's filters are summarised in the list
- **WHEN** a rule filters on customer group *Wholesale* and shipping method *Freight*
- **THEN** its list row summarises it as matching customer group *Wholesale* and shipping *Freight*

### Requirement: Diagnostics show rules and order outcomes

A diagnostics bundle SHALL include the *Report orders to TaxCloud* value per store view under its current name, the full rule list in evaluation order with each rule's filters, action and active state, and, when generated for an order, that order's stored outcome and the rule that decided it, or that it has none.

#### Scenario: Bundle for a non-reported order
- **WHEN** a diagnostics bundle is generated for an order whose outcome is *Calculate only*
- **THEN** the bundle states the outcome and the deciding rule, so a missing capture is explained without reading the logs
