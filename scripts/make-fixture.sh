#!/usr/bin/env bash
#
# Generate a scrubbed DB dump from an existing Magento install and place it
# under fixtures/db/ as a committable fixture.
#
# Approach: clone the source DB to a temp DB, run an aggressive scrub pass
# (TRUNCATEs PII/secret/business tables, DELETEs sensitive rows from
# core_config_data), dump the temp DB, gzip, drop the temp DB. The source
# DB is never modified.
#
# Usage:  ./scripts/make-fixture.sh <edition> <version>
# Example:./scripts/make-fixture.sh community 2.4.8-p5
#
# Configuration (env vars, all optional):
#   SOURCE_DB_CONTAINER   Docker container of the source MariaDB.
#                         Default: taxcloud-db-1 (Warden's default name).
#   MAGENTO_SOURCE_DIR    Root of the source Magento install (for env.php).
#                         Default: $MODULE_ROOT/../../..  (= the Magento
#                         install this module is mounted into).

set -euo pipefail

EDITION="${1:-}"
VERSION="${2:-}"

if [[ -z "$EDITION" || -z "$VERSION" ]]; then
    echo "Usage: $0 <community|enterprise> <version>"
    exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$MODULE_ROOT"

SOURCE_DB_CONTAINER="${SOURCE_DB_CONTAINER:-taxcloud-db-1}"
SOURCE_DB_ROOT_PASSWORD="${SOURCE_DB_ROOT_PASSWORD:-}"
MAGENTO_SOURCE_DIR="${MAGENTO_SOURCE_DIR:-$MODULE_ROOT/../../../..}"
ENV_PHP="$MAGENTO_SOURCE_DIR/app/etc/env.php"

if [[ ! -f "$ENV_PHP" ]]; then
    echo "ERROR: cannot find $ENV_PHP — set MAGENTO_SOURCE_DIR to the Magento install root." >&2
    exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$SOURCE_DB_CONTAINER"; then
    echo "ERROR: source DB container '$SOURCE_DB_CONTAINER' is not running." >&2
    echo "Set SOURCE_DB_CONTAINER if your container has a different name." >&2
    exit 1
fi

# Pull DB connection details out of env.php without echoing the password.
DB_NAME=$(php -r '$c=require $argv[1]; echo $c["db"]["connection"]["default"]["dbname"];' "$ENV_PHP")
DB_USER=$(php -r '$c=require $argv[1]; echo $c["db"]["connection"]["default"]["username"];' "$ENV_PHP")
DB_PASS=$(php -r '$c=require $argv[1]; echo $c["db"]["connection"]["default"]["password"];' "$ENV_PHP")

