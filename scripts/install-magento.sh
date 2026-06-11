#!/usr/bin/env bash
#
# Install a Magento Open Source or Adobe Commerce instance into the integration
# test stack (docker-compose.yml), mount this module into it, restore the DB
# from a committed fixture, and configure TaxCloud credentials.
#
# Idempotent: re-running skips composer create-project if Magento is already
# installed at the target location; setup:install + dump restore always run to
# guarantee clean schema state.
#
# Usage:  ./scripts/install-magento.sh <edition> <version>
# Example: ./scripts/install-magento.sh community 2.4.8-p5
#          ./scripts/install-magento.sh enterprise 2.4.8-p5
#
# Required env vars (sourced from .env, or exported in CI):
#   TAXCLOUD_API_ID, TAXCLOUD_API_KEY
#   MAGENTO_PUBLIC_KEY, MAGENTO_PRIVATE_KEY  (Marketplace creds; required for
#                                             both editions today — see
#                                             docs/INTEGRATION_TESTS.md)
#
# Optional:
#   MAGENTO_INSTALL_DIR — host path for the Magento install
#                         (default: ../magento-<edition>-<version>)

set -euo pipefail

EDITION="${1:-}"
VERSION="${2:-}"

if [[ -z "$EDITION" || -z "$VERSION" ]]; then
    echo "Usage: $0 <community|enterprise> <version>"
    echo "Example: $0 community 2.4.8-p5"
    exit 2
fi

if [[ "$EDITION" != "community" && "$EDITION" != "enterprise" ]]; then
    echo "ERROR: edition must be 'community' or 'enterprise', got '$EDITION'"
    exit 2
fi

# Load .env if present (CI exports these directly; local devs use .env).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$MODULE_ROOT"

if [[ -f .env ]]; then
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

export MAGENTO_EDITION="$EDITION"
export MAGENTO_VERSION="$VERSION"
export PHP_VERSION="${PHP_VERSION:-8.3}"

DEFAULT_INSTALL_DIR="$MODULE_ROOT/../magento-${EDITION}-${VERSION}"
MAGENTO_INSTALL_DIR="${MAGENTO_INSTALL_DIR:-$DEFAULT_INSTALL_DIR}"
export MAGENTO_INSTALL_DIR

FIXTURE_REL="fixtures/db/magento-fixture-${EDITION}-${VERSION}.sql.gz"
FIXTURE_ABS="$MODULE_ROOT/$FIXTURE_REL"

# --- 1. Preflight ----------------------------------------------------------

if [[ ! -f "$FIXTURE_ABS" ]]; then
    cat >&2 <<EOF
ERROR: DB fixture not found: $FIXTURE_REL

Integration tests require a pre-built database dump that matches the requested
Magento edition + version. See docs/INTEGRATION_TESTS.md for the procedure to
generate and commit one.

Looked for: $FIXTURE_ABS
EOF
    exit 1
fi

if [[ -z "${TAXCLOUD_API_ID:-}" || -z "${TAXCLOUD_API_KEY:-}" ]]; then
    echo "ERROR: TAXCLOUD_API_ID and TAXCLOUD_API_KEY must be set in .env (or env)." >&2
    echo "See .env.example for guidance." >&2
    exit 1
fi

if [[ -z "${MAGENTO_PUBLIC_KEY:-}" || -z "${MAGENTO_PRIVATE_KEY:-}" ]]; then
    echo "ERROR: MAGENTO_PUBLIC_KEY and MAGENTO_PRIVATE_KEY required for $EDITION." >&2
    echo "Get free Marketplace keys at https://commercemarketplace.adobe.com/customer/accessKeys/" >&2
    exit 1
fi

mkdir -p "$MAGENTO_INSTALL_DIR"

COMPOSER_CACHE_DIR_RESOLVED="${COMPOSER_CACHE_DIR:-$MODULE_ROOT/.composer-cache}"
mkdir -p "$COMPOSER_CACHE_DIR_RESOLVED"
# The container runs as a non-root user; relax host perms so it can write.
chmod 777 "$COMPOSER_CACHE_DIR_RESOLVED"

echo "==> Bringing up integration test stack (PHP $PHP_VERSION, Magento $EDITION $VERSION)..."
docker compose up -d --wait

# --- 2. Composer auth ------------------------------------------------------

# The container's app user (uid 1000) can't write to /var/www/.composer (owned
# by root), so we write the auth file as root and leave it world-readable. It
# only contains the Marketplace public/private keys, which already exist in the
# environment of every process in this container anyway.
echo "==> Writing repo.magento.com auth..."
docker compose exec -T -u root app sh -c "cat > /var/www/.composer/auth.json" <<EOF
{
  "http-basic": {
    "repo.magento.com": {
      "username": "$MAGENTO_PUBLIC_KEY",
      "password": "$MAGENTO_PRIVATE_KEY"
    }
  }
}
EOF
docker compose exec -T -u root app chmod 644 /var/www/.composer/auth.json

# --- 3. Composer create-project (idempotent) ------------------------------

if docker compose exec -T app test -f /var/www/html/composer.json; then
    echo "==> Magento already present at $MAGENTO_INSTALL_DIR, skipping composer create-project."
else
    if [[ "$EDITION" == "community" ]]; then
        PACKAGE="magento/project-community-edition"
    else
        PACKAGE="magento/project-enterprise-edition"
    fi
    echo "==> composer create-project ${PACKAGE}:${VERSION}..."
    docker compose exec -T app composer create-project \
        --repository-url=https://repo.magento.com/ \
        --no-interaction \
        "${PACKAGE}=${VERSION}" \
        .
