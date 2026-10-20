## ADDED Requirements

### Requirement: A cart line's item ID fits TaxCloud's limit

Every product line sent to TaxCloud SHALL carry an item ID of at most 50 characters, counted in UTF-16 code units. This is the stricter of the two APIs' measures, so an ID within it is accepted by both v1 SOAP and v3 REST.

A line whose SKU is 50 units or fewer SHALL be identified by its SKU, unchanged. A line whose SKU is longer SHALL be identified by:
- the longest prefix of its SKU that is at most 39 units and does not split a character,
- the character `~`,
- the first 10 lowercase hexadecimal characters of the SHA-1 digest of the SKU's first 255 characters.

The `shipping` line and the Colorado Retail Delivery Fee line keep their fixed identifiers.

#### Scenario: A SKU that fits is sent unchanged
- **WHEN** a line's SKU is 50 characters or fewer
- **THEN** its item ID is exactly its SKU

#### Scenario: A long SKU is shortened to exactly 50 characters
- **WHEN** a line's SKU is longer than 50 characters, such as a Dynamic SKU bundle `planner_bundle-clos-it-217000-850573-850634-217154-RR-217307-217116-RR-216294-RR-216287-RR`
- **THEN** its item ID is the SKU's first 39 characters followed by `~` and 10 hexadecimal characters, 50 characters in all, and TaxCloud accepts the request

#### Scenario: Configurations sharing a prefix stay distinct
- **WHEN** two lines' SKUs are both longer than 50 characters and share their first 50 characters
- **THEN** their item IDs differ

#### Scenario: Multibyte SKUs are measured as TaxCloud measures them
- **WHEN** a SKU contains characters outside the Basic Multilingual Plane
- **THEN** each such character counts as two units toward the limit, and the shortened prefix never splits a character

#### Scenario: SKUs beyond the database column length agree with their stored form
- **WHEN** a SKU is longer than the 255 characters Magento stores for it
- **THEN** its item ID is the same whether derived from the in-memory SKU or from the stored, truncated one

### Requirement: Every payload for an order derives item IDs the same way

The item ID SHALL be derived from the line's SKU alone, with no stored state, and SHALL be applied by every payload that names product lines:
- the tax lookup,
- the v3 order filed at capture,
- the return built from a credit memo,
- the adjustment-only distribution,
- the full-order cart used for full returns and exempt re-creates.

A line with a long SKU SHALL therefore be named by the same item ID from lookup through refund.

#### Scenario: A long-SKU line is refunded under the ID it was sold under
- **WHEN** an order containing a line with a SKU longer than 50 characters is looked up, captured and then refunded
- **THEN** the lookup, the filed order and the refund all name that line by the same item ID

#### Scenario: Existing orders keep matching
- **WHEN** an order placed before this rule, whose SKUs all fit, is refunded
- **THEN** the refund names its lines by their unchanged SKUs, matching what TaxCloud recorded
