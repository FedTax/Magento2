## Why

Every request the module sends to TaxCloud today goes out anonymous: the v1 SOAP
calls carry PHP's default `PHP-SOAP/x.y` and the v3 REST calls carry whatever
cURL defaults to. When a merchant opens a support ticket, TaxCloud cannot tell
from their traffic which extension version, Magento version, or PHP version
produced a request — so diagnosis starts with a round of questions that the
request itself should have answered. This matters more now that 1.4.0 ships two
transports side by side: "which version is this store on" is exactly the
question the v1/v3 rollout makes people ask.

WooCommerce solved this in DEV-7464; this is the Magento counterpart.

## What Changes

- Every outbound TaxCloud request carries a `User-Agent` identifying the
  extension, Magento (with edition), and PHP versions — for example:
  `TaxCloud-Magento2/1.4.0 Magento/2.4.7-p3 (Community) PHP/8.3.14`
- The string is built in one place and applied to all three transports:
  - the v3 REST client (all tax operations and both Test Connection pings),
  - the v3 Bearer token exchange (which also runs during `setup:upgrade`),
  - the v1 SOAP client — **both** its HTTP calls and its WSDL fetch, which are
    configured separately.
- A component whose version cannot be determined degrades to a documented
  placeholder rather than emitting an empty or malformed segment.
- The module's declared version is bumped to `1.4.0` in `composer.json` and
  `etc/module.xml`, and `CHANGELOG.md`'s `## Unreleased` section becomes
  `## 1.4.0`. Both declarations currently read `1.3.0` while the branch
  contains the unreleased 1.4.0 work, so without this the User-Agent would
  confidently report a version that is not the running code — worse for support
  than sending nothing.

## Capabilities

### New Capabilities
- `api-request-user-agent`: the identity every outbound TaxCloud request
  carries — which components it names, how it is formatted, which transports
  must apply it, and how unknown values degrade.

### Modified Capabilities
<!-- None. No existing capability's requirements change: routing, credential
     migration, connection testing and the tax operations all behave exactly as
     before; they simply send one additional header. -->

## Non-goals

- **Not** reporting the selected API type (`soap`/`rest`) in the User-Agent —
  the endpoint and protocol of the request already reveal it.
- **Not** the connection-record version sync (the `PATCH
  /mgmt/connections/{id}/options` work). That is a separate change, still
  blocked on TaxCloud confirming the option key.
- **Not** adding a configuration setting to disable or customise the
  User-Agent. It is diagnostic metadata with no secrets; a toggle would only
  create a way for the field to be silently missing.
- **Not** changing any request body, endpoint, timeout, retry or logging
  behaviour.
- **Not** introducing a release-automation process for version bumping. This
  change bumps the two declarations and pins them against drift with a test;
  automating releases is out of scope.

## Store-scoping implications

Deliberately **none** — and this is a conscious exception to the project's
"everything is store-aware" rule, not an oversight. Extension version, Magento
version, Magento edition and PHP version are properties of the installation,
not of a store view: there is no scope at which they could differ, and no
configuration read is involved. The builder therefore takes no `$store`
argument, which also lets its result be memoized for the request lifetime.

The transports it plugs into remain store-aware exactly as they are today — the
User-Agent is added alongside the store-resolved endpoint, credentials and
timeout without participating in that resolution.

## Impact

Affected code:
- `Model/Gateway/Rest/RestClient.php` — header assembly in `send()`, the single
  path all v3 operations and pings share.
- `Model/Gateway/Rest/TokenExchange.php` — its own `Curl` instance and headers.
- `Model/Gateway/Soap/SoapGateway.php` — `buildSoapOptions()`: the `SoapClient`
  `user_agent` option *and* the `http.user_agent` entry of the existing
  `stream_context` (the WSDL fetch goes through libxml and ignores the former).
- New: a User-Agent builder in `Model/Gateway/`, plus its `di.xml` wiring.

Dependencies: `Magento\Framework\App\ProductMetadataInterface` (version +
edition) and `Magento\Framework\Module\PackageInfo` (extension version) —
neither is currently injected anywhere in the module.

Version declarations: `composer.json`, `etc/module.xml`, `CHANGELOG.md`.

Not affected: request bodies, endpoints, authentication, retry rules, log
redaction (the User-Agent carries no credential material), and the SOAP and
REST response paths.
