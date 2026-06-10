# Database fixtures

Pre-built MariaDB dumps used by the integration test stack to seed Magento
with a known state (sample products, sample customers, tax-relevant config)
without paying the cost of running the Magento installer + sample data
import on every test run.

## Naming convention

```
magento-fixture-<edition>-<version>.sql.gz
```

Examples:

```
magento-fixture-community-2.4.7-p3.sql.gz
magento-fixture-community-2.4.8-p1.sql.gz
magento-fixture-enterprise-2.4.8-p1.sql.gz
```

The install script picks the file by interpolating its `<edition> <version>`
arguments into this pattern. A missing fixture is a fail-fast error; we do
**not** silently fall back to a clean install, because tests that need fixture
data would then silently pass against an empty DB.

## How to generate a fresh dump

Generating a fixture is a one-time setup per Magento version (do it once when
you add a new row to the matrix; commit the result). Roughly:

1. `make integration-clean` to wipe any prior install.
2. Bring the stack up with the target edition/version:
   ```bash
   MAGENTO_EDITION=community MAGENTO_VERSION=2.4.8-p1 \
       docker compose up -d --wait
   ```
3. Run a manual install inside the `app` container (skip the fixture-restore
   step in `scripts/install-magento.sh` — easiest is to copy the script and
   delete the dump-restore block):
   ```bash
   docker compose exec app bash
   composer create-project --repository-url=https://repo.magento.com/ \
       magento/project-community-edition=2.4.8-p1 .
   bin/magento setup:install ...   # same flags as in install-magento.sh
   bin/magento sampledata:deploy   # optional — sample products & customers
   bin/magento setup:upgrade
   ```
4. Add anything else you want baked into the fixture: enable specific Magento
   modules, configure tax zones, create test stores, etc.
5. Dump and gzip:
   ```bash
   docker compose exec -T db \
       mariadb-dump -uroot -pmagento --single-transaction magento \
       | gzip -9 > fixtures/db/magento-fixture-community-2.4.8-p1.sql.gz
   ```
6. Commit the resulting `.sql.gz`. Expect 5-30 MB depending on whether you
   loaded sample data.

## Keeping dumps small

- `--no-data` on tables you don't need (sessions, search index, log_*).
- `gzip -9` always.
- Don't dump the `password_reset_request_event` and `flag` tables.

## When to regenerate

- Adding a new Magento version to the CI matrix.
- After a meaningful schema change in core that breaks fixture replay.
- When you intentionally want different baseline data (new sample products,
  different store config).

Re-running the install script will pick up the new dump automatically — the
script always re-creates the schema baseline then restores on top.