# Root password (needed for CREATE/DROP DATABASE on the temp DB). Auto-detect
# from the container env if not provided — MariaDB containers normally expose
# MYSQL_ROOT_PASSWORD via the entrypoint env.
if [[ -z "$SOURCE_DB_ROOT_PASSWORD" ]]; then
    SOURCE_DB_ROOT_PASSWORD=$(docker exec "$SOURCE_DB_CONTAINER" \
        sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' 2>/dev/null || true)
fi
if [[ -z "$SOURCE_DB_ROOT_PASSWORD" ]]; then
    echo "ERROR: could not determine the source DB root password." >&2
    echo "Export SOURCE_DB_ROOT_PASSWORD=... and re-run." >&2
    exit 1
fi

TMP_DB="magento_fixture_tmp_$$"
OUT_FILE="$MODULE_ROOT/fixtures/db/magento-fixture-${EDITION}-${VERSION}.sql.gz"

# Helpers — keep secrets off argv via MYSQL_PWD env. Use root for privileged
# CREATE/DROP/GRANT, magento user for the actual dump pipe.
mariadb_root() {
    docker exec -i -e MYSQL_PWD="$SOURCE_DB_ROOT_PASSWORD" "$SOURCE_DB_CONTAINER" \
        mariadb -uroot "$@"
}
mariadb_exec() {
    docker exec -i -e MYSQL_PWD="$DB_PASS" "$SOURCE_DB_CONTAINER" \
        mariadb -u"$DB_USER" "$@"
}
mariadb_dump() {
    docker exec -i -e MYSQL_PWD="$DB_PASS" "$SOURCE_DB_CONTAINER" \
        mariadb-dump -u"$DB_USER" --single-transaction --no-tablespaces --quick "$@"
}

cleanup() {
    echo "==> Dropping temp DB $TMP_DB"
    mariadb_root -e "DROP DATABASE IF EXISTS \`$TMP_DB\`;" 2>/dev/null || true
}
trap cleanup EXIT

# --- 1. Clone source DB to temp DB ----------------------------------------

echo "==> Cloning $DB_NAME → $TMP_DB (this can take a minute)..."
mariadb_root -e "
    CREATE DATABASE \`$TMP_DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
    GRANT ALL PRIVILEGES ON \`$TMP_DB\`.* TO '$DB_USER'@'%';
    FLUSH PRIVILEGES;
"

# Pipe dump from source DB into temp DB, inside the container (no host transit).
docker exec -i \
    -e SOURCE_PW="$DB_PASS" \
    "$SOURCE_DB_CONTAINER" sh -c '
        MYSQL_PWD="$SOURCE_PW" mariadb-dump -u'"$DB_USER"' --single-transaction --no-tablespaces --quick '"$DB_NAME"' \
        | MYSQL_PWD="$SOURCE_PW" mariadb -u'"$DB_USER"' '"$TMP_DB"'
    '

# --- 2. Scrub the temp DB --------------------------------------------------

echo "==> Scrubbing PII, secrets, and business data from $TMP_DB..."

# Tables to TRUNCATE if present. Anything here either contains PII / secrets,
# or rebuilds itself on first Magento boot. Add to this list when you've
# installed third-party modules that store sensitive data in their own tables.
TRUNCATE_TABLES=(
    # Active admin sessions + UI messages — no value in shipping, refresh on
    # boot. admin_user, admin_passwords, admin_user_expiration, and
    # authorization_role / authorization_rule are deliberately KEPT so the
    # fixture ships with a working admin login (default: admin / 1234567a)
    # and the Administrators role exists for setup:upgrade.
    admin_user_session admin_system_messages

    # Customers, addresses, sessions
    customer_entity customer_entity_datetime customer_entity_decimal
    customer_entity_int customer_entity_text customer_entity_varchar
    customer_address_entity customer_address_entity_datetime
    customer_address_entity_decimal customer_address_entity_int
    customer_address_entity_text customer_address_entity_varchar
    customer_log customer_visitor customer_grid_flat persistent_session

    # Sales (orders / invoices / credit memos / shipments / payments)
    sales_order sales_order_address sales_order_grid sales_order_item
    sales_order_payment sales_order_status_history sales_order_tax
    sales_order_tax_item sales_invoice sales_invoice_grid sales_invoice_item
    sales_invoice_comment sales_creditmemo sales_creditmemo_grid
    sales_creditmemo_item sales_creditmemo_comment sales_shipment
    sales_shipment_grid sales_shipment_item sales_shipment_track
    sales_shipment_comment sales_payment_transaction sales_sequence_meta
    sales_sequence_profile

    # Quotes (active carts)
    quote quote_address quote_address_item quote_item quote_item_option
    quote_payment quote_shipping_rate quote_id_mask

    # Newsletter
    newsletter_subscriber newsletter_problem newsletter_queue
    newsletter_queue_link

    # Reviews
    review review_detail review_entity_summary review_store
    rating_option_vote rating_option_vote_aggregated

    # Reports, logs, search history, visitor tracking
    reports_event reports_viewed_product_index search_query
    catalogsearch_recommendations captcha_log password_reset_request_event
    email_abandoned_cart

    # API tokens, integrations, OAuth
    integration oauth_token oauth_consumer oauth_token_request_log oauth_nonce

    # Queue / message bus (may contain queued PII)
    queue_message queue_message_status queue queue_lock queue_poison_pill

    # Business data
    shipping_tablerate
    klarna_payments_quote amazon_customer
    paypal_billing_agreement paypal_payment_transaction

    # Aggregated sales reports
    sales_bestsellers_aggregated_daily sales_bestsellers_aggregated_monthly
    sales_bestsellers_aggregated_yearly sales_invoiced_aggregated
    sales_invoiced_aggregated_order sales_refunded_aggregated
    sales_refunded_aggregated_order sales_order_aggregated_created
    sales_order_aggregated_updated tax_order_aggregated_created
    tax_order_aggregated_updated

    # Transient — Magento rebuilds these on demand
    mview_state indexer_state cron_schedule
)

# Intersect against what actually exists in the temp DB so a missing
# extension table doesn't abort the scrub mid-way.
EXISTING_TABLES=$(mariadb_exec "$TMP_DB" -B -N -e "SHOW TABLES;")

TRUNCATED=()
SKIPPED=()
for t in "${TRUNCATE_TABLES[@]}"; do
    if grep -qx "$t" <<<"$EXISTING_TABLES"; then
        TRUNCATED+=("$t")
    else
        SKIPPED+=("$t")
    fi
done

# Build a single SQL batch — one round-trip, transactional truncate list.
{
    echo "SET FOREIGN_KEY_CHECKS = 0;"
    for t in "${TRUNCATED[@]}"; do
        echo "TRUNCATE TABLE \`$t\`;"
    done
    # Sensitive rows in core_config_data (TaxCloud keys, payment gateways,
    # SMTP passwords, encryption-marked fields, API tokens, etc.)
    cat <<'SQL'
DELETE FROM core_config_data
WHERE path LIKE 'tax/taxcloud_settings/%'
   OR path LIKE '%password%'
   OR path LIKE '%api_key%'
   OR path LIKE '%apikey%'
   OR path LIKE '%api_id%'
   OR path LIKE '%/key'
   OR path LIKE '%/secret'
   OR path LIKE '%token%'
   OR path LIKE 'payment/%'
   OR path LIKE 'system/smtp/%'
   OR path LIKE 'trans_email/%'
   OR path LIKE 'admin/security/%'
   OR path LIKE 'oauth/%'
   -- Store contact info + custom domains. shipping/origin is intentionally
   -- NOT scrubbed — it's required for TaxCloud tests and the default
   -- placeholder is a public landmark, not PII.
   OR path LIKE 'general/store_information/%'
   OR path = 'web/unsecure/base_url'
   OR path = 'web/secure/base_url';
SET FOREIGN_KEY_CHECKS = 1;
SQL
} | mariadb_exec "$TMP_DB"

echo "    truncated ${#TRUNCATED[@]} tables; skipped ${#SKIPPED[@]} (not present in this install)"

# --- 3. Dump the scrubbed temp DB -----------------------------------------

mkdir -p "$(dirname "$OUT_FILE")"
echo "==> Dumping scrubbed fixture to $OUT_FILE..."

mariadb_dump "$TMP_DB" | gzip -9 > "$OUT_FILE"

SIZE=$(du -h "$OUT_FILE" | awk '{print $1}')
echo
echo "==> Done. Fixture written: $OUT_FILE ($SIZE)"
echo
echo "Recommended pre-commit sanity check:"
echo "  gunzip -c \"$OUT_FILE\" | grep -Ei 'tax(cloud)?|api_key|password|@[a-z]+\\.com' | head"
echo "(any hits should be reviewed before committing)"
