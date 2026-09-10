## Purpose

Lets an administrator find the right Taxability Information Code by describing what they are selling, instead of having to already know its number — in every place a TIC can be set, without ever preventing them from entering a code the system does not recognise.

## ADDED Requirements

### Requirement: TIC lookup is available wherever a TIC is set

Every administrative field that stores a TIC SHALL offer lookup: the product TIC, the category TIC, the store's default TIC, and the store's shipping TIC.

The interaction SHALL be the same in all four: the same way of searching, the same way results are presented, the same way a selection is applied, and the same way an unrecognised value is reported. An administrator who has learned the field in one place SHALL NOT have to learn it again in another.

#### Scenario: Lookup is offered on every TIC field
- **WHEN** an administrator edits any of the four TIC fields
- **THEN** the field offers lookup, presented and operated identically in each place

#### Scenario: Adding a TIC field later
- **WHEN** a new TIC-bearing field is introduced
- **THEN** it can adopt the same lookup without a second implementation of the behaviour

### Requirement: Searching by description

An administrator SHALL be able to search by describing the goods or service in their own words, and receive candidate TICs ranked with the most relevant first. Each candidate SHALL be presented with both its code and its description, so the code is never the only thing shown.

Searching SHALL also accept a TIC code directly. When the entered text is an exact code, that TIC SHALL be offered first, so an administrator who already knows the number is confirmed rather than made to search.

Where the underlying source provides further detail about a candidate — longer documentation, a natural-language description, a relevance score — that detail SHALL be shown alongside the candidate. Where it does not, the candidate SHALL be presented with the detail it does have, and the presentation SHALL remain well-formed.

#### Scenario: Searching by description
- **WHEN** an administrator types a description of what they sell
- **THEN** candidate TICs are listed most-relevant-first, each showing its code and description

#### Scenario: Searching by code
- **WHEN** an administrator types an exact TIC code
- **THEN** that TIC is offered first, identified by its description

#### Scenario: Selecting a candidate
- **WHEN** an administrator chooses a candidate
- **THEN** the field takes that TIC's code as its value, and the choice can still be edited or replaced afterwards

#### Scenario: Nothing matches
- **WHEN** a search returns no candidates
- **THEN** the administrator is told plainly, and whatever they have typed remains in the field untouched

### Requirement: A lookup in progress is visible

While a lookup is being performed the field SHALL show that it is working, and that indication SHALL clear once the lookup finishes, whatever its outcome.

A lookup that has been superseded or abandoned — because the query changed, because the administrator chose a suggestion, or because they cleared the field — SHALL NOT continue to appear in progress, and its late answer SHALL NOT be applied.

#### Scenario: Searching shows progress
- **WHEN** a lookup is underway
- **THEN** the field indicates that it is working

#### Scenario: Progress clears on completion
- **WHEN** a lookup returns results, returns nothing, or fails
- **THEN** the field no longer indicates that it is working

#### Scenario: A superseded lookup is abandoned
- **WHEN** an administrator picks a suggestion, or shortens the query below the length that triggers a search, while a lookup is still underway
- **THEN** that lookup's answer is discarded rather than repopulating the field, and the progress indication clears

### Requirement: Lookup follows the store's configured API

Lookup SHALL be served through the API generation configured for the store whose value is being edited, resolved against that store and never against the ambient one. A store configured for the current API SHALL be served by its TIC search; a store configured for the legacy API SHALL be served from that API's TIC list.

The two sources SHALL NOT be expected to return equivalent results: one performs semantic search over richer TIC metadata, the other matches text against a short description. The same query MAY therefore return different candidates depending on the store's configured API. This difference is intended and SHALL NOT be treated as a defect.

Retrieving the legacy API's TIC list SHALL NOT require a request per search: the list SHALL be reused across searches for a bounded period, and MAY be refreshed in the background.

#### Scenario: Store on the current API
- **WHEN** an administrator searches while editing a value for a store configured to use the current API
- **THEN** the results come from that API's TIC search, resolved with that store's credentials

#### Scenario: Store on the legacy API
- **WHEN** an administrator searches while editing a value for a store configured to use the legacy API
- **THEN** the results come from that API's TIC list, matched against the query

