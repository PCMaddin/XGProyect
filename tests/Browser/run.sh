#!/usr/bin/env bash
# The browser test as the CI runs it: install the game into an empty
# database, seed it (tests/Browser/seed.php), serve it and crawl every page
# in English and German (tests/Browser/crawl.mjs).
#
#   DB_DATABASE=xgp_browser DB_USERNAME=xgp DB_PASSWORD=xgp tests/Browser/run.sh
#
# The database must exist and be empty. Needs node with playwright
# (npm install --no-save playwright && npx playwright install chromium).
# A config/xgp-db-config.php of the web installer is put aside for the run:
# the game takes its database from the environment here.
set -euo pipefail
cd "$(dirname "$0")/../.."

: "${DB_DATABASE:?set DB_DATABASE (an empty database)}"
export DB_CONNECTION=mysql DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-3306}" DB_PREFIX="${DB_PREFIX:-xgp_}"
export APP_ENV=production APP_DEBUG=false XGP_SECRETWORD="${XGP_SECRETWORD:-browser-test}"
export SESSION_DRIVER=file SESSION_SECURE_COOKIE=false CACHE_DRIVER=file CACHE_STORE=file MAIL_MAILER=log
export XGP_ADMIN_TWO_FACTOR=false
port="${BROWSER_PORT:-8001}"
export APP_URL="http://127.0.0.1:$port"

config=config/xgp-db-config.php
created=''
if ! cmp -s "$config" deploy/php/xgp-db-config.php; then
    if [ -f "$config" ]; then mv "$config" "$config.browser-test"; fi
    created=1
fi
server=''
finish() {
    if [ -n "$server" ]; then kill "$server" 2>/dev/null || true; fi
    if [ -n "$created" ]; then rm -f "$config"; fi
    if [ -f "$config.browser-test" ]; then mv "$config.browser-test" "$config"; fi
    php artisan config:clear >/dev/null 2>&1 || true
}
trap finish EXIT
cp deploy/php/xgp-db-config.php "$config"
php artisan config:clear >/dev/null

php artisan xgp:install --admin-name=Admin --admin-email=admin@example.com --admin-password=Geheim12345
php tests/Browser/seed.php

php artisan serve --host=127.0.0.1 --port="$port" > storage/logs/browser-serve.log 2>&1 &
server=$!
for _ in $(seq 1 30); do
    curl -fs -o /dev/null "$APP_URL/" && break
    sleep 1
done

status=0
for lang in en de; do
    php artisan tinker --execute="app(App\Services\SettingsService::class)->write('lang', '$lang');" >/dev/null
    node tests/Browser/crawl.mjs "$APP_URL" "$lang" || status=1
done
exit $status
