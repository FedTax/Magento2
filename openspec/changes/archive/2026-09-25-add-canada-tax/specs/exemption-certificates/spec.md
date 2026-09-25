## MODIFIED Requirements

### Requirement: Exemption certificates are offered for the United States only

Exemption certificates cover US states only, and a lookup for a destination outside the United States — including a Canadian destination priced under the `canada-tax` capability — SHALL NOT carry or record a certificate, so an exemption covering anywhere else could never apply. The forms SHALL make that limit visible rather than offering choices that cannot take effect.

#### Scenario: The limit is stated where it matters
- **WHEN** a merchant or customer records the states an exemption applies in
- **THEN** only US states are offered, and the form says that exemptions apply to US destinations

#### Scenario: A certificate holder shipping to Canada is taxed
- **WHEN** a customer holding an attached certificate places an order to a Canadian address in a store with Canadian tax enabled
- **THEN** the order is taxed and no certificate is applied or recorded
