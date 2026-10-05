## Why

On servers whose libcurl talks HTTP/1.1 to TaxCloud and predates the 1 MiB
`Expect` threshold (reproduced on RHEL/AlmaLinux/Rocky 8, libcurl 7.61.1),
every v3 REST request with a body over 1,024 bytes carries
`Expect: 100-continue`. TaxCloud correctly answers `100 Continue` before the
real status, and Magento's `Curl` client reports that interim `100` as the
response status. The extension then treats a successful call as failed.

For a tax lookup, that means any cart of roughly nine lines or more silently
falls back to Magento's own tax rates (or to no tax). Meanwhile TaxCloud records
a correct Lookup that the order never uses. This was found on a live merchant
(CXRE-132, order #3000021800, extension 1.4.0): their log shows
`Error encountered during lookupTaxes: HTTP 100`, and the order carries
Magento-rate tax with untaxed shipping instead of TaxCloud's amount. The same
misread affects every v3 request large enough to cross the threshold: order
capture, refunds, exemption certificates, and the credential exchange. Ship as
1.4.1 so merchants on 1.4.0 can take the fix without the 1.5.0 feature set.

## What Changes

- v3 REST requests (every operation sent through the REST client, and the V1→v3
  credential exchange) stop sending `Expect: 100-continue`. libcurl sends the
  body immediately and never asks TaxCloud for an interim response. This also
  saves one round trip on large requests.
- The HTTP client the REST transport uses reads the status of the **final**
  response. Any interim `1xx` response (`100 Continue`, `103 Early Hints`, or
  one injected by a proxy) is discarded together with its headers, instead of
  being reported as the outcome.
- Unit tests pin both behaviors, plus the core `Curl` behavior they work around.
- Release as 1.4.1 from the `v1.4.0` tag: version declarations bumped, and a
  CHANGELOG entry added.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `rest-tax-operations`: adds a requirement that the outcome of every v3 call
  (including the credential exchange) is the server's final response, so an
  interim `1xx` response can never turn a successful call into a failure.

## Non-goals

- **The Magento-rates fallback never taxing shipping.** The fallback hardcodes
  shipping to tax class 0. That is a separate defect, and a separate change.
- **The V1 SOAP transport.** It does not use libcurl through Magento's `Curl`
  client, and is not touched.
- **Any setting to toggle the behavior.** There is no legitimate reason to want
  the misread status, so the fix is unconditional.
- **Back-filling affected orders.** Identifying past orders that were taxed on
  fallback is a support task. The extension does not re-price placed orders.
- **Forward ports.** 1.5.1 and the unreleased line take this fix as separate
  follow-ups.

## Store-scoping implications

None. The change adds no configuration reads and alters no store resolution.
Every request keeps resolving its endpoint, credentials, auth mode and timeout
against the entity's store exactly as before. The fix is transport-level and
store-independent: it applies identically to every store.

## Impact

- **Code:** `Model/Gateway/Rest/RestClient.php` and
  `Model/Gateway/Rest/TokenExchange.php` (request headers, and the curl client
  they are given). There is one new class, a `Curl` subclass that keeps the
  final status line, created through its auto-generated factory.
- **Constructor signatures:** `RestClient` and `TokenExchange` take the new
  client's factory in place of `Magento\Framework\HTTP\Client\CurlFactory`. Both
  are internal classes resolved by DI; production mode needs
  `setup:di:compile`, as with any upgrade.
- **Tests:** existing unit tests that construct the two clients switch to the
  new factory. New unit tests cover the header and the status parsing.
- **Merchant-visible:** on affected servers, large carts get TaxCloud's tax
  instead of a fallback. Captures and refunds of large orders are no longer
  reported as failed. No settings, admin screens or documentation pages change.
- **Release:** `composer.json`, `etc/module.xml`, README version line,
  `CHANGELOG.md`.
