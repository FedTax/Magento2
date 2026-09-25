## Why

Merchants selling into Canada collect no tax on those orders: every lookup and every filed order stops at a hard "US only" gate, Canadian postal codes fail the 5-digit ZIP validation, and v3 requests never state a country (TaxCloud assumes US and rejects a Canadian postal code with HTTP 422). The TaxCloud v3 API already prices Canadian destinations correctly when Canada is enabled on the account — verified live on the dev connection: ON 13%, BC 12%, QC 14.975%, AB 5%, shipping taxed, CAD or USD accepted. Canada is an account-level add-on that TaxCloud support switches on, so the module must not assume it: the feature is opt-in, and the merchant needs a way to confirm their account really has it.

## What Changes

- New store-scoped setting **Calculate Canadian Tax** (Yes/No, **default No**), shown only for the V3 REST API type. Its admin comment states that Canada must also be enabled on the TaxCloud account by contacting TaxCloud support.
- New admin button **Check Canada Access** that runs one real sample lookup to a fixed Canadian address against the scope's saved settings and reports one of: access confirmed (with the sample rate), not enabled on the account (contact TaxCloud support), or could not check (credentials / network / origin problem).
- The same check runs automatically when the tax configuration is saved with the setting on (or with credentials changed while it is on); a failure shows an admin warning but never blocks the save.
- When the setting is on for the entity's store:
  - Tax lookups price Canadian destinations (province and a valid Canadian postal code required); other non-US countries keep receiving zero tax without an API call.
  - Canadian orders are filed with TaxCloud at capture, and refunds, tax-only exempt re-creates and cancellation reversals work for them as for US orders.
- Every v3 address sent (origin, destination) carries an explicit country code — `US` for US addresses, `CA` for Canadian ones.
- Address verification is skipped for Canadian destinations (the v3 verify-address endpoint answers "unsupported country code").
- Exemption certificates are never applied to Canadian destinations (certificates cover US states only).
- A failed Canadian lookup logs a hint that Canada may not be enabled on the TaxCloud account.
- The diagnostics bundle shows the setting in its summary and, for stores with it on, runs the Canada access check as part of the live probe.
- Merchant docs: new "Canadian tax" page; settings reference and README updated.
- With the setting off (the default), behavior is unchanged from today, and SOAP stores are unaffected.

## Non-goals

- Separate GST / HST / PST / QST amounts: TaxCloud returns one combined rate per line, so Magento shows one tax amount.
- Canadian exemption status (e.g. First Nations, resale registrations) and Canadian exemption certificates.
- Countries other than the United States and Canada.
- Canadian tax over the V1 SOAP API.
- A shipping origin outside the United States.
- Address verification for Canadian addresses.
- Determining Canada access from an account/feature endpoint: none exists (`/mgmt/connections/{id}` returns only id and name), so access is inferred from a sample lookup.

## Store-scoping implications

- The setting is `showInDefault/Website/Store` and resolved against the store of the entity being processed: the quote's store for lookups, the order's store for capture, exempt re-create and refunds, the edited config scope for the admin check and save-time check, each probed store for diagnostics.
- A store view may enable Canada while its siblings do not; a Canadian quote in a store without it is untaxed exactly as today.
- The effective setting also requires that store's API type to be REST, so a store view overriding the API type to SOAP never sends Canadian requests.
- Capture reads the setting from the order's store at capture time; an order placed while the setting was on but captured after it was turned off is not filed (logged), which the docs call out.

## Capabilities

### New Capabilities
- `canada-tax`: opt-in Canadian tax calculation — the store-scoped setting and its REST-only gate, Canadian destinations in lookups and filed orders, Canadian postal code validation, the Canada access check (admin button and save-time), and the limits (no address verification, no exemptions).

### Modified Capabilities
- `rest-tax-operations`: the lookup pre-flight gate admits Canadian destinations when enabled; v3 addresses carry a country code; address verification is not attempted for non-US destinations.
- `tax-address-sourcing`: an order's destination resolves to a Canadian address when the order's store has Canadian tax enabled, instead of failing as non-US.
- `exemption-certificates`: the US-only limit is restated now that lookups are no longer US-only — certificates never apply to a Canadian destination.
- `diagnostics-bundle`: the live probe reports Canada access for stores that enable it, and the summary lists the setting.

## Impact

- **Config**: `etc/adminhtml/system.xml` (new field + button), `etc/config.xml` (default 0), `Model/Config/TaxcloudConfig.php` (new accessor).
- **Gateway**: `Model/Gateway/Rest/RestGateway.php` (lookup gate, certificate skip, failure hint), `Model/Gateway/Rest/RestRequestBuilder.php` (country code, Canadian order destination), `Model/Gateway/RequestBuilder.php` (Canadian destination builder), `Model/PostalCodeParser.php` (Canadian format).
- **Observers**: `Observer/Sales/Address.php` (skip non-US carts), `Observer/Sales/RecordCertificate.php` (skip non-US orders), new adminhtml save-time observer.
- **Admin**: new checker model, controller, block and template for the Check Canada Access button.
- **Diagnostics**: `Model/Diagnostics/Bundle/Probe/ApiProbe.php`, `Model/Diagnostics/Bundle/Summary/SummaryRenderer.php`.
- **Unchanged**: SOAP gateway (`Model/Api.php`), Colorado Retail Delivery Fee (US/CO only), Magento-rates fallback.
- **Cache**: v3 lookup cache keys change once for US carts because the payload gains `countryCode`; entries simply miss once and repopulate.
- **Docs**: `docs/` new Canadian tax page + `mkdocs.yml` nav, settings reference, `README.md`, `CHANGELOG.md`.
- **Open question for TaxCloud support** (does not block implementation): the exact response an account without Canada returns for a Canadian lookup. The check treats both an error response and a 0% rate on a general-goods sample as "not enabled" (no Canadian province has a 0% rate on general goods), so either behavior is classified correctly.
