# Integration tests

This module ships a real integration test pipeline: it boots the full Magento
application (Open Source or Commerce) against a working MariaDB + OpenSearch +
Redis stack, registers `Taxcloud_Magento2` into `app/code/`, and runs PHPUnit
through Magento's own `dev/tests/integration/` framework.

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
8. Configure Magento's integration framework against a separate test DB
   (`magento_integration_test`).
9. Run the PHPUnit integration suite.

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

## How fixtures work

The integration framework manages its own database
(`magento_integration_test`) — it installs Magento there on first bootstrap
and tears it down at the end. Magento's standard fixture annotations
(`@magentoDataFixture`, `@magentoDbIsolation`) work against that database.

Separately, the install script seeds the **main** `magento` database from a
committed SQL dump under `fixtures/db/`. That DB is useful for:

- Browsing the storefront manually after install (`http://localhost/` when
  nginx is in front; not configured in this stack by default).
- Tests that want pre-loaded sample products, customers, or tax zones
  without paying the cost of running sample-data import on every run.

A missing fixture is a **fail-fast error**: the install script will refuse to
run if `fixtures/db/magento-fixture-<edition>-<version>.sql.gz` is not
present. This is deliberate — silently falling back to a clean install
would hide schema/data drift bugs that fixture-based tests are meant to
catch.

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

1. Add a row to `.github/workflows/integration-tests.yml`:
   ```yaml
   - magento-edition: community
     magento-version: '2.4.9'
     php-version: '8.3'
   ```
2. Generate and commit a DB fixture for the new version (see above).
3. Run the workflow manually (Actions tab → Integration Tests → Run
   workflow) with `magento_versions=2.4.9` to test just the new row before
   merging.
4. Once green, merge the matrix row + fixture together.

If a row needs a different PHP version than the others, the compose stack
already supports it — the `PHP_VERSION` env var is honored both locally and
in CI. The image is `markoshust/magento-php:<PHP_VERSION>-fpm`.

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

### Why a separate test DB?

Magento's integration framework boots a clean install at the start of each
test run and tears it down at the end. It owns its own DB. We give it
`magento_integration_test`, separate from the `magento` DB our install
script seeds from a fixture.

If you find yourself wanting fixture data inside an integration test, use
Magento's `@magentoDataFixture` annotation pointed at a fixture PHP file —
that's the framework's canonical pattern. The dump on disk is for manual
inspection and for future tests that load it via fixture annotations.

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

### "magento_integration_test" DB connection refused

You ran `make integration-clean` (which wipes the DB volume) but the
Magento install still has stale `app/etc/env.php` pointing at the old DB
credentials. Easiest fix: wipe the install dir and re-run:

```bash
rm -rf ../magento-community-2.4.8-p5
make integration-test
```
