## Purpose

Detects whether TaxCloud is the tax total collector Magento will actually run for a
store, and surfaces that verdict to merchants and support staff, so that another
module displacing TaxCloud stops being a silent failure that only shows up as
under-collected tax.

## ADDED Requirements

### Requirement: Active tax collector verdict
The extension SHALL determine, for each store where TaxCloud is enabled, whether
the tax total collector Magento will execute is TaxCloud's own. The verdict SHALL
reflect the collector set as Magento actually resolves it, so that both a competing
class registered for the tax total and a competing object-manager preference on the
tax total class are detected. When TaxCloud is not the collector, the verdict SHALL
report the fully-qualified class name that occupies the slot.

#### Scenario: TaxCloud owns the tax total
- **WHEN** the verdict is computed for a store where TaxCloud is enabled and no other module has claimed the tax total
- **THEN** the verdict for that store is healthy and names TaxCloud as the active collector

#### Scenario: Another module has claimed the tax total
- **WHEN** another module registers a different class for the tax total, or overrides the tax total class with its own preference
- **THEN** the verdict for that store is unhealthy and reports the fully-qualified class name that occupies the slot

#### Scenario: The tax total is absent entirely
- **WHEN** no class occupies the tax total slot
- **THEN** the verdict for that store is unhealthy and reports that the tax total collector is missing

#### Scenario: Only enabled stores are evaluated
- **WHEN** TaxCloud is disabled for a store
- **THEN** that store is excluded from the verdict, and its configuration cannot make the overall verdict unhealthy

#### Scenario: Enablement resolves per store
- **WHEN** TaxCloud is enabled for one store and disabled for another
- **THEN** only the enabled store appears in the verdict, regardless of which store is ambient when the verdict is computed

### Requirement: Interception and overwrite detection
The verdict SHALL additionally report two conditions that leave TaxCloud registered
as the collector while still preventing its result from reaching the customer: an
interceptor wrapping the tax collection call, which can suppress it, and any
collector that runs after the tax total and is capable of replacing the computed
tax. Each condition SHALL name the responsible class or classes.

#### Scenario: An interceptor wraps tax collection
- **WHEN** a module registers an interceptor around the tax collection call
- **THEN** the verdict reports that interception is present and names the intercepting class

#### Scenario: A later collector may overwrite tax
- **WHEN** a non-Magento total collector is ordered to run after the tax total
- **THEN** the verdict reports that collector as a potential overwrite and names it

#### Scenario: Neither condition present
- **WHEN** no interceptor wraps tax collection and no non-Magento collector runs after the tax total
- **THEN** the verdict reports both conditions as clear

### Requirement: Runtime verification at order placement
The extension SHALL record whether its own tax collection ran while a quote's
totals were collected, and SHALL check that record when the quote becomes an order.
When TaxCloud is enabled for the quote's store and its tax collection did not run,
the extension SHALL write a warning to the TaxCloud log naming the condition. The
check SHALL resolve enablement against the quote's own store rather than the
ambient store. Warnings SHALL be rate limited per store so that a store placing
many orders does not repeat the same warning on every order.

#### Scenario: TaxCloud collection ran
- **WHEN** an order is placed from a quote whose totals were collected by TaxCloud
- **THEN** no warning is written

#### Scenario: TaxCloud collection did not run
- **WHEN** an order is placed from a quote for a store where TaxCloud is enabled, and TaxCloud's tax collection never ran for that quote
- **THEN** a warning is written to the TaxCloud log identifying the store and stating that TaxCloud is enabled but is not the active tax collector

#### Scenario: Disabled store places an order
- **WHEN** an order is placed from a quote for a store where TaxCloud is disabled
- **THEN** no warning is written

#### Scenario: Enablement follows the quote's store
- **WHEN** an order is placed through the admin or an API context where the ambient store differs from the quote's store
- **THEN** the check uses the quote's store to decide whether TaxCloud is enabled

#### Scenario: Repeated failures are rate limited
- **WHEN** multiple orders are placed for the same store within the rate limit window while TaxCloud is not the active collector
- **THEN** at most one warning is written for that store in that window

