#!/usr/bin/env bash
# End-to-end tests of the plugin in a real, throwaway Sylius (the plugin's
# test application, on the working tree: uncommitted changes are tested
# too): starts its own database and PHP containers (never the dev ones), then
#
#   1. creates the database with two channels (E2E_PRO with its own webhook
#      secret) and an admin (e2e/config.yaml);
#   2. sends real signed HTTP calls to the webhook and checks the prices;
#   3. checks the sync page is behind the admin login, then runs a sync from
#      the admin in a browser (Playwright), against a fake Pricee API running
#      in the PHP container, and checks what it got.
#
# Needs the installed dependencies (vendor/, composer install) and the test
# application's built assets (vendor/sylius/test-application/public/build).
#
# Usage: e2e/run.sh [Sylius PHP image]
# Env: E2E_PORT (8084), E2E_KEEP=1 to leave the containers running for debugging.
set -euo pipefail

PHP_IMAGE="${1:-ghcr.io/sylius/sylius-php:8.3-alpine}"
PORT="${E2E_PORT:-8084}"
# Same version as the playwright package in e2e/package.json
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAME="pricee-sylius-e2e-${PORT}"
DB="${NAME}-db"
SHOP="${NAME}-shop"
SHOP_URL="http://localhost:${PORT}"
WORK="$(mktemp -d)"

step() { printf '\n== %s\n' "$*"; }
in_shop() { docker exec "$SHOP" "$@"; }
console() { in_shop php -d date.timezone=UTC vendor/bin/console "$@"; }

cleanup() {
    if [ "${E2E_KEEP:-0}" = "1" ]; then
        echo "Containers kept: $SHOP (${SHOP_URL}/admin/, e2e / pricee_e2e_2026) and $DB"
    else
        docker rm -f "$SHOP" "$DB" >/dev/null 2>&1 || true
        docker network rm "$NAME" >/dev/null 2>&1 || true
        # Kept with the containers: it is the shop's media folder
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

[ -f "$ROOT/vendor/autoload.php" ] || { echo "Run composer install first"; exit 1; }
[ -d "$ROOT/vendor/sylius/test-application/public/build" ] || { echo "Build the test application assets first (composer frontend-clear)"; exit 1; }

step "Starting Sylius with ${PHP_IMAGE} on port ${PORT}"
docker rm -f "$SHOP" "$DB" >/dev/null 2>&1 || true
docker network rm "$NAME" >/dev/null 2>&1 || true
docker network create "$NAME" >/dev/null
docker run -d --name "$DB" --network "$NAME" -e MYSQL_ALLOW_EMPTY_PASSWORD=1 mysql:8.4 >/dev/null
for i in $(seq 1 60); do
    docker exec "$DB" mysql -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
    [ "$i" = 60 ] && { docker logs "$DB"; exit 1; }
    sleep 2
done

# The plugin's working tree, read-only: the cache and logs go to the container.
# PHP's built-in server with workers, as the sync page calls the server while
# the server calls the fake Pricee API (another PHP server, on port 8090)
mkdir -p "$WORK/media"
docker run -d --name "$SHOP" --network "$NAME" -p "127.0.0.1:${PORT}:8000" \
    --user "$(id -u):$(id -g)" -e HOME=/tmp -w /srv/sylius \
    -v "$ROOT:/srv/sylius:ro" -v "$WORK/media:/srv/sylius/vendor/sylius/test-application/public/media" \
    -e APP_ENV=test -e APP_DEBUG=0 -e APP_SECRET=e2e -e APP_CACHE_DIR=/tmp/sylius/cache -e APP_LOG_DIR=/tmp/sylius/log \
    -e DATABASE_URL="mysql://root@${DB}/sylius_e2e?serverVersion=8.4" \
    -e SYLIUS_TEST_APP_CONFIGS_TO_IMPORT="@PriceeIOSyncPlugin/tests/TestApplication/config/config.yaml;@PriceeIOSyncPlugin/e2e/config.yaml" \
    -e PRICEEIO_CLIENT_ID=e2e-client -e PRICEEIO_API_KEY=e2e-key \
    -e PRICEEIO_WEBHOOK_ENABLED=1 -e PRICEEIO_WEBHOOK_SECRET=e2e-webhook-secret \
    -e PRICEEIO_API_BASE_URL=http://127.0.0.1:8090/api/v1/ \
    -e PRICEE_E2E_WEBSITE_URL=http://localhost \
    -e PHP_CLI_SERVER_WORKERS=4 -e PHP_DATE_TIMEZONE=UTC \
    --entrypoint php "$PHP_IMAGE" -d date.timezone=UTC \
    -d memory_limit=1G -S 0.0.0.0:8000 -t vendor/sylius/test-application/public vendor/sylius/test-application/public/index.php >/dev/null

console doctrine:database:create -n --if-not-exists >/dev/null
console doctrine:migrations:migrate -n >/dev/null
console sylius:fixtures:load pricee_e2e -n >/dev/null
console cache:warmup >/dev/null
echo "Sylius $(console --version | sed -n 's/.*Sylius \([^ ]*\).*/\1/p' || true) ready"

step "Webhook over HTTP"
in_shop php -d date.timezone=UTC e2e/shop.php webhook

step "Sync from the admin"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "${SHOP_URL}/admin/priceeio/sync")
case "$code" in
    "302 ${SHOP_URL}/admin/login") echo "ok - sync page redirects to the admin login when not logged in" ;;
    *) echo "FAIL: sync page answers ${code} when not logged in"; exit 1 ;;
esac
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SHOP_URL}/admin/priceeio/sync/run")
[ "$code" = 302 ] || { echo "FAIL: sync run answers ${code} when not logged in"; exit 1; }
echo "ok - sync run refused when not logged in"

docker exec -d "$SHOP" php -S 127.0.0.1:8090 e2e/fake-api.php
for i in $(seq 1 20); do
    in_shop php -d date.timezone=UTC -r 'exit(false === @file_get_contents("http://127.0.0.1:8090/_state") ? 1 : 0);' && break
    [ "$i" = 20 ] && { echo "Fake Pricee API did not start"; exit 1; }
    sleep 1
done
in_shop php -d date.timezone=UTC e2e/shop.php sync-fixtures

# In Playwright's image (its own Node and Chromium, nothing to install on the
# host), on the host network so that localhost:<port> is the shop
mkdir -p "$ROOT/e2e/output"
docker run --rm --network host --ipc=host --user "$(id -u):$(id -g)" -e HOME=/tmp \
    -v "$ROOT/e2e:/e2e:ro" -v "$ROOT/e2e/output:/output" \
    -e SHOP_URL="$SHOP_URL" -e ADMIN_USER=e2e@example.com -e ADMIN_PASSWD=pricee_e2e_2026 \
    -e SYNC_CATEGORY="Pricee E2E" -e SYNC_PRODUCTS=55 \
    "$PLAYWRIGHT_IMAGE" \
    sh -c 'cp /e2e/package.json /e2e/package-lock.json /e2e/sync.mjs /tmp/ && cd /tmp && npm ci --silent --no-audit --no-fund && node sync.mjs'
in_shop php -d date.timezone=UTC e2e/shop.php check-sync

step "All end-to-end tests passed"
