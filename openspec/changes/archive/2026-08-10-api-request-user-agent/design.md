## Context

See proposal.md — Why. What shapes the approach is that the module has three
independent outbound HTTP paths, each configured differently:

| Path | Mechanism | Covers |
|---|---|---|
| `Model/Gateway/Rest/RestClient::send()` | `Magento\Framework\HTTP\Client\Curl`, headers assembled at one point | every v3 operation, both Test Connection pings |
| `Model/Gateway/Rest/TokenExchange::exchange()` | its own `Curl` instance | Bearer token mint, incl. during `setup:upgrade` |
| `Model/Gateway/Soap/SoapGateway::buildSoapOptions()` | PHP `SoapClient` options + a `stream_context` | every v1 operation **and** the WSDL fetch |

There is no shared chokepoint below these three: the REST paths and the SOAP
path do not meet until the gateway interfaces above them. Any solution
therefore touches three places, and the design question is how to keep them
from drifting apart rather than how to find one hook.

Relevant verified facts about the platform:

- `Magento\Framework\HTTP\Client\Curl` maps `_headers` onto `CURLOPT_HTTPHEADER`
  (`Curl.php:145`, `:370-375`) and never sets `CURLOPT_USERAGENT`. Adding a
  `User-Agent` header is therefore sufficient and cannot produce a duplicate.
- `Magento\Framework\Module\PackageInfo::getVersion($module)` reads the
  `version` field of the module's own `composer.json` (`PackageInfo.php:113`,
  `:283-287`) and returns `''` when absent. It does **not** read
  `etc/module.xml`.
- `Magento\Framework\App\ProductMetadata::getVersion()` falls back to the
  literal string `'UNKNOWN'` when it cannot resolve a version
  (`ProductMetadata.php:84`), and caches the result in Magento's config cache.
- `getEdition()` returns the `EDITION_NAME` constant — `'Community'` in Open
  Source (`ProductMetadata.php:23`); Adobe Commerce preferences a class
  returning `'Enterprise'`.

## Goals / Non-Goals

**Goals:**
- One place that decides what the User-Agent says; three thin call sites that
  cannot express an opinion of their own.
- Structural coverage: a new operation on either transport inherits the header
  without anyone remembering it.
- Containment: nothing about building this header can fail a tax lookup.

**Non-Goals:**
- A general-purpose outbound-HTTP interception layer for the module.
- Reporting anything beyond the four components in the spec (no OS, no
  hostname, no store or connection identifiers).
- Reconciling `etc/module.xml`'s `setup_version` with composer as a *runtime*
  concern — it is a release-hygiene check here, not an input to the header.

## Decisions

### D1: A single value-producing collaborator, injected as a shared instance

Add one class under `Model/Gateway/` exposing a single no-argument accessor
returning the finished string, memoized in a property. Magento's DI returns
shared instances by default, so all three transports get the same object and
the string is assembled at most once per request.

No `$store` parameter — per the spec, identification is installation-wide.
This is the one deliberate departure from the project's store-aware rule and
the reason it is safe to memoize; proposal.md states it explicitly so the
absence of a scope argument does not read as a bug in review.

*Alternative considered:* a static helper or a constant assembled at
compile time. Rejected — the values come from DI collaborators, and a static
would be untestable across the PHPUnit 9.5/10.5/12.5 matrix.

### D2: Extension version from `PackageInfo`, not `module.xml`

`PackageInfo::getVersion('Taxcloud_Magento2')` reads the module's
`composer.json`, which is also what a Composer-distributed install resolves.
That makes `composer.json` the single source of truth for what the header
reports.

*Alternative considered:* `ModuleListInterface::getOne()['setup_version']`
(i.e. `etc/module.xml`). Rejected — `setup_version` is optional under
declarative schema, is a schema-migration marker rather than a release
version, and is not what Composer distributes. It is still kept in sync as a
release-hygiene matter (D5), just not read at runtime.

### D3: Three explicit call sites, no framework-wide interception

Add the header at each of the three paths in the Context table.

*Alternative considered:* a `di.xml` plugin on
`Magento\Framework\HTTP\Client\Curl`. Rejected on two counts: that class is
shared with every other module and core service, so the plugin would stamp our
identity onto unrelated traffic; and it would not cover the SOAP path at all,
leaving the split we were trying to close.

The structural-coverage requirement is met not by a hook but by *where* the
two REST call sites sit: `RestClient::send()` is the single method every v3
operation and both pings already funnel through, and `TokenExchange` has
exactly one outbound call. A new v3 operation cannot bypass them.

