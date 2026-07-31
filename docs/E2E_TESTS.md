# End-to-end (E2E) tests

This module ships a Playwright E2E pipeline that drives a **real browser**
against a **real, served Magento storefront**. It reuses the same Docker stack,
install script, and programmatic seed as the [integration tests](INTEGRATION_TESTS.md)
— it just adds the two things a browser needs that PHPUnit doesn't: an HTTP web
server (nginx) in front of php-fpm, and Playwright + Chromium to drive it.

> **Like the integration suite, E2E is expensive, slow, and manual.** It does
> **not** run on push or pull requests — only on `workflow_dispatch` and release
> tags (`v*`). The unit suite remains the fast per-PR feedback loop.

The E2E suite and the integration suite are **siblings on a shared foundation**,
not parent/child: both are provisioned by `scripts/install-magento.sh` + the
seed in `scripts/seed-test-data.php`. E2E only layers HTTP serving and a browser
on top.

---

## Getting started locally (10 minutes)

### Prerequisites

Everything the [integration tests](INTEGRATION_TESTS.md) need, plus:

- **Node.js >= 20** and npm on the host (Playwright runs on your machine and
  drives a browser that talks to the Dockerized storefront).
- A free TCP port for the storefront (default **8080**).

The credentials are the **same one-time `.env` edit** as integration — there are
no new secrets:

```bash
# from the module root (app/code/Taxcloud/Magento2)
cp .env.example .env
$EDITOR .env       # TaxCloud sandbox + Magento Marketplace keys (same as integration)
```

### Run it

```bash
make e2e-setup     # install Magento + nginx and serve it over HTTP (~10-15 min first time)
make e2e-test      # run the suite headless (auto-installs Node deps + Chromium on first run)
```

`make e2e-test` refuses to run with a clear message if the storefront isn't up,
so the two-step flow is hard to get wrong. That's it — `make e2e-test` works off
the same `.env` edit integration uses.

To reuse a Magento version you already installed for integration (skips the
Composer download), pass it through:

```bash
make e2e-setup MAGENTO_VERSION=2.4.7-p10
```

### Watch the browser

```bash
make e2e-test-ui       # Playwright's interactive UI runner — ▶ a test and watch it live
make e2e-test-headed   # run headed (visible window), straight through
make e2e-install       # (re)install Node deps + Chromium explicitly
make e2e-trace         # open the trace viewer for the last FAILED run
make e2e-clean         # remove test-results/ and playwright-report/
```

The browser runs **on your machine**, not in Docker, and hits the storefront at
`MAGENTO_BASE_URL` (default `http://localhost:8080`). If you change the port,
keep `MAGENTO_HTTP_PORT` (install) and `MAGENTO_BASE_URL` (browser) in sync — and
pass `MAGENTO_BASE_URL=...` to the `make e2e-test*` targets too.

---

## How it serves Magento (the architecture)

The integration stack runs the `app` (php-fpm) container as `sleep infinity`:
PHPUnit `exec`s into it and talks to Magento PHP objects directly, so it never
needs HTTP. A browser does. `docker-compose.e2e.yml` overlays the base stack and:

- runs **php-fpm** in `app` (so it creates the unix socket `/sock/phpfpm.sock`),
- adds an **nginx** container (`markoshust/magento-nginx`) that fastcgi_passes to
  that socket and publishes the storefront on the host (`MAGENTO_HTTP_PORT`).

nginx serves plain **HTTP on 8080** (the image's default vhost is HTTPS-only with
a self-signed cert; we mount our own HTTP vhost — `Test/E2E/docker/default.conf` —
to avoid cert-trust flakiness). The actual routing is **Magento's own
`nginx.conf`** (copied from `nginx.conf.sample` by the install script), so we
reuse Magento's official static/media/security handling rather than hand-rolling it.

Integration runs are **completely unaffected**: they use `docker-compose.yml`
alone (no nginx, app still sleeps). The overlay is only applied when
`scripts/install-magento.sh` is run with `E2E=1` (which `make e2e-setup` does).

---

## SOAP mocking (server-side) — current status: DEFERRED

This is the one piece that is **designed and documented here but not yet
implemented in code** (by decision — the pipeline this ticket delivers doesn't
exercise it).

**Why it can't be browser-side.** Unlike a typical front-end test, the browser
never makes the TaxCloud SOAP call. **Magento's PHP does**, server-side, during
cart/checkout. So Playwright's `page.route()` cannot intercept it — the mock has
to live inside Magento, across the HTTP boundary the request crosses.

**What already exists.** The integration suite mocks SOAP in-process by swapping
the `\Magento\Framework\Webapi\Soap\ClientFactory` DI binding for a
`RecordingSoapClient` that returns canned responses from
`Test/Integration/_files/soap_responses/*.php`
(see `Test/Integration/IntegrationTestCase.php::installSoapMock()`). That swap
works **only** because PHPUnit shares the process with Magento; it's unavailable
to a separate php-fpm request.

**Why nothing is needed yet.** The only current E2E test loads the home page,
which makes **zero** SOAP calls (tax lookup happens at cart/checkout). So the
pipeline is proven end to end without any server-side mock.

