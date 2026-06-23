# Integration tests

This module ships a real integration test pipeline: it boots the full Magento
application (Open Source or Commerce) against a working MariaDB + OpenSearch +
Redis stack, registers `Taxcloud_Magento2` into `app/code/`, and runs PHPUnit
against a small custom bootstrap that loads the same installed Magento. We
do **not** wrap Magento's `dev/tests/integration/` framework — that's for
testing Magento core, and brings far more machinery than a single module
needs.

> **The unit suite (`Test/Unit/`) is the fast feedback loop and runs on every
> PR. The integration suite documented here is expensive, manual, and exists
> to gate big releases.** It does not run on push or pull requests.

---

## Getting started locally (5 minutes)

### Prerequisites

- Docker Desktop (or Docker Engine 24+ on Linux) with Compose v2
- GNU Make
- ~5 GB free disk for the Magento install and Docker volumes
- A **TaxCloud sandbox account** — free, sign up at
  <https://taxcloud.com/sandbox>. Grab the API ID + key from the dashboard.
- **Magento Marketplace public/private keys** — free, sign up at
  <https://commercemarketplace.adobe.com/customer/accessKeys/>. Required for
  both editions because Composer pulls Magento from `repo.magento.com`.

### Steps

```bash
# from the module root (app/code/Taxcloud/Magento2)
cp .env.example .env
$EDITOR .env       # fill in the four credentials above
make integration-test
```

That's it. On the first run, the install script will:

1. Bring up the integration stack via `docker-compose.yml`.
2. `composer create-project magento/project-community-edition=<version>` into
   a sibling directory (`../magento-community-<version>` by default).
3. Symlink this module into the install's `app/code/Taxcloud/Magento2`.
4. Run `bin/magento setup:install` for a clean baseline install.
5. Run `setup:upgrade`, `setup:di:compile`, enable the module.
6. Seed the standard test environment via `scripts/seed-test-data.php`
   (admin user, test category + product, TaxCloud + shipping-origin config,
   active shipping carrier + payment method, reindex, cache flush).
7. Run the PHPUnit integration suite via our custom bootstrap
   (`Test/Integration/bootstrap.php`).

Re-runs are idempotent — composer create-project is skipped if Magento is
already installed at the target path; the schema and DB are always
re-prepared so tests run against a known state.

### Choosing a different edition / version / PHP

```bash
make integration-test MAGENTO_EDITION=community  MAGENTO_VERSION=2.4.7-p3 PHP_VERSION=8.2
make integration-test MAGENTO_EDITION=enterprise MAGENTO_VERSION=2.4.8-p5
```

Precedence for the PHP version: `make` command line > `PHP_VERSION` in
`.env` > `8.3`. Edition and version always come from the `make` command
line (or its `community 2.4.8-p5` default) — the `MAGENTO_EDITION` /
`MAGENTO_VERSION` lines in `.env` only affect direct `docker compose`
invocations.

### Other targets

```bash
make integration-shell   # bash inside the app container
make integration-clean   # tear down containers + volumes
```

`integration-clean` does **not** delete the Magento install directory itself
(that would force a full ~5-minute reinstall on the next run). Remove it
manually if you want a truly fresh start:

```bash
rm -rf ../magento-community-2.4.8-p5     # adjust to match your matrix row
```

---

## How the test runtime is wired

Tests boot the actual installed Magento via a custom bootstrap at
[`Test/Integration/bootstrap.php`](../Test/Integration/bootstrap.php). That
bootstrap calls Magento's `Bootstrap::create()`, grabs the ObjectManager,
and stashes it in
[`Taxcloud\Magento2\Test\Integration\TestEnvironment`](../Test/Integration/TestEnvironment.php).
Tests pull it back via `TestEnvironment::getObjectManager()` (or the
shorthand `TestEnvironment::get(SomeClass::class)`).

We deliberately do **not** use Magento's `dev/tests/integration/` framework.
That framework exists to test Magento core — it installs a second Magento
into a separate DB and wires up annotation-driven fixtures and isolation. For
exercising one module against an already-installed Magento, it adds nothing
and makes the install pipeline far more fragile.

### How test data works

There is no committed DB dump. Instead, the install script applies a known
test state programmatically on top of a clean `setup:install` via
[`scripts/seed-test-data.php`](../scripts/seed-test-data.php) — a standalone
PHP script that bootstraps the installed Magento and uses standard Magento
APIs (repositories, config writer, indexers). Because the state is created
through the application rather than restored as SQL, the same script works
on **any** edition or version with no per-version artifacts to regenerate.

The seeded baseline every test can rely on:

| What | Value |
| ---- | ----- |
| Admin user | `admin` / `1234567a` (`admin@example.com`, Administrators role) |
| Category | "Test Category" (`test-category`) |
| Product | `test-product` — simple, $10.00, in stock, in Test Category |
| TaxCloud config | `enabled`, `logging`, `verify_address` = 1; `default_tic` = 20000; `api_id`/`api_key` from env |
| Shipping origin | 1401 Lavaca St, Austin TX 78701-1634 (region 57) |
| Checkout methods | `carriers/flatrate` + `payment/checkmo` active |
| Indexers / caches | All reindexed, all flushed |

The script is idempotent — re-running updates rather than duplicates. It
can also be pointed at any other installed Magento (a local dev install,
say) to reproduce the same baseline:

```bash
TAXCLOUD_API_ID=... TAXCLOUD_API_KEY=... \
  php scripts/seed-test-data.php /path/to/magento
```

If a future test needs more seed data, extend `seed-test-data.php` (keep it
idempotent) or `INSERT` from within the test via Magento's
`ResourceConnection`.

> Magento's per-test annotations (`@magentoDataFixture`,
> `@magentoDbIsolation`, `@magentoConfigFixture`) **do not work** here —
> they're framework features. If you need that style of isolation, you can
> wrap a test in a manual transaction:
>
> ```php
> $conn = TestEnvironment::get(\Magento\Framework\App\ResourceConnection::class)
>     ->getConnection();
> $conn->beginTransaction();
> try {
>     // ... test work ...
> } finally {
>     $conn->rollBack();
> }
> ```

---

## Mocking the TaxCloud SOAP client

Real TaxCloud SOAP calls cannot run in CI — they need live sandbox
connectivity and would make tests slow, flaky, and dependent on an external
service's state. Every behavioural test instead swaps the SOAP client for a
controllable double **while keeping the rest of Magento real** (real DB, real
order/invoice/credit-memo flow, real event dispatch, real observers). This is
the same approach Magento core uses when stubbing external services.

### How the swap works

`Taxcloud\Magento2\Model\Api` gets its client from Magento's
`Magento\Framework\Webapi\Soap\ClientFactory` (`create()` returns a
`\SoapClient`). The harness in
[`Test/Integration/IntegrationTestCase.php`](../Test/Integration/IntegrationTestCase.php)
does two things in `installSoapMock()`:

1. **Rebinds the factory.** It puts an anonymous `ClientFactory` subclass into
   the ObjectManager whose `create()` returns a
   [`RecordingSoapClient`](../Test/Integration/Doubles/RecordingSoapClient.php)
   instead of a real `\SoapClient`.
2. **Evicts the cached singletons** that would otherwise still hold the real
   client — `Api`, the TaxCloud `Tax` total model, and the four observers.
   On the next resolution Magento rebuilds that graph around the mock.

> **Why not `addSharedInstance()`?** That method only exists on Magento's
> *integration-test-framework* ObjectManager. This suite deliberately boots the
> real installed Magento (see "Why a custom bootstrap" below), whose production
> ObjectManager keeps its shared instances in a protected array. The harness
> reaches them with a closure bound to the ObjectManager's class scope — the
> minimal seam that lets us swap one binding without dragging in the whole
> framework.

`RecordingSoapClient` extends `\SoapClient` (so it satisfies the factory's
return contract) but never calls the parent constructor, so **no WSDL is ever
fetched**. Every TaxCloud operation is a magic method on `\SoapClient`, so the
double intercepts them all through `__call()`, where it both **records the
call** (method name + argument payload) and **returns a canned response**.

### Canned responses

Reusable happy-path responses live as PHP fixtures under
[`Test/Integration/_files/soap_responses/`](../Test/Integration/_files/soap_responses/),
one file per operation, each returning an array shaped like the real WSDL's
response element:

| Fixture | SOAP op (as the code calls it) | Result element |
| ------- | ------------------------------ | -------------- |
| `lookup_ok_empty.php` | `lookup` | `LookupResult` (OK, empty cart response = zero tax) |
| `verify_address_ok.php` | `verifyAddress` | `VerifyAddressResult` (`ErrNumber` 0) |
| `get_exempt_certificates_empty.php` | `GetExemptCertificates` | `GetExemptCertificatesResult` (OK, none) |
| `authorized_with_capture_ok.php` | `authorizedWithCapture` | `AuthorizedWithCaptureResult` (OK) |
| `returned_ok.php` | `Returned` | `ReturnedResult` (OK) |
| `order_details_captured.php` | `OrderDetails` | `OrderDetailsResult` (non-empty `CapturedDate`) |

`installSoapMock()` loads all six by default. Note the keys match the **casing
the code uses** when calling the client (`lookup`, `verifyAddress`, …), not the
WSDL's PascalCase — `__call()` receives the name exactly as written in `Api`.
Override or add a response per test with
`$soap->setResponse('OperationName', $arrayOrClosure)`; a `\Closure(array $args)`
lets you compute a per-call response.