#### Scenario: Mixed store configurations
- **WHEN** two stores are configured for different API generations and an administrator edits a store-scoped TIC value for each
- **THEN** each search is served by its own store's configured API, not by whichever store happens to be active

#### Scenario: Repeated searching does not repeat retrieval
- **WHEN** an administrator performs several searches in a row against the legacy API
- **THEN** the TIC list is not retrieved afresh for each search

### Requirement: Stored values stay freeform

A TIC field SHALL continue to accept and store any value an administrator types. Lookup SHALL be a way to fill the field, never a restriction on it.

A value that lookup does not recognise SHALL be preserved exactly as entered, SHALL NOT be cleared, corrected or substituted, and SHALL NOT prevent the record from being saved. The administrator SHALL be told that the value was not found and that it will be saved as entered.

That notice SHALL be presented as information rather than as an error, and SHALL be distinguishable from a genuine failure such as lookup being unavailable.

#### Scenario: Unrecognised value is kept
- **WHEN** an administrator enters a TIC that lookup does not recognise and saves
- **THEN** the value is stored exactly as entered, and the administrator was told it was not found and would be saved as entered

#### Scenario: Saving is never blocked
- **WHEN** a TIC field holds an unrecognised value
- **THEN** saving the product, category or configuration succeeds

#### Scenario: Existing values are never rewritten
- **WHEN** a record with a previously stored TIC is opened and saved without touching the TIC field
- **THEN** the stored value is unchanged, whether or not lookup recognises it

### Requirement: A stored code is shown with its meaning

When a field holds a TIC that lookup recognises, its description SHALL be displayed with the field, so that an administrator returning to a previously configured value can see what it means without leaving the page.

#### Scenario: Stored code is explained
- **WHEN** an administrator opens a record whose TIC field holds a recognised code
- **THEN** the code's description is shown alongside the field

#### Scenario: Unrecognised stored code
- **WHEN** the stored code is not recognised
- **THEN** the field reports it as not found rather than showing a misleading description, and the value remains

### Requirement: An empty field shows what will be used instead

Where a TIC field is empty and the system falls back to another value, the field SHALL show which value will apply and where it comes from.

#### Scenario: Empty product TIC
- **WHEN** an administrator views a product whose TIC is empty
- **THEN** the field shows the value that will be used instead and its origin

#### Scenario: Empty field with no fallback chain
- **WHEN** a TIC field has no inherited source and falls back only to its configured default
- **THEN** the field says so, rather than implying an inheritance that does not exist

### Requirement: Lookup degrades without obstructing work

When lookup cannot be performed, the field SHALL remain fully usable as a plain text field, and the administrator SHALL be told that lookup is unavailable. Saving SHALL be unaffected. This SHALL hold whenever lookup cannot run, including when no credentials have been entered yet, when the configured credentials are not accepted, and when TaxCloud cannot be reached.

Where the reason is that the store has not yet been configured — credentials not entered or not yet saved — the administrator SHALL be told specifically that, rather than being shown a generic unavailability message. The configuration screen presents the TIC fields and the credential fields together, so this is the ordinary state during first-time setup and not an edge case.

A failure of lookup SHALL NOT block saving, SHALL NOT clear the field, and SHALL NOT be presented as an error against the value the administrator has entered.

#### Scenario: No credentials yet
- **WHEN** an administrator sets a TIC on a store with no TaxCloud credentials saved
- **THEN** the field works as a plain text field and explains that credentials must be saved before lookup is available

#### Scenario: TaxCloud unreachable
- **WHEN** lookup cannot reach TaxCloud
- **THEN** the field works as a plain text field, says lookup is unavailable, and saving still succeeds

#### Scenario: Failure does not disturb the entered value
- **WHEN** lookup fails while a TIC field holds a value
- **THEN** the value is left exactly as it was, and no error is raised against it

### Requirement: Lookup does not disclose credentials or bypass permissions

TIC lookup SHALL be available only to administrators already permitted to edit the field it serves, and SHALL NOT expose TaxCloud credentials to the browser.

#### Scenario: Credentials are not exposed
- **WHEN** lookup is performed
- **THEN** no TaxCloud credential value is disclosed to the browser

#### Scenario: Lookup respects administrative permissions
- **WHEN** a request for lookup is made by a session without permission to edit TaxCloud settings
- **THEN** it is refused
