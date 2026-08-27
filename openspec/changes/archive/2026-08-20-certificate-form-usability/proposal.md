## Why

The certificate forms work but were built by someone who already knew the answers. An administrator meeting them for the first time gets a free-text box asking for comma-separated state codes, two dropdowns of `WholesaleTrade`-style identifiers with no explanation of what they mean or which to pick, a hand-rolled layout that does not look like the rest of the admin, and a Tax ID field that on a V3 REST store is silently discarded.

None of it is wrong. All of it makes an unfamiliar, legally consequential task harder than it needs to be — and the person filling this in is asserting an exemption they may be liable for.

## What Changes

- **States become a multiselect** of real state names rather than a comma-separated text box.
- **The address state becomes a dropdown**, as it is everywhere else in Magento.
- **Both forms follow standard admin conventions** instead of hand-rolled markup, so the panel reads as part of the customer page rather than bolted onto it.
- **Business type and exemption reason show human labels** — "Wholesale Trade", not `WholesaleTrade` — matching the labels the WooCommerce plugin already uses, so the two products describe the same certificate the same way.
- **Each of those fields links to TaxCloud's guidance**, because the right answer is a tax question the software cannot answer for the merchant.
- **The Tax ID field is removed.** V1 stores it; **V3 has no field for it at all**, so on a REST store it is accepted and silently discarded, and cannot even be shown back. It looks meaningful and does nothing on the transport the product is moving to. TaxCloud does not keep the certificate document either — the tax id lives on the signed paperwork the merchant retains.
- **The forms state that exemptions are US-only**, which they have always been.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `exemption-certificates`: the capability describes what the certificate forms must collect but says nothing about whether a person can reasonably complete them. This adds requirements for how the choices are presented and what is asked for at all.

## Non-goals

- **Canada, or any non-US support.** Both this module and the WooCommerce plugin hard-gate every tax lookup to `US`, and have since long before exemptions existed — `Model/Api.php:325`, `RequestBuilder.php:457`, and Woo's `class-sst-abstract-cart.php:666` and hardcoded `Address::$countryCode = 'US'`. TaxCloud's v3 API does accept `CA`, which is worth asking them about, but changing that boundary is a product decision spanning both integrations and is not form polish.
- **Changing what a certificate means or how it is resolved.** This is presentation only; the resolution, ownership and gating behaviour stay exactly as they are.
- **Rebuilding the panel as a Magento UI component.** The admin panel loads its data over AJAX for reasons that still hold (a live TaxCloud call must not be able to make the customer page slow or unloadable). Standard *styling* and *field types* are the goal, not a rewrite onto a different rendering stack.

## Store-scoping implications

None new. The forms already act on the store resolved by the surrounding page, and nothing here changes which store a certificate is created against.

## Impact

- `Model/Certificate/CertificateFormReader.php` — the enums gain display labels; `taxId`/`taxType` stop being read.
- `Model/Certificate/SoapCertificateGateway.php` — stops sending a tax id it no longer collects.
- `Block/Adminhtml/Customer/Edit/Tab/Certificates.php`, `Block/Certificate/Manage.php` — supply labelled options and the region source.
- `view/adminhtml/templates/customer/certificates.phtml`, `view/frontend/templates/certificate/manage.phtml` — standard field markup, multiselect, region dropdown, guidance links.
- `Test/Unit/Model/Certificate/CertificateFormReaderTest.php` — the tax-id expectations go; label coverage arrives.
- **Behavioural**: certificates created after this change carry no tax id on V1 stores, where previously they did. Existing certificates are untouched, and nothing about which certificate exempts an order changes.
