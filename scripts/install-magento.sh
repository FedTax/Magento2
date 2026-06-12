#!/usr/bin/env bash
#
# Install a Magento Open Source or Adobe Commerce instance into the integration
# test stack (docker-compose.yml), mount this module into it, and seed the
# standard test environment programmatically (scripts/seed-test-data.php).
#
# Idempotent: re-running skips composer create-project if Magento is already
# installed at the target location; setup:install + seeding always run to
# guarantee clean schema state.
#
# Usage:  ./scripts/install-magento.sh <edition> <version> [php-version]
# Example: ./scripts/install-magento.sh community 2.4.8-p5
#          ./scripts/install-magento.sh enterprise 2.4.8-p5 8.2
#
# PHP version precedence: third argument > PHP_VERSION in .env / env > 8.3.
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
PHP_VERSION_ARG="${3:-}"

if [[ -z "$EDITION" || -z "$VERSION" ]]; then
    echo "Usage: $0 <community|enterprise> <version> [php-version]"
    echo "Example: $0 community 2.4.8-p5 8.3"
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
# Arg wins over .env (sourced above) so `make integration-test PHP_VERSION=8.2`
# beats a PHP_VERSION line in .env.
export PHP_VERSION="${PHP_VERSION_ARG:-${PHP_VERSION:-8.3}}"

# DB / search engine versions follow Magento's system requirements:
# 2.4.9 dropped MariaDB 10.x and OpenSearch 2.x. Derived from the Magento
# version unless explicitly set via env / .env.
case "$VERSION" in
    2.4.9*|2.5*)
        export MARIADB_VERSION="${MARIADB_VERSION:-11.4}"
        export OPENSEARCH_VERSION="${OPENSEARCH_VERSION:-3.0.0}"
        ;;
    *)
        export MARIADB_VERSION="${MARIADB_VERSION:-10.6}"
        export OPENSEARCH_VERSION="${OPENSEARCH_VERSION:-2.12.0}"
        ;;
esac

DEFAULT_INSTALL_DIR="$MODULE_ROOT/../magento-${EDITION}-${VERSION}"
MAGENTO_INSTALL_DIR="${MAGENTO_INSTALL_DIR:-$DEFAULT_INSTALL_DIR}"
export MAGENTO_INSTALL_DIR

# --- 1. Preflight ----------------------------------------------------------

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
# The app container runs as uid 1000, but CI runners create this dir as the
# runner user (uid 1001). Without world-write, composer create-project dies
# with "vendor/composer does not exist and could not be created". Same
# reasoning as the composer cache dir below.
chmod 777 "$MAGENTO_INSTALL_DIR"

COMPOSER_CACHE_DIR_RESOLVED="${COMPOSER_CACHE_DIR:-$MODULE_ROOT/.composer-cache}"
mkdir -p "$COMPOSER_CACHE_DIR_RESOLVED"
# The container runs as a non-root user; relax host perms so it can write.
chmod 777 "$COMPOSER_CACHE_DIR_RESOLVED"

echo "==> Bringing up integration test stack (Magento $EDITION $VERSION, PHP $PHP_VERSION, MariaDB $MARIADB_VERSION, OpenSearch $OPENSEARCH_VERSION)..."
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

# --- 6. setup:install (clean baseline) -------------------------------------

echo "==> bin/magento setup:install (clean baseline)..."
docker compose exec -T app bin/magento setup:install \
    --base-url=http://localhost/ \
    --db-host=db --db-name=magento --db-user=magento --db-password=magento \
    --admin-firstname=Admin --admin-lastname=User \
    --admin-email=admin@example.com \
    --admin-user=admin --admin-password='1234567a' \
    --language=en_US --currency=USD --timezone=America/Chicago \
    --use-rewrites=1 \
    --search-engine=opensearch \
    --opensearch-host=opensearch \
    --opensearch-port=9200 \
    --session-save=files \
    --cache-backend=redis --cache-backend-redis-server=redis --cache-backend-redis-db=0 \
    --page-cache=redis  --page-cache-redis-server=redis  --page-cache-redis-db=1 \
    --no-interaction

# --- 7. Module enable, DI compile -----------------------------------------

echo "==> bin/magento module:enable / setup:upgrade / di:compile..."
docker compose exec -T app bin/magento module:enable Taxcloud_Magento2 || true
docker compose exec -T app bin/magento setup:upgrade --no-interaction
docker compose exec -T app bin/magento setup:di:compile

# --- 8. Seed the standard test environment ---------------------------------
#
# Admin user, test category + product, TaxCloud config (creds come from the
# container env, wired through docker-compose.yml), shipping origin, active
# shipping carrier + payment method, reindex, cache flush. Idempotent — see
# scripts/seed-test-data.php for the full list.

echo "==> Seeding test environment (scripts/seed-test-data.php)..."
docker compose exec -T -w /var/www/html app \
    php /srv/module/scripts/seed-test-data.php

echo
echo "==> Install complete."
echo "    Magento $EDITION $VERSION at $MAGENTO_INSTALL_DIR"
echo "    Run tests:   docker compose exec -T -w /var/www/html app vendor/bin/phpunit -c /srv/module/phpunit.integration.xml.dist"
echo "    Or use:      make integration-test"
