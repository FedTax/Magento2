## Context

The v3 transport has exactly two places that open HTTP connections:
`RestClient::send()` (every operation, plus the admin credential test) and
`TokenExchange::exchange()` (V1→Bearer). Each creates a fresh
`Magento\Framework\HTTP\Client\Curl` per request from the generated
`CurlFactory`, adds headers, calls `post()`/`get()`, and reads
`getStatus()`/`getBody()`.

Core `Curl::parseHeaders()` is libcurl's header callback. It takes the status
from the first header line it ever sees (`_headerCount == 0`) and stores every
later `Name: value` line into `_responseHeaders`. When libcurl sends
`Expect: 100-continue`, the first line is `HTTP/1.1 100 Continue`, so
`getStatus()` returns 100 while `getBody()` holds the final response's body.
The method and the protected properties it uses (`_headerCount`,
`_responseHeaders`, `_responseStatus`) are byte-identical in 2.4.7-p10,
2.4.8-p5 and 2.4.9.

Measured on the live v3 API with the shipped 1.4.0 code (see proposal):
- libcurl 7.61.1 (EL8) defaults to HTTP/1.1 and adds the header above 1,024
  bytes. A 963-byte cart reads 200, and 1,032- and 1,102-byte carts read 100.
- libcurl 7.76.1 (the dev container) negotiates HTTP/2 and adds the header
  only above 1 MiB.
- The TaxCloud endpoint sends `100 Continue` whenever the header is present,
  over HTTP/1.1 and HTTP/2, and never when it is absent.

## Goals / Non-Goals

**Goals:**
- Remove the cause for the module's own requests: they never carry the header.
- Make status reading robust to any interim `1xx`, whatever its origin
  (proxies, CDNs sending `103 Early Hints`, a future regression that drops the
  header).
- Keep the fix confined to the module's own HTTP clients. Every other Magento
  HTTP client keeps core behavior.
- Pin both behaviors with unit tests that run against the real parent class on
  the full CI matrix.

**Non-Goals:**
- Changing retry semantics, error mapping, logging, or timeouts.
- Replacing Magento's `Curl` client with another HTTP library.

## Decisions

### D1. Suppress `Expect` with an empty header on every v3 request

Both `RestClient::send()` and `TokenExchange::exchange()` add the header
`Expect` with an empty value. Magento's `Curl` sends it as `Expect: `, which
libcurl treats as "remove this internal header". The body then goes out with
the request, and no interim response is ever solicited. In `RestClient` the
header joins the base header set built in `send()`, so every method (`POST`,
`GET`, `DELETE`), the admin ping, and any future operation get it with no
per-call opt-in.

This was verified on libcurl 7.61.1 against the live API: with the header
added, carts of every size read 200.

Alternatives considered:
- *Force HTTP/2 (`CURLOPT_HTTP_VERSION`).* Rejected. It is unavailable when
  libcurl is built without nghttp2, and TaxCloud also sends `100` over HTTP/2
  when asked.
- *Shorten the wait (`CURLOPT_EXPECT_100_TIMEOUT_MS`).* Rejected. It only
  shortens the pause; the `100` still arrives and is still misread.

### D2. A `Curl` subclass that keeps the final status line

A new `Taxcloud\Magento2\Model\Gateway\Rest\FinalStatusCurl` extends core
`Curl` and overrides only `parseHeaders()`. When a status line (`HTTP/…`)
arrives after header lines have already been counted, a new response has
begun. The subclass resets `_headerCount` to 0 and clears `_responseHeaders`,
then delegates to the parent. The parent records the new status exactly as it
always does. The method still returns `strlen($data)` through the parent, as
libcurl requires. Nothing else is overridden.

This is defense in depth. D1 removes the interim responses we cause. D2 makes
any interim response harmless. Either alone fixes the reproduced case, but D1
alone leaves `103 Early Hints` and proxy-injected `100` misread, and D2 alone
keeps the extra round trip.

Alternatives considered:
- *A plugin on core `Curl`.* Rejected. `parseHeaders()` is protected, so a
  plugin cannot intercept it. A preference would change every HTTP client in
  the installation.
- *Install our own `CURLOPT_HEADERFUNCTION`.* Rejected. Core applies user
  options after its own callback, so ours would replace the parser and leave
  `getStatus()` at 0.

### D3. Wire the subclass through its generated factory, typed in the constructor

`RestClient` and `TokenExchange` take
`Taxcloud\Magento2\Model\Gateway\Rest\FinalStatusCurlFactory` (auto-generated
by Magento, like any `*Factory`) in place of core `CurlFactory`. The
constructor type is the wiring, so the type system and every unit test that
builds these classes enforce it. There is no `di.xml` entry that could be
missing or overridden.

Alternative considered: keep the `CurlFactory` type and pass a `virtualType` of
it with `instanceName` set to the subclass, in `di.xml`. That keeps the
signatures, but the wiring becomes invisible to unit tests (they construct the
classes with a mock factory) and can only be pinned by parsing XML. Rejected in
favor of the typed dependency.

## Risks / Trade-offs

- [Core `Curl` internals change in a future Magento release] → The subclass
  touches three protected members. The new unit tests drive `parseHeaders()`
  on the real parent class on 2.4.7, 2.4.8 and 2.4.9 in CI, so drift fails a
  build rather than a checkout.
- [Third-party code constructs `RestClient`/`TokenExchange` by hand with a core
  `CurlFactory`] → It gets a `TypeError` on upgrade. Both classes are internal
  and DI-resolved, and no extension point documents them. The CHANGELOG notes
  that production mode needs `setup:di:compile`. That step also generates the
  new factory.
- [A server that insists on `Expect`] → None is known, and HTTP/1.1 servers
  must accept a body sent without it. The only cost of omitting it is the
  inability to abort a large upload early, which v3 request sizes never need.
- [Interim-response headers carried a `Set-Cookie`] → They are discarded with
  the interim response, which is correct: the final response is the one that
  sets state.

## Migration Plan

Ship as 1.4.1 from the `v1.4.0` tag. There is no schema, data or configuration
change. Merchants update the package, then run `setup:upgrade` (version bump)
and, in production mode, `setup:di:compile`. Rollback is reinstalling 1.4.0,
which restores the misread with no data consequences. The same change is then
ported to 1.5.1 and to the unreleased line as separate follow-ups.
