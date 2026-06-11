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
4. Run `bin/magento setup:install` as a schema baseline.
5. Restore the committed DB fixture
   (`fixtures/db/magento-fixture-community-<version>.sql.gz`).
6. Run `setup:upgrade`, `setup:di:compile`, enable the module.
7. Inject TaxCloud credentials into `core_config_data` via `bin/magento
   config:set tax/taxcloud_settings/{api_id,api_key,enabled}`.
8. Run the PHPUnit integration suite via our custom bootstrap
   (`Test/Integration/bootstrap.php`).

Re-runs are idempotent — composer create-project is skipped if Magento is
already installed at the target path; the schema and DB are always
re-prepared so tests run against a known state.

### Choosing a different edition / version

```bash
make integration-test MAGENTO_EDITION=community  MAGENTO_VERSION=2.4.7-p3
make integration-test MAGENTO_EDITION=enterprise MAGENTO_VERSION=2.4.8-p5
```

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

### How fixtures work

The install script restores a committed SQL dump under `fixtures/db/` into
the `magento` database. Tests run against that same database, so the
restored state is your test fixture. If a future test needs custom seed
data, either bake it into the dump (run `make-fixture.sh` against a Magento
you've prepped) or `INSERT` it from within the test via Magento's
`ResourceConnection`.

A missing dump is a **fail-fast error**: the install script will refuse to
run if `fixtures/db/magento-fixture-<edition>-<version>.sql.gz` is not
present. This is deliberate — silently falling back to a clean install
would hide schema/data drift bugs that fixture-based tests are meant to
catch.

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

### Generating a fresh DB dump

You generate a dump once per Magento version and commit it. The procedure:

1. Tear down any prior stack: `make integration-clean`.
2. Wipe the existing Magento install dir for the target version (the
   install script will recreate it cleanly):
   ```bash
   rm -rf ../magento-community-2.4.8-p5
   ```
3. Stand up just the stack services (no install yet):
   ```bash
   MAGENTO_EDITION=community MAGENTO_VERSION=2.4.8-p5 docker compose up -d --wait
   ```
4. Get a shell in the app container and run the install steps from
   `scripts/install-magento.sh` manually, **skipping** the dump-restore
   block (since you're generating the dump fresh):
   ```bash
   docker compose exec -w /var/www/html app bash
   composer create-project --repository-url=https://repo.magento.com/ \
     magento/project-community-edition=2.4.8-p5 .
   bin/magento setup:install ...            # use the same flags as the script
   bin/magento sampledata:deploy            # optional, adds sample products
   bin/magento setup:upgrade
   ```
5. Make any additional baseline state changes (tax zones, store config,
   custom modules, etc.) — these will be baked into the fixture.
6. Dump and gzip from the host:
   ```bash
   docker compose exec -T db \
     mariadb-dump -uroot -pmagento --single-transaction --no-tablespaces magento \
     | gzip -9 > fixtures/db/magento-fixture-community-2.4.8-p5.sql.gz
   ```
7. Commit the resulting file. Expect 5-30 MB depending on whether you
   loaded sample data. See `fixtures/db/README.md` for more notes on
   keeping dumps small.

---

## Adding a new Magento version to the matrix

The install script **fail-fasts** on missing fixtures, so the order matters
— fixture first, matrix row second. Follow these steps in order:

1. **Generate the fixture for the new version.** This requires either an
   already-installed Magento at that version (e.g. a local Warden install)
   or a one-off provision via `make integration-test MAGENTO_VERSION=2.4.9`
   (which would have to skip the dump-restore step — easiest is to comment
   out that block in `scripts/install-magento.sh` for the one-off run).
   Then run `scripts/make-fixture.sh community 2.4.9`. See "Generating a
   fresh DB dump" above for the longer procedure.
2. **Commit the fixture file.** Verify the safety greps in
   `make-fixture.sh`'s closing notice show no leaked PII/credentials.
3. **Add a row to `.github/workflows/integration-tests.yml`:**
   ```yaml
   - magento-edition: community
     magento-version: '2.4.9'
     php-version: '8.3'
   ```
4. **Test the row before merging:** Actions tab → Integration Tests → Run
   workflow → set `magento_versions=2.4.9`.
5. **Merge** the fixture + matrix row together as one commit.

If a row needs a different PHP version than the others, the compose stack
already supports it — the `PHP_VERSION` env var is honored both locally and
in CI. The image is `markoshust/magento-php:<PHP_VERSION>-fpm`.

### Re-expanding to a full matrix from this PR's starting point

The workflow currently ships with one active row (`community 2.4.8-p5`).
Two future rows are commented out in
`.github/workflows/integration-tests.yml` as a punch-list:

- `community 2.4.7-p3` / PHP 8.2
- `enterprise 2.4.8-p5` / PHP 8.3

Each one becomes active by generating its fixture (step 1 above) and
uncommenting the matrix block.

---

## Triggering the workflow manually

From the GitHub UI:

1. Actions tab → **Integration Tests** workflow.
2. **Run workflow** → choose the branch.
3. Optional: set **Comma-separated versions** (e.g. `2.4.8-p5` to run only
   that version's matrix rows). Empty = full matrix.
4. Watch the per-row jobs. On failure, the **debug-\<edition\>-\<version\>**
   artifact contains `docker compose logs` and Magento's `var/log/*.log`
   tail — start there.

From the CLI:

```bash
gh workflow run integration-tests.yml --ref main
gh workflow run integration-tests.yml --ref main -f magento_versions=2.4.8-p5
```

The workflow also runs automatically on `push` to release tags (`v*`).

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
in a manual transaction (see "How fixtures work" earlier). If you need
fixture data, bake it into the dump or `INSERT` it from the test.

---

## Troubleshooting

### `ERROR: DB fixture not found`

A version of `magento-fixture-<edition>-<version>.sql.gz` is missing from
`fixtures/db/`. Generate one (see above) or run with an
edition/version that already has a fixture committed.

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
