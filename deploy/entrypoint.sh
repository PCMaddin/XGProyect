#!/bin/sh
# The configuration comes from the environment: cached at every start, never
# baked into the image (it holds the keys).
set -eu

php artisan config:cache --no-interaction >/dev/null

# this version of the code into the versions volume (once), and the map of
# the universes on other versions for the router; a copy that fails must
# not keep the game from serving
if [ -n "${XGP_VERSIONS_PATH:-}" ] && [ -d "$XGP_VERSIONS_PATH" ]; then
    php artisan xgp:versions:sync --no-interaction || echo "xgp:versions:sync failed; universes on older versions may not be served" >&2
fi

# the web server serves the static files from a volume shared with it; old
# files stay, so pages of the version still running find theirs. One
# container at a time: two copying the same file at once fail.
if [ -n "${PUBLIC_DIR:-}" ] && [ -d "$PUBLIC_DIR" ]; then
    flock "$PUBLIC_DIR/.copying" cp -R public/. "$PUBLIC_DIR"/
fi

# php-fpm listens for the web server only once the game has rendered its
# start page in this container: a broken release never gets a request
if [ "${1:-}" = php-fpm ]; then
    php-fpm --fpm-config /usr/local/etc/php-fpm-selftest.conf
    status=""
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        status="$(REQUEST_METHOD=GET REQUEST_URI=/ QUERY_STRING= SCRIPT_NAME=/index.php \
            SCRIPT_FILENAME=/srv/app/public/index.php DOCUMENT_ROOT=/srv/app/public SERVER_NAME=localhost \
            SERVER_PORT=443 HTTPS=on REMOTE_ADDR=127.0.0.1 cgi-fcgi -bind -connect 127.0.0.1:9001 2>/dev/null | head -n 1 || true)"
        [ -n "$status" ] && break
        sleep 1
    done
    kill "$(cat /tmp/php-fpm-selftest.pid)"
    case "$status" in
        "Status: 5"*|"")
            echo "The game does not render its start page (${status:-no answer}); not serving." >&2
            exit 1
            ;;
    esac
fi

exec "$@"
