## ADDED Requirements

### Requirement: Certificate forms are completable by someone meeting them for the first time

A person creating an exemption certificate is asserting something they may be held liable for, on a form they will see rarely. The forms SHALL therefore present every choice in terms a merchant recognises rather than in the identifiers the APIs exchange.

Covered states SHALL be chosen from a list of states, not typed. The purchaser's state SHALL be chosen from a list, as everywhere else in the admin. Exemption reasons and business types SHALL be shown with readable names, and SHALL match the names the WooCommerce plugin uses for the same values so the two products do not describe one certificate two ways.

Where a choice is a tax question rather than a software question — which reason applies, which business type fits — the form SHALL link to TaxCloud's guidance rather than leave the merchant guessing. The form SHALL NOT imply that the choices have been checked: nothing verifies an exemption claim.

The forms SHALL follow the surrounding admin and storefront conventions, so the panel reads as part of the page it sits on.

#### Scenario: States are chosen, not typed
- **WHEN** a merchant records which states an exemption applies in
- **THEN** they select from a list of states rather than entering abbreviations as text

#### Scenario: Choices read as words
- **WHEN** a reason or business type is offered
- **THEN** it is shown as readable text such as "Wholesale Trade" rather than as the value the API exchanges

#### Scenario: Tax questions carry guidance
- **WHEN** a merchant must pick an exemption reason or business type
- **THEN** guidance from TaxCloud is one click away, and the form does not suggest that the answer has been validated

### Requirement: Forms do not collect what the store cannot use

A field that is accepted and discarded is worse than an absent one: it tells a merchant something was recorded when nothing was. Certificate forms SHALL only ask for values that the store's transport can actually carry.

The purchaser's tax identification number SHALL NOT be collected. The v3 API has no field for it, so on a REST store it can be neither stored nor shown back — and TaxCloud does not retain the certificate document either, so the number remains on the signed paperwork the merchant keeps.

#### Scenario: No field is silently discarded
- **WHEN** a certificate is created on a store using either transport
- **THEN** every value the form collected is one that transport records, and nothing entered is dropped without the merchant being told

### Requirement: Exemption certificates are offered for the United States only

Every tax lookup in this module is limited to US destinations, so an exemption covering anywhere else could never apply. The forms SHALL make that limit visible rather than offering choices that cannot take effect.

#### Scenario: The limit is stated where it matters
- **WHEN** a merchant or customer records the states an exemption applies in
- **THEN** only US states are offered, and the form says that exemptions apply to US destinations