### Requirement: Admin notification of an unhealthy verdict
The admin SHALL display a notification whenever the verdict is unhealthy. The
notification SHALL state which condition was found, name the responsible class,
list the affected stores, link to the troubleshooting documentation, and offer a
link that dismisses it. The notification SHALL be raised at critical severity. When
the verdict is healthy no notification SHALL be displayed.

#### Scenario: Unhealthy verdict raises the notification
- **WHEN** an admin loads any admin page while the verdict is unhealthy
- **THEN** a critical notification names the condition, the responsible class, and the affected stores, and offers a documentation link and a dismissal link

#### Scenario: Healthy verdict shows nothing
- **WHEN** an admin loads any admin page while the verdict is healthy
- **THEN** no TaxCloud collector notification is displayed

#### Scenario: Multiple conditions at once
- **WHEN** more than one unhealthy condition is present
- **THEN** the notification reports each condition

### Requirement: Dismissal acknowledges a specific conflict
Dismissing the notification SHALL record an acknowledgement of the exact conflict
that was displayed, identified by the responsible classes and the affected stores.
The notification SHALL stay hidden while the verdict continues to match the
acknowledged conflict, and SHALL reappear without further action once the verdict
differs — including when the same kind of conflict is caused by a different class,
or when it begins affecting a store it did not affect before. Dismissal SHALL take
effect immediately for the admin who dismissed it and for all other admins.

#### Scenario: Dismissing hides the notification
- **WHEN** an admin follows the dismissal link
- **THEN** the notification is no longer displayed on subsequent admin pages while the conflict is unchanged

#### Scenario: A different class causes the conflict later
- **WHEN** a conflict has been dismissed and a different module subsequently takes the tax collector slot
- **THEN** the notification is displayed again, naming the new class

#### Scenario: The conflict spreads to another store
- **WHEN** a dismissed conflict later affects a store it did not affect at dismissal time
- **THEN** the notification is displayed again, listing the updated store set

#### Scenario: The conflict is resolved and returns
- **WHEN** a dismissed conflict is resolved so the verdict becomes healthy, and the same conflict later reappears
- **THEN** the notification is displayed again

### Requirement: Diagnose command
The extension SHALL provide a command-line command that reports the full verdict:
per store, the active tax collector, any interception, and any later collector that
could overwrite tax. The command SHALL report the verdict regardless of whether the
admin notification has been dismissed. The command SHALL exit with a non-zero status
when the verdict is unhealthy, so it can be used in automated checks.

#### Scenario: Healthy store reported
- **WHEN** the command runs while TaxCloud is the active collector for every enabled store
- **THEN** it reports each enabled store as healthy and exits with a zero status

#### Scenario: Unhealthy store reported
- **WHEN** the command runs while another module occupies the tax collector slot
- **THEN** it names that class and the affected stores and exits with a non-zero status

#### Scenario: Dismissal does not affect the command
- **WHEN** the command runs after an admin has dismissed the notification for the current conflict
- **THEN** it still reports the conflict and still exits with a non-zero status

### Requirement: A healthy verdict is not a correctness claim
A healthy verdict means only that TaxCloud's tax collection will run. Admin
notifications, command output, and documentation SHALL NOT state or imply that a
healthy verdict means tax is being calculated correctly, since credential failures
and the Magento tax fallback can still produce no TaxCloud tax on a healthy verdict.

#### Scenario: Command output is qualified
- **WHEN** the command reports a healthy verdict
- **THEN** its output states that the check covers collector ownership only and does not verify credentials or calculation

### Requirement: Diagnostics never burden the storefront
Computing the verdict SHALL NOT occur on the storefront request path. The runtime
record of whether TaxCloud's tax collection ran SHALL NOT require resolving the
collector set, and SHALL add no external calls, persisted writes, or cache reads to
tax collection.

#### Scenario: Checkout does not compute the verdict
- **WHEN** a customer collects totals or places an order
- **THEN** no tax collector verdict is computed during that request

### Requirement: Probe failure is itself reported
If the verdict cannot be computed — for example because resolving the collector set
raises an error — the extension SHALL treat that as an unhealthy verdict describing
the failure, and SHALL NOT allow the error to interrupt the admin page, the order,
or the command.

#### Scenario: Resolving the collector set fails
- **WHEN** an error is raised while resolving the collector set
- **THEN** the verdict reports that diagnostics could not be computed, including the reason, and the surrounding request completes normally
