# canada-tax Specification

## Purpose
Lets a merchant whose TaxCloud account has Canada enabled collect and file Canadian sales tax (GST, HST, PST, QST) on orders shipped to Canada, while keeping the feature off until the merchant turns it on and giving them a way to confirm their account really has Canadian access.
## Requirements
### Requirement: Canadian tax is off until a merchant turns it on

A store-scoped setting "Calculate Canadian Tax" SHALL govern whether Canadian destinations are taxed, and it SHALL default to off, so an install gains no Canadian behavior by upgrading. The setting SHALL be configurable at default, website and store view scope and SHALL be resolved against the store of the entity being processed — the quote's store for a lookup, the order's store for capture, refunds and exempt re-creates — never the ambient store.

The setting SHALL take effect only for a store whose API type is V3 REST. For a store on V1 SOAP, Canadian tax SHALL behave as off regardless of the stored value, and the setting SHALL NOT be shown in the admin while V1 SOAP is selected.

While the setting is off for a store, every tax lookup, capture, refund and address verification for that store SHALL behave exactly as it did before this capability existed.

#### Scenario: Disabled by default
- **WHEN** a store upgrades without changing any setting and a customer checks out to a Canadian address
- **THEN** no Canadian tax is calculated, no request is sent to TaxCloud for that destination, and the order is not filed with TaxCloud

#### Scenario: Enabled for one store view only
- **WHEN** the setting is on for one store view and off at default, and customers in both store views check out to the same Canadian address
- **THEN** only the quote in the enabled store view is priced by TaxCloud

#### Scenario: Ignored on V1 SOAP
- **WHEN** the setting is stored as on for a store whose API type is V1 SOAP
- **THEN** Canadian destinations in that store are treated as if the setting were off

#### Scenario: Setting is resolved against the order's store at capture
- **WHEN** an admin in the default store view invoices an order placed in a store view that enables Canadian tax, while the default store view does not
- **THEN** the order is filed using the order's store view setting

### Requirement: The admin states that TaxCloud must also enable Canada

The setting's admin description SHALL tell the merchant that Canadian tax must also be enabled on their TaxCloud account, that this is done by contacting TaxCloud support, and that the Canada access check confirms it.

#### Scenario: Merchant reads the setting
- **WHEN** a merchant views the TaxCloud settings with V3 REST selected
- **THEN** the Calculate Canadian Tax setting explains that TaxCloud support must enable Canada on the account and points to the access check

### Requirement: Canadian destinations are priced when enabled

For a store with Canadian tax enabled, a tax lookup for a quote whose destination country is Canada SHALL be sent to TaxCloud with the destination's street, city, two-letter province code, postal code and country, and the tax TaxCloud returns SHALL be applied to the quote's product and shipping lines exactly as for a US destination.

The lookup SHALL NOT be sent, and a zero-tax result SHALL be returned, when the Canadian destination has no province, no city, or a postal code that is not a valid Canadian postal code (letter-digit-letter digit-letter-digit, space optional, case-insensitive). A valid postal code SHALL be sent in the uppercase `A1A 1A1` form.

Destinations in countries other than the United States and Canada SHALL continue to receive a zero-tax result without an API call.

#### Scenario: Ontario destination is taxed
- **WHEN** a customer in a store with Canadian tax enabled checks out to a Toronto, Ontario address with postal code `m5h2n2`
- **THEN** TaxCloud is asked to price the cart for province `ON`, postal code `M5H 2N2`, country `CA`, and the returned HST is applied to the products and shipping

#### Scenario: Invalid Canadian postal code
- **WHEN** a Canadian destination has postal code `12345`
- **THEN** no request is sent to TaxCloud and the quote receives zero tax, with a warning logged

#### Scenario: Missing province
- **WHEN** a Canadian destination has no province selected
- **THEN** no request is sent to TaxCloud and the quote receives zero tax

#### Scenario: Other countries are unchanged
- **WHEN** a customer in a store with Canadian tax enabled checks out to a Mexican address
- **THEN** no request is sent to TaxCloud and the quote receives zero tax

### Requirement: Canadian orders are filed, refunded and reversed

For a store with Canadian tax enabled at the time of the operation, an order whose destination is a Canadian address SHALL be filed with TaxCloud at capture with that Canadian destination, and credit memo refunds, tax-only refunds (including the exempt re-create) and cancellation reversals SHALL work for it exactly as for a US order. An order whose Canadian destination is unusable (no province, invalid postal code) SHALL fail to file and the reason SHALL be logged.

#### Scenario: Canadian order is captured
- **WHEN** an order shipped to a British Columbia address is captured in a store with Canadian tax enabled
- **THEN** a TaxCloud order is created whose destination carries province `BC` and country `CA`, carrying the tax the order charged

#### Scenario: Canadian tax-only refund
- **WHEN** a tax-only credit memo is issued for a captured Canadian order
- **THEN** the order is fully refunded in TaxCloud and re-created as exempt with the same Canadian destination