### D4: SOAP sets the user agent in both places, for redundancy — not necessity

`buildSoapOptions()` gains a `user_agent` option, and the existing
`stream_context` gains an `http.user_agent` entry.

**This decision was written on a hypothesis that measurement refuted.** The
original rationale was that `SoapClient` uses the `user_agent` option for its
own HTTP requests while the WSDL document is fetched through the stream
context, which ignores it — so setting only one would leave the WSDL retrieval
unidentified.

Measured against a local HTTP server that logs the received `User-Agent`, on
PHP 8.1.34, 8.2.31, 8.3.31 and 8.4.2 (the module's supported range), all three
configurations identify **both** requests:

| configuration | WSDL fetch | SOAP call |
|---|---|---|
| `user_agent` option only | ours | ours |
| `stream_context` only | ours | ours |
| both (shipped) | ours | ours |

PHP propagates the `user_agent` option into the context it builds for the WSDL
fetch, and honours a supplied `stream_context` for the SOAP call. Either
placement alone would satisfy the spec.

Both are kept regardless: they are independent code paths inside ext-soap, the
cost is nil, and neither request then depends on that propagation continuing to
hold in a future PHP release. They are read from one source, so they cannot
disagree. What is *not* claimed any more is that the doubling is required.

Consequence: the documented fallback (accept an unidentified WSDL fetch) is
moot — there is no supported version on which it is needed.

### D5: `composer.json` is the source of truth; drift is a test failure

Bump `composer.json` and `etc/module.xml` to `1.4.0` and promote the
CHANGELOG's `## Unreleased` heading. Pin them with a unit test asserting all
three agree.

The spec requires that a discrepancy be "detectable automatically rather than
discovered from support traffic"; a test in the existing suite satisfies that
without inventing release tooling. The test reads the files rather than
hardcoding `1.4.0`, so the next bump does not have to edit it.

*Alternative considered:* leave `module.xml` at `1.3.0` since it is unread by
this feature. Rejected — two version declarations disagreeing inside one module
is the exact confusion this change exists to end.

### D6: Degrade at the component level, inside the builder

Each component resolves independently; an empty result, or the literal
`'UNKNOWN'` that `ProductMetadata` emits, is normalised to `unknown`. The whole
assembly is wrapped so that any `Throwable` still yields a valid string built
from whatever resolved.

Memoizing the result means a degraded outcome is computed once, not retried per
request — the header stays stable across a request even if a collaborator is
misbehaving.

## Risks / Trade-offs

- **`ProductMetadata::getVersion()` returns `'UNKNOWN'` on some Composer
  layouts** → normalised to `unknown` (D6); the header stays well-formed and
  the other three components still identify the install.
- **Magento caches its own version in the config cache** → a stale value can
  survive an upgrade until the cache is flushed. Accepted: this is Magento's
  behaviour for its own version reporting, and `setup:upgrade` flushes it.
- ~~**The WSDL fetch may not honour the stream-context user agent on every
  supported PHP version**~~ → **retired.** Measured on PHP 8.1–8.4: both the
  WSDL fetch and the SOAP call carry the header, under any of the three
  configurations (D4). No fallback needed.
- **The version bump lands inside a feature change** → makes this change a
  prerequisite for the 1.4.0 release cut rather than an independent addition.
  Mitigated by the drift test, which turns the coupling into something CI
  enforces instead of something a release checklist remembers.
- **Slightly larger request headers on every call** → a few dozen bytes; no
  measurable effect on lookup latency.
- **Adding a header to SOAP options touches the client construction path** →
  the options array is already unit-tested; the new keys are additive and the
  existing timeout/trace assertions guard against collateral change.

## Migration Plan

Code-only; no data migration, no config change, no new setting. Deployment is
the ordinary module upgrade, and the header appears on the next request.

Rollback is a plain revert — nothing persists, nothing is written to
configuration, and TaxCloud treats the header as advisory metadata, so reverting
returns traffic to its current unidentified state with no reconciliation needed.

The only ordering constraint is release-side: the `1.4.0` bump must ship in the
same release as the code that reports it, which is why both are in this change.

## Open Questions

- Whether TaxCloud's log tooling would rather see Adobe Commerce as
  `Magento/2.4.7-p3 (Enterprise)` (current design, mirroring
  `ProductMetadata::getEdition()`) or as a distinct product token. Deferrable:
  it changes one format string and its test, not the approach, the call sites,
  or the task breakdown — and it can be answered from the first week of real
  traffic.