**The intended approach** (lands with the first checkout E2E test, in the "E2E
test coverage" ticket): a small **dev-only Magento module**, enabled only in the
E2E install, that — when a test-mode config flag is set — rebinds `ClientFactory`
to a **file-backed** mock reusing the existing
`Test/Integration/_files/soap_responses/` fixtures. Tests assert on **UI
outcomes** (e.g. the tax shown at checkout), so the first pass needs neither
per-test response injection nor a call-recording endpoint — keeping it to a
tiny di.xml preference rather than a REST-driven support module. The placeholder
fixture `Test/E2E/fixtures/soap-mock.ts` records this and will host the helper
surface when it lands.

---

## Test data

E2E reuses the **same programmatic seed** as integration
(`scripts/seed-test-data.php`) — there are no SQL-dump fixtures in this repo. The
seed already provides everything a browser checkout needs:

- an enabled, in-stock, catalog-visible **Test Product** ($10) in a Test Category,
- a configurable product with two TIC-distinct variants,
- an active **payment** method (Check / Money Order) and **shipping** method
  (Flat Rate),
- the admin user (`admin` / `1234567a`),
- TaxCloud config + ship-from origin,
- a second website/group/store view (code `second`) with the same catalog and
  TaxCloud **disabled** at store scope; store codes are in URLs
  (`web/url/use_store` = 1), so the stores are browsable side by side at
  `/default/...` and `/second/...` on the same base URL. The
  `multistore-second-store-no-tax` spec checks out on `/second/` and asserts
  the customer sees **no** TaxCloud tax there, while the A.1 checkout spec pins
  the taxed default-store totals — together they prove per-store scoping
  through real URL-based store resolution.

E2E-specific data that the smoke test doesn't need (e.g. a registered customer
account for login flows) is **deferred**: it'll be added as an optional
`scripts/seed-e2e-data.php` + install flag when the first test that needs it
lands. Do not fork a parallel seed — extend the shared one.

---

## CI

E2E runs as the **`e2e` job** in the unified pipeline
[`.github/workflows/test.yml`](../.github/workflows/test.yml), alongside
`unit-tests`, `integration`, `lint-code`, and `security-scan` — the same single
workflow that gates a release.

**Triggers:** like the `integration` job, the `e2e` job runs **only** on
`workflow_dispatch` (with an optional `magento_versions` input to subset the
matrix) and `push` of `v*` tags. **No** `pull_request`, **no** branch pushes
(only `unit-tests` + `lint` + `security` run on those).

**Matrix:** mirrors the integration matrix exactly — community + enterprise
across `2.4.7-p10` / `2.4.8-p5` / `2.4.9` (PHP 8.2 / 8.3 / 8.5). Enterprise rows
are **auto-skipped** (not failed) when Marketplace keys are absent, same as
integration.

**Secrets:** the same four as integration (`TAXCLOUD_API_ID`, `TAXCLOUD_API_KEY`,
`MAGENTO_PUBLIC_KEY`, `MAGENTO_PRIVATE_KEY`). No new secrets.

### Adding a Magento version to the matrix

Edit the `matrix.include` list under the `e2e` job in `test.yml` (keep it in step
with the `integration` job's matrix):

```yaml
include:
  - magento-edition: community
    magento-version: '2.4.9'
    php-version: '8.5'
  # ... add a row here (and the matching enterprise row)
```

The DB/search-engine versions are derived from the Magento version automatically
by `scripts/install-magento.sh`. To run a subset on a manual dispatch, use the
**Run workflow** form's `magento_versions` field (e.g. `2.4.9`).

---

## Debugging a failed CI run

Artifacts are uploaded **on failure** (traces, video, screenshots, the HTML
report, and Docker logs):

1. Open the failed run in the **Actions** tab → **Artifacts** → download
   `playwright-report-<edition>-<version>-php<x.y>`.
2. Unzip it. To open the interactive trace viewer locally:

   ```bash
   cd Test/E2E
   npx playwright show-trace /path/to/unzipped/test-results/<test>/trace.zip
   # or open the HTML report (which links every trace/video/screenshot):
   npx playwright show-report /path/to/unzipped/playwright-report
   ```

3. The `debug-e2e-...` artifact has `docker compose logs` (nginx + php-fpm + DB)
   for serving/install failures.

Traces/videos/screenshots are **retain-on-failure** / **only-on-failure**, so
green runs produce no artifacts and stay fast. `make e2e-trace` opens the trace
for your most recent **local** failure.

---

## Layout

```
Test/E2E/
  package.json            # @playwright/test pinned; engines.node >= 20
  package-lock.json
  playwright.config.ts    # baseURL from MAGENTO_BASE_URL; trace/video/screenshot
                          # on failure; 1 worker (shared DB); chromium only
  docker/
    default.conf          # HTTP nginx vhost (includes Magento's nginx.conf)
  fixtures/
    auth.ts               # scaffold — logged-in customer/admin helpers (deferred)
    soap-mock.ts          # documents the deferred server-side SOAP strategy
  pages/
    storefront/HomePage.ts
  specs/
    smoke/storefront-loads.spec.ts   # the pipeline smoke test
```

Page objects keep selectors out of specs: locators in the constructor,
navigation + assertions as methods. Follow `HomePage` when adding new ones.
