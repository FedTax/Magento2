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

# --- E2E mode --------------------------------------------------------------
#
# E2E=1 overlays docker-compose.e2e.yml (adds an nginx container and runs
# php-fpm in the app container) and serves Magento over HTTP so Playwright can
# reach it. Integration installs (E2E unset) are byte-for-byte unchanged: no
# nginx, app still runs `sleep infinity`, base URL still http://localhost/.
if [ "${E2E:-0}" = "1" ]; then
    # docker compose honors COMPOSE_FILE (':'-separated) on every invocation in
    # this script, so we set it once instead of threading -f flags everywhere.
    export COMPOSE_FILE="docker-compose.yml:docker-compose.e2e.yml"
    MAGENTO_BASE_URL="${MAGENTO_BASE_URL:-http://localhost:8080/}"
else
    MAGENTO_BASE_URL="${MAGENTO_BASE_URL:-http://localhost/}"
fi
# Magento requires the base URL to end in a slash.
case "$MAGENTO_BASE_URL" in
    */) ;;
    *)  MAGENTO_BASE_URL="$MAGENTO_BASE_URL/" ;;
esac
export MAGENTO_BASE_URL

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

# UNIT_ONLY=1 provisions just the Magento codebase for running unit tests:
# only the app (PHP) container — no MariaDB/OpenSearch/Redis, no setup:install.
if [ "${UNIT_ONLY:-0}" = "1" ]; then
    echo "==> Bringing up app container only (unit mode — Magento $EDITION $VERSION, PHP $PHP_VERSION; no DB/search)..."
    docker compose up -d --no-deps --wait app
else
    echo "==> Bringing up integration test stack (Magento $EDITION $VERSION, PHP $PHP_VERSION, MariaDB $MARIADB_VERSION, OpenSearch $OPENSEARCH_VERSION)..."
    docker compose up -d --wait