fi

# --- 4. Mount this module under app/code/Taxcloud/Magento2 ----------------
#
# Symlink only the production-runtime subtree, mirroring composer.json's
# archive.exclude. A flat `ln -s /srv/module` would pull in Test/ — and
# Test/Unit/Mocks/MagentoMocks.php redeclares Magento\* interfaces, which
# bin/magento setup:di:compile explodes on as it autoload-walks the module.

echo "==> Linking module into app/code/Taxcloud/Magento2 (production paths only)..."
docker compose exec -T app sh -c '
    set -e
    mkdir -p /var/www/html/app/code/Taxcloud
    rm -rf /var/www/html/app/code/Taxcloud/Magento2
    mkdir -p /var/www/html/app/code/Taxcloud/Magento2
    cd /var/www/html/app/code/Taxcloud/Magento2

    # registration.php must be a real file, not a symlink. It uses __DIR__
    # to tell Magento where the module lives, and PHP resolves __DIR__ through
    # symlinks to the *real* path. If we symlinked registration.php → /srv/module,
    # Magento would register the module at /srv/module, and PSR-4 autoload of
    # Taxcloud\Magento2\Test\Unit\* would find files we never symlinked into
    # app/code/. Copying breaks that chain.
    cp /srv/module/registration.php registration.php

    for d in Logger Model Observer Setup etc; do
        ln -s /srv/module/$d $d
    done
    for f in composer.json LICENSE.txt CHANGELOG.md README.md; do
        [ -e /srv/module/$f ] && ln -s /srv/module/$f $f
    done
    # Test/Integration is exposed so PHPUnit can discover + PSR-4 autoload
    # the smoke tests. Test/Unit is intentionally NOT exposed.
    mkdir -p Test
    ln -s /srv/module/Test/Integration Test/Integration
'

# --- 5. Reset Magento install state ---------------------------------------
#
# setup:install refuses to run if env.php exists, and Magento's own data
# patches leave the DB in a half-state if interrupted (admin user inserted
# but Administrators role missing, for example). We always start with a
# clean slate — the composer install on disk is preserved, but the DB and
# generated config are nuked.

echo "==> Resetting Magento install state (DB + env.php + generated/)..."
docker compose exec -T db sh -c \
    'MYSQL_PWD=magento mariadb -uroot -e "DROP DATABASE IF EXISTS magento; CREATE DATABASE magento; GRANT ALL PRIVILEGES ON magento.* TO '"'"'magento'"'"'@'"'"'%'"'"'; FLUSH PRIVILEGES;"'
docker compose exec -T -u root app sh -c '
    rm -f /var/www/html/app/etc/env.php /var/www/html/app/etc/config.php
    rm -rf /var/www/html/generated/code /var/www/html/generated/metadata
    rm -rf /var/www/html/var/cache/* /var/www/html/var/page_cache/* /var/www/html/var/view_preprocessed/*
'

# --- 6. setup:install (schema baseline before dump restore) ---------------

echo "==> bin/magento setup:install (schema baseline)..."
docker compose exec -T app bin/magento setup:install \
    --base-url=http://localhost/ \
    --db-host=db --db-name=magento --db-user=magento --db-password=magento \
    --admin-firstname=Admin --admin-lastname=User \
    --admin-email=admin@example.com \
    --admin-user=admin --admin-password='Admin123!' \
    --language=en_US --currency=USD --timezone=America/Chicago \
    --use-rewrites=1 \
    --search-engine=opensearch \
    --opensearch-host=opensearch \
    --opensearch-port=9200 \
    --session-save=files \
    --cache-backend=redis --cache-backend-redis-server=redis --cache-backend-redis-db=0 \
    --page-cache=redis  --page-cache-redis-server=redis  --page-cache-redis-db=1 \
    --no-interaction

# --- 7. Restore committed DB fixture --------------------------------------

echo "==> Dropping schema and restoring fixture: $FIXTURE_REL"
docker compose exec -T db sh -c \
    'mariadb -uroot -pmagento -e "DROP DATABASE magento; CREATE DATABASE magento;"'
gunzip -c "$FIXTURE_ABS" \
    | docker compose exec -T db mariadb -uroot -pmagento magento

# --- 8. Module enable, DI compile, indexers -------------------------------

echo "==> bin/magento module:enable / setup:upgrade / di:compile..."
docker compose exec -T app bin/magento module:enable Taxcloud_Magento2 || true
docker compose exec -T app bin/magento setup:upgrade --no-interaction
docker compose exec -T app bin/magento setup:di:compile

# --- 9. Inject TaxCloud credentials ---------------------------------------

echo "==> Injecting TaxCloud credentials into core_config_data..."
docker compose exec -T app bin/magento config:set tax/taxcloud_settings/api_id "$TAXCLOUD_API_ID"
docker compose exec -T app bin/magento config:set tax/taxcloud_settings/api_key "$TAXCLOUD_API_KEY"
docker compose exec -T app bin/magento config:set tax/taxcloud_settings/enabled 1

docker compose exec -T app bin/magento cache:flush

echo
echo "==> Install complete."
echo "    Magento $EDITION $VERSION at $MAGENTO_INSTALL_DIR"
echo "    Run tests:   docker compose exec -T -w /var/www/html app vendor/bin/phpunit -c /srv/module/phpunit.integration.xml.dist"
echo "    Or use:      make integration-test"