### Writing an observer-wiring test

Extend `IntegrationTestCase`, install the mock, drive a real sales-flow action,
then assert on what reached the SOAP layer:

```php
final class CaptureOnOrderPlaceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
    }

    public function testCaptureFiresOnPlacement(): void
    {
        $soap  = $this->soapClient();
        $order = $this->placeOrder();        // fires sales_order_place_after

        $this->assertSame(1, $soap->callCount('authorizedWithCapture'));
        $this->assertSame(
            $order->getIncrementId(),
            $soap->firstCallArgs('authorizedWithCapture')['orderID']
        );
    }
}
```

The base class provides the whole real lifecycle so the events actually fire:
`placeOrder()`, `payInvoice()`, `createShipment()`, `cancelOrder()`,
`refundOrder()`, plus `setCaptureTrigger()` / `writeConfig()` (which persist
config and `reinit()` the shared config). Current coverage lives in
[`Test/Integration/Observer/Sales/`](../Test/Integration/Observer/Sales/):

| Test | Proves |
| ---- | ------ |
| `CaptureOnOrderPlaceTest` | trigger=order_creation → capture on `sales_order_place_after` |
| `CaptureOnInvoicePayTest` | trigger=payment → capture on invoice pay, not on placement |
| `CaptureOnShipmentTest` | trigger=shipment → capture on shipment save only |
| `CancelOnRealOrderStateTransitionTest` | cancelling a captured, uninvoiced order calls `Returned` once across both cancel events |
| `RefundOnCreditmemoTest` | credit memo → `Returned`, with payload items matching the memo |

> These tests place real orders and therefore **write to the test database** —
> run them against the integration stack (`make integration-test`), never the
> shared dev database. Each test creates and asserts on its own order, so they
> don't depend on each other's state.

---

## The CI matrix

The workflow currently runs four rows:

| Edition    | Magento  | PHP | MariaDB | OpenSearch |
| ---------- | -------- | --- | ------- | ---------- |
| community  | 2.4.8-p5 | 8.3 | 10.6    | 2.12       |
| community  | 2.4.9    | 8.5 | 11.4    | 3.0        |
| enterprise | 2.4.8-p5 | 8.3 | 10.6    | 2.12       |
| enterprise | 2.4.9    | 8.5 | 11.4    | 3.0        |

Enterprise rows skip cleanly (not fail) when the `MAGENTO_PUBLIC_KEY`
secret is unset, so forks without a Commerce contract still get green
community rows. Note that enterprise rows need Marketplace keys from an
account **entitled to Adobe Commerce** — ordinary free Marketplace keys can
download Open Source but get a 403 from `repo.magento.com` for
`project-enterprise-edition`.

### Adding a new Magento version

There are no per-version artifacts to generate — the seed script works
against any installed Magento. Adding a version is two steps:

1. **Add a row to the `integration` job's matrix in `.github/workflows/test.yml`**
   (add both editions, and a matching row to the `unit-tests` matrix if it's a
   new version line):
   ```yaml
   - magento-edition: community
     magento-version: '2.4.10'
     php-version: '8.5'
   - magento-edition: enterprise
     magento-version: '2.4.10'
     php-version: '8.5'
   ```
2. **Test the row before merging:** Actions tab → CI → Run workflow → set
   `magento_versions=2.4.10` (dispatch runs the integration job too).

If a row needs a different PHP version than the others, the compose stack
already supports it — the `PHP_VERSION` env var is honored both locally and
in CI. The image is `markoshust/magento-php:<PHP_VERSION>-fpm`.

MariaDB and OpenSearch versions are derived from the Magento version by
`scripts/install-magento.sh` per Adobe's system requirements (2.4.9 dropped
MariaDB 10.x and OpenSearch 2.x). If a future Magento release changes the
requirements again, extend the `case` block in the install script — or
override per-run with the `MARIADB_VERSION` / `OPENSEARCH_VERSION` env
vars.

---

## Triggering the integration matrix manually

Integration runs as a job within the unified **CI** workflow
(`.github/workflows/test.yml`). It is gated to release tags (`v*`) and manual
dispatch — it does not run on ordinary PRs or branch pushes.

From the GitHub UI:

1. Actions tab → **CI** workflow.
2. **Run workflow** → choose the branch.
3. Optional: set **Integration: comma-separated versions** (e.g. `2.4.8-p5` to
   run only that version's matrix rows). Empty = full matrix.
4. Watch the per-row jobs. On failure, the **debug-\<edition\>-\<version\>**
   artifact contains `docker compose logs` and Magento's `var/log/*.log`
   tail — start there.

From the CLI:

```bash
gh workflow run test.yml --ref main
gh workflow run test.yml --ref main -f magento_versions=2.4.8-p5
```

A pushed release tag (`v*`) runs the whole thing — unit + the full integration
matrix — in a single CI run.

### Required GitHub Actions secrets

| Secret               | Purpose                                  | Required for                |
| -------------------- | ---------------------------------------- | --------------------------- |
| `TAXCLOUD_API_ID`    | Sandbox API ID                           | All rows                    |
| `TAXCLOUD_API_KEY`   | Sandbox API key                          | All rows                    |
| `MAGENTO_PUBLIC_KEY` | Marketplace public key (auth.json user)  | All rows                    |
| `MAGENTO_PRIVATE_KEY`| Marketplace private key (auth.json pass) | All rows                    |

Enterprise rows skip cleanly (not fail) when `MAGENTO_PUBLIC_KEY` is unset,
so forks without Commerce contracts still get green community rows.

---

## Architecture

### Why markoshust/docker-magento?

We chose [markoshust/docker-magento](https://github.com/markoshust/docker-magento)
as the container base over rolling our own minimal Compose. Reasons:

- The PHP image bundles every extension Magento needs (`gd`, `intl`,
  `soap`, `xsl`, `zip`, `bcmath`, `pcntl`, `sockets`, `redis`, `opcache`,
  …). Replicating that in a hand-rolled Dockerfile is ~50 lines of
  `apt-get install` and `pecl install` we'd have to keep current.
- It's used widely in the Magento community, so when something breaks
  there's a body of public knowledge to draw from.
- Versions track Magento's PHP support matrix closely. Bumping PHP for a
  new Magento release is a tag bump.

We deliberately use **only** their PHP image, not their full stack —
nginx/varnish/etc. add nothing for integration tests, which don't go
through HTTP.

### Local vs CI layout

| Aspect             | Local                                    | CI                                              |
| ------------------ | ---------------------------------------- | ----------------------------------------------- |
| Magento install    | Sibling dir (`../magento-<edition>-<v>`) | Workspace-adjacent (`$GITHUB_WORKSPACE/../…`)   |
| Module mount       | Bind from this repo into `/srv/module`   | Bind from `$GITHUB_WORKSPACE` into `/srv/module`|
| Composer cache     | `./.composer-cache` (gitignored)         | `$GITHUB_WORKSPACE/.composer-cache` + `actions/cache` |
| Credentials        | `.env` (gitignored)                      | Repo secrets injected as job env                |
| Lifetime           | Persistent across `make` runs            | Wiped between matrix rows                       |

Both paths drive the same `scripts/install-magento.sh` and `docker-compose.yml`.

### Why a custom bootstrap instead of Magento's framework?

Magento's `dev/tests/integration/` framework installs a second Magento into
a separate DB (`magento_integration_test`), wires up
`@magentoDataFixture` / `@magentoDbIsolation` / `@magentoConfigFixture`
annotations, and otherwise reproduces the full test-isolation model used by
Magento core's own test suite.

For one third-party module, that machinery is pure cost:
- A second Magento install means a second `setup:install` to maintain.
- A second DB means a second source of truth for fixture data.
- Every PHPUnit constant (`TESTS_INSTALL_CONFIG_FILE` etc.) is another
  file you have to write into Magento's tree at install time.

Our custom bootstrap is ~20 lines, boots the installed Magento, and lets
tests share state. If you ever need per-test isolation, you can wrap a test
in a manual transaction (see "How test data works" earlier). If you need
seed data, add it to `scripts/seed-test-data.php` or `INSERT` it from the
test.

---

## Troubleshooting

### `ERROR: TAXCLOUD_API_ID and TAXCLOUD_API_KEY must be set`

You haven't filled in `.env` (locally) or set repo secrets (CI). See the
"Prerequisites" section.

### `Composer could not authenticate against repo.magento.com`

Your Marketplace keys are wrong, expired, or revoked. Regenerate at
<https://commercemarketplace.adobe.com/customer/accessKeys/> and update
`.env` / secrets.

### OpenSearch OOM-killed on first boot

The compose file sets `-Xms512m -Xmx512m`. If your Docker host is tight on
memory (less than 6 GB allocated to Docker), bump those down to `256m` in
`docker-compose.yml` or give Docker more RAM.

### `make integration-test` fails inside `setup:di:compile`

Almost always a stale `generated/` directory in the Magento install. From
the host:

```bash
docker compose exec -w /var/www/html app rm -rf generated/code generated/metadata
make integration-test
```

### Connection refused / "Unknown database 'magento'" after `make integration-clean`

`make integration-clean` wipes the DB volume, but the Magento install on
disk still has a stale `app/etc/env.php` pointing at the old DB. The
install script's defensive reset re-creates the DB but env.php gets
written by setup:install, so you just need to let the script run. If
something gets really wedged:

```bash
rm -rf ../magento-community-2.4.8-p5
make integration-test
```