#### Scenario: Setting turned off before capture
- **WHEN** an order to a Canadian address was placed while Canadian tax was on, and the setting is turned off before the order's capture trigger fires
- **THEN** the order is not filed, and the log records that no usable destination could be resolved

### Requirement: Canadian destinations skip address verification

Address verification SHALL NOT be attempted for a Canadian destination, because TaxCloud's address verification supports US addresses only. The Canadian address SHALL be sent to the lookup as the customer entered it (with the postal code normalized), and verification of US destinations SHALL be unaffected.

#### Scenario: Verification enabled, Canadian cart
- **WHEN** address verification is enabled and a Canadian cart is priced
- **THEN** no address verification request is made for it, and the lookup still proceeds

### Requirement: Exemption certificates never apply to Canadian destinations

A lookup or filed order for a Canadian destination SHALL NOT carry an exemption certificate, and no certificate SHALL be recorded on such an order, regardless of any certificate the customer holds or has attached. Resolving this SHALL NOT require a call to TaxCloud.

#### Scenario: Exempt US customer ships to Canada
- **WHEN** a customer with an attached certificate covering several US states checks out to a Canadian address
- **THEN** the cart is taxed, no certificate is sent, and no certificate is recorded on the resulting order

### Requirement: A failed Canadian lookup points at account access

When a lookup for a Canadian destination fails at TaxCloud, the log entry SHALL, in addition to TaxCloud's reason, state that Canadian tax may not be enabled on the TaxCloud account and that TaxCloud support can enable it. The failure SHALL otherwise follow the store's existing lookup-failure behavior (Magento tax rates when fallback is enabled, zero tax otherwise).

#### Scenario: Account without Canada rejects the lookup
- **WHEN** TaxCloud rejects a Canadian lookup
- **THEN** the log shows TaxCloud's reason and a hint to confirm Canada is enabled on the account with TaxCloud support, and the store's fallback behavior applies

### Requirement: Merchants can check whether their account has Canadian access

The TaxCloud settings SHALL provide a "Check Canada Access" action, shown with the Canadian tax setting, that runs one sample tax lookup for the scope being edited, using that scope's saved credentials and shipping origin, to a fixed Canadian address for a general-goods item, and reports exactly one of:

- **Access confirmed** — TaxCloud returned a non-zero tax rate; the message includes the sample rate.
- **Not enabled** — TaxCloud answered but refused the Canadian lookup, or returned a zero rate (no province has a zero rate on general goods); the message tells the merchant to contact TaxCloud support to enable Canada and includes TaxCloud's reason when one was given.
- **Could not check** — credentials were rejected, the connection is unknown, TaxCloud could not be reached, the store is not on V3 REST, or the scope has no valid US shipping origin; the message names the problem and does not suggest contacting support about Canada.

The check SHALL only create or update a sample cart under a single fixed cart identifier, so repeated checks do not accumulate carts, and SHALL never file an order. The action SHALL be protected by the same admin permission as the tax configuration and SHALL require a POST with the admin form key.

#### Scenario: Account has Canada
- **WHEN** a merchant whose account has Canada enabled clicks Check Canada Access
- **THEN** the result reads as access confirmed and shows the sample rate

#### Scenario: Account without Canada
- **WHEN** TaxCloud rejects the sample lookup with a validation or permission error
- **THEN** the result says Canada is not enabled on the account and to contact TaxCloud support, including TaxCloud's reason

#### Scenario: Zero rate is not access
- **WHEN** TaxCloud accepts the sample lookup but returns a zero tax rate
- **THEN** the result says Canada is not enabled on the account

#### Scenario: Bad credentials are not reported as missing Canada access
- **WHEN** TaxCloud rejects the scope's credentials
- **THEN** the result says the check could not run because of the credentials, and does not tell the merchant to ask support to enable Canada

#### Scenario: Store on SOAP
- **WHEN** the check is run for a scope whose API type is V1 SOAP
- **THEN** the result says the check requires V3 REST, and no request is sent

#### Scenario: Check is scoped to the edited store view
- **WHEN** the check is run while editing a store view that has its own TaxCloud connection
- **THEN** the sample lookup uses that store view's connection and shipping origin

### Requirement: Saving the setting on verifies access without blocking

When the tax configuration is saved for a scope and, after the save, Canadian tax is effectively on for that scope, the Canada access check SHALL run if the Canadian tax setting, the API type or any V3 credential changed in that save. A confirmed result SHALL be shown as a success message; any other result SHALL be shown as a warning with the check's message. The configuration SHALL be saved regardless of the result, and a check that throws SHALL NOT break the save.

#### Scenario: Turning the setting on for an account without Canada
- **WHEN** a merchant saves Calculate Canadian Tax = Yes and the check reports not enabled
- **THEN** the setting is saved, and a warning says Canada is not enabled on the TaxCloud account and to contact TaxCloud support

#### Scenario: Unrelated save does not call TaxCloud
- **WHEN** a merchant with Canadian tax already on saves a change to the cache lifetime only
- **THEN** no Canada access check is run