fi

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

    # Link every directory except the dev-only ones. This is a denylist, not an
    # allowlist: a new production directory (Plugin/, Block/, Ui/, ...) must be
    # picked up automatically, or di:compile fails with "class doesn'\''t exist"
    # for something that is plainly there in the repo.
    for d in /srv/module/*/; do
        name=$(basename "$d")
        case "$name" in
            Test|scripts|docs) continue ;;
        esac
        ln -s "/srv/module/$name" "$name"
    done
    for f in composer.json LICENSE.txt CHANGELOG.md README.md; do
        [ -e /srv/module/$f ] && ln -s /srv/module/$f $f
    done
    # Test/Integration is exposed so PHPUnit can discover + PSR-4 autoload
    # the smoke tests. Test/Unit is intentionally NOT exposed.
    mkdir -p Test
    ln -s /srv/module/Test/Integration Test/Integration
'

# Unit mode stops here: the codebase + the module linked into app/code is all
# the unit suite needs (production classes autoload via the symlink; the suite
# runs from /srv/module with MAGENTO_ROOT=/var/www/html). No DB, setup:install,
# di:compile or seeding required.
if [ "${UNIT_ONLY:-0}" = "1" ]; then
    echo
    echo "==> Codebase ready (unit mode). Magento $EDITION $VERSION at $MAGENTO_INSTALL_DIR"
    exit 0
fi

# --- 4c. E2E: install Magento's nginx vhost --------------------------------
#
# The E2E nginx container (docker-compose.e2e.yml) includes
# /var/www/html/nginx.conf. Magento ships it as nginx.conf.sample; copy it into
# place so the include resolves. nginx was started (empty) by `up` above; it
# gets reloaded at the end of this script once Magento is fully installed.
if [ "${E2E:-0}" = "1" ]; then
    echo "==> Installing Magento nginx.conf (from nginx.conf.sample)..."
    docker compose exec -T app sh -c 'cp /var/www/html/nginx.conf.sample /var/www/html/nginx.conf'
fi

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
# Fixed backend frontName (Magento randomizes it by default) so E2E admin tests
# can use a stable /admin/ URL. Harmless for unit/integration (they never hit the
# admin over HTTP); this is a throwaway test install, not production.
docker compose exec -T app bin/magento setup:install \
    --base-url="$MAGENTO_BASE_URL" \
    --backend-frontname=admin \
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

# E2E drives the admin UI, but Magento 2.4 forces admin Two-Factor Auth on first
# login, which can't be satisfied headlessly. Disable the 2FA modules for E2E
# installs only (integration/unit never touch the admin over HTTP). Names vary by
# version/edition, so tolerate absence.
if [ "${E2E:-0}" = "1" ]; then
    echo "==> Disabling admin 2FA modules (E2E admin tests)..."
    docker compose exec -T app bin/magento module:disable \
        Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth 2>/dev/null || true

    # Admin action URLs normally carry a per-session secret key, which blocks
    # deep-linking straight to admin pages (e.g. the Stores > Config editor).
    # Turn it off so E2E can navigate to admin URLs directly.
    echo "==> Disabling admin URL secret key (E2E admin tests)..."
    docker compose exec -T app bin/magento config:set admin/security/use_form_key 0 2>/dev/null || true

    # The module's directories are symlinks into /srv/module, so its .phtml
    # templates resolve outside the Magento root and the template validator
    # rejects them ("Invalid template file") unless symlinks are allowed.
    echo "==> Allowing symlinked templates (module is symlinked)..."
    docker compose exec -T app bin/magento config:set dev/template/allow_symlink 1 2>/dev/null || true
fi
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

# --- 8b. Enable test products in a fresh process ---------------------------
#
# On Adobe Commerce, Content Staging means the status set on a just-created
# product does NOT persist within the seed's process — products stay Disabled
# and Quote::addProduct() rejects them. Running the enable from a fresh process
# (which re-reads the committed version) sticks. No-op on Open Source.
echo "==> Enabling test products (fresh process; Adobe Commerce staging fix)..."
docker compose exec -T -w /var/www/html app \
    php /srv/module/scripts/enable-test-products.php

# --- 9. Reindex in a fresh process -----------------------------------------
#
# The seed reindexes in-process, but on some versions (seen on 2.4.7) that
# leaves a configurable product's MSI salability index stale — the parent isn't
# marked salable even though its children are, so Quote::addProduct() rejects it
# with "Product that you are trying to add is not available." A fresh-process
# `indexer:reindex` rebuilds it correctly. Cheap insurance; runs once per install.
echo "==> Reindexing (fresh process, fixes configurable salability)..."
docker compose exec -T -w /var/www/html app bin/magento indexer:reindex

# --- 10. E2E: reload nginx and confirm the storefront serves ---------------
#
# nginx came up before nginx.conf existed (so it started with an empty vhost).
# Now that Magento is installed and nginx.conf is in place, reload so the real
# config takes effect, then probe health_check.php so a broken serving setup
# fails here with a clear message rather than as a wall of Playwright errors.
if [ "${E2E:-0}" = "1" ]; then
    echo "==> Reloading nginx..."
    docker compose exec -T nginx nginx -s reload 2>/dev/null \
        || docker compose restart nginx

    echo "==> Waiting for storefront at ${MAGENTO_BASE_URL} ..."
    reachable=0
    for _ in $(seq 1 20); do
        if curl -fsS -o /dev/null "${MAGENTO_BASE_URL}health_check.php"; then
            reachable=1
            break
        fi
        sleep 2
    done
    if [ "$reachable" != "1" ]; then
        echo "ERROR: storefront did not become reachable at ${MAGENTO_BASE_URL}health_check.php" >&2
        echo "       Check 'docker compose logs nginx app'." >&2
        exit 1
    fi
    echo "==> Storefront reachable at ${MAGENTO_BASE_URL}"
fi

echo
echo "==> Install complete."
echo "    Magento $EDITION $VERSION at $MAGENTO_INSTALL_DIR"
echo "    Run tests:   docker compose exec -T -w /var/www/html app vendor/bin/phpunit -c /srv/module/phpunit.integration.xml.dist"
echo "    Or use:      make integration-test"
