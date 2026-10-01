## 1. Configuration

- [x] 1.1 Add `XML_PATH_CANADA_TAX_ENABLED` and `isCanadaTaxEnabled($store)` (flag AND api type REST, store scope) to `TaxcloudConfig`; default `0` in `etc/config.xml`
- [x] 1.2 Add the `canada_tax_enabled` select to `etc/adminhtml/system.xml` (all scopes, depends enabled + api_type=rest, comment about TaxCloud support and the access check)

## 2. Addresses and payloads

- [x] 2.1 `PostalCodeParser::parseCanadian()` returning `A1A 1A1` or null
- [x] 2.2 `RequestBuilder::buildCanadianDestination()`; `buildDestinationFromOrder($order, $allowCanada = false)` with the CA branch and "usable destination" log wording
- [x] 2.3 `RestRequestBuilder::toV3Address()` emits `countryCode` (`Country` key or `US`) and the Canadian postal code; `buildOrderPayload()` passes the store's Canada gate to the order destination builder and updates its error wording

## 3. Gateway and observers

- [x] 3.1 `RestGateway::lookupTaxes()`: country dispatch (US / enabled CA / other), region+city checks for both, no certificate resolution for CA, account-access hint on failed CA lookups
- [x] 3.2 `Observer/Sales/Address::verifyRestCarts()`: skip carts whose destination country is not US
- [x] 3.3 `Observer/Sales/RecordCertificate`: return early for orders whose destination country is not US

## 4. Canada access check

- [x] 4.1 `Model/Canada/CanadaAccessResult` and `Model/Canada/CanadaAccessChecker` (sample cart, classification per design D7)
- [x] 4.2 `Model/Canada/ConfigScopeStore` resolving the edited config scope to a store
- [x] 4.3 `Controller/Adminhtml/Canada/Check` (POST, `Magento_Tax::config_tax`) returning JSON
- [x] 4.4 `Block/Adminhtml/System/Config/CheckCanadaAccess` + `check_canada_access.phtml`; `check_canada_access` button field in `system.xml`
- [x] 4.5 `Observer/Adminhtml/CheckCanadaAccessOnSave` on `admin_system_config_changed_section_tax` (new `etc/adminhtml/events.xml`), running only on relevant changed paths, never breaking the save

## 5. Diagnostics

- [x] 5.1 `ApiProbe`: Canada flag in the group key; `canada_access` call entry for REST configurations with Canada on
- [x] 5.2 `SummaryRenderer::SUMMARY_SETTINGS` gains `canada_tax_enabled`

## 6. Tests

- [x] 6.1 Unit tests: `TaxcloudConfig::isCanadaTaxEnabled` (store scope vs ambient, SOAP override), `PostalCodeParser::parseCanadian`, `RequestBuilder` Canadian destination + order destination gating, `RestRequestBuilder` countryCode for US/CA and Canadian order payload, `RestGateway` lookup gates / certificate skip / failure hint, `Address` observer skip, `RecordCertificate` skip, `CanadaAccessChecker` classification, `ConfigScopeStore`, `Check` controller, save observer path filtering and exception safety, `ApiProbe` canada call; all compatible with PHPUnit 9.5/10.5/12.5
- [x] 6.2 Update existing unit tests whose expected v3 payloads/log wording change
- [x] 6.3 Run `make test-unit`, `make lint`, `make phpstan` and judge by exit code
- [x] 6.4 Propose integration coverage (mocked-gateway Canadian quote + capture; store-scoped setting) and e2e coverage (checkout to a Toronto address, Check Canada Access button) to the maintainer — do not write them unapproved

## 6b. Integration and e2e coverage (approved 2026-09-21)

- [x] 6.5 Integration: a recording REST transport double + `installRestMock()` harness; Canadian quote priced over v3 (destination shape, normalized postal code, no verify-address call, store-scoped gate), Canadian order captured/refunded/reversed over v3, and the config-save Canada check
- [x] 6.6 E2E: `canada-on` setup/teardown projects, guest checkout to a Toronto address showing Canadian tax, and the Check Canada Access button in the admin; page objects extended for a non-US country and the new button

## 7. Live verification

- [x] 7.1 On the dev stack (setting enabled via a temporary, reverted config override — not by writing the dev DB), run the checker against the dev connection and a Canadian quote lookup; confirm US lookups still price

## 8. Documentation

- [x] 8.1 New merchant page `docs/canadian-tax.md` (what it does, contacting TaxCloud support, Check Canada Access results, limits) + `mkdocs.yml` nav
- [x] 8.2 Update `docs/settings.md`, and any page stating the extension is US-only (checkout, exemptions, common problems, diagnostics)
- [x] 8.3 Update `README.md` developer material (diagnostics `probe.json` format) and add `CHANGELOG.md` entries
- [x] 8.4 `mkdocs build --strict` passes
