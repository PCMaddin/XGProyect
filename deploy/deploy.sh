#!/usr/bin/env bash
#
# The game on this server, with one command: the first setup and every
# update. Builds the image, installs the game once, runs the migrations and
# replaces the app containers without the game going down.
#
#   deploy/deploy.sh                    build, migrate, roll out
#   deploy/deploy.sh --admin-name=Admin --admin-email=you@example.com
#                                       the first time: the administrator
#                                       (the password is asked)
#
# XGP_IMAGE=registry/xgproyect:1.2  roll out an image built elsewhere (CI)
#                                   instead of building here
# EXTRA_CA=/path/ca.crt     a CA the build must trust (a proxy that re-signs TLS)
# DOCKER_BUILD_NETWORK=host the network of the build (a proxy on localhost)
set -euo pipefail

cd "$(dirname "$0")"

say() { printf '\n== %s\n' "$*"; }
compose() { docker compose "$@"; }

# --- settings: deploy/.env, its secrets made once ---------------------------

if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    echo "deploy/.env created. Set XGP_DOMAIN, APP_URL, ACME_EMAIL and the mail server there, then run deploy.sh again."
    exit 1
fi

fill() {
    if grep -qE "^$1=$" .env; then
        sed -i "s|^$1=$|$1=$2|" .env
    fi
}

fill APP_KEY "base64:$(openssl rand -base64 32)"
fill XGP_SECRETWORD "$(openssl rand -hex 24)"
fill DB_PASSWORD "$(openssl rand -hex 24)"
fill DB_ROOT_PASSWORD "$(openssl rand -hex 24)"
chmod 600 .env

setting() { sed -n "s/^$1=//p" .env | tail -n 1; }

# --- the image ---------------------------------------------------------------

build_image() {
    # the commit and the time: two builds of one commit are two releases
    release="$(git rev-parse --short=12 HEAD 2>/dev/null || echo build)-$(date +%Y%m%d%H%M%S)"

    say "Building release $release"
    local build=(docker build -f Dockerfile -t "xgproyect-app:$release")
    if [ -n "${EXTRA_CA:-}" ]; then
        build+=(--secret "id=extra_ca,src=$EXTRA_CA")
    fi
    for proxy in HTTPS_PROXY https_proxy NO_PROXY no_proxy; do
        if [ -n "${!proxy:-}" ]; then
            build+=(--build-arg "$proxy")
        fi
    done
    if [ -n "${DOCKER_BUILD_NETWORK:-}" ]; then
        build+=(--network "$DOCKER_BUILD_NETWORK")
    fi
    "${build[@]}" ..
}

if [ -n "${XGP_IMAGE:-}" ]; then
    release="$(tr -c 'A-Za-z0-9_.\n-' '-' <<<"${XGP_IMAGE##*[:/]}")-$(date +%Y%m%d%H%M%S)"
    say "Release $release from $XGP_IMAGE"
    docker image inspect "$XGP_IMAGE" >/dev/null 2>&1 || docker pull "$XGP_IMAGE"
    docker tag "$XGP_IMAGE" "xgproyect-app:$release"
else
    build_image
fi

previous="$(setting XGP_RELEASE)"
if grep -q '^XGP_RELEASE=' .env; then
    sed -i "s|^XGP_RELEASE=.*|XGP_RELEASE=$release|" .env
else
    printf '\n# the release running now (written by deploy.sh)\nXGP_RELEASE=%s\n' "$release" >> .env
fi
export XGP_RELEASE="$release"

# --- database and Redis ------------------------------------------------------

say "Database and Redis"
compose up -d --wait db redis-cache redis-sessions
# the game's user creates the databases of the universes (xgp_uni_<slug>)
compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "GRANT ALL PRIVILEGES ON \`xgp\\_uni\\_%\`.* TO \"$MARIADB_USER\"@\"%\""'

# --- install once, then the migrations (before the new code serves) ----------

say "Install (only the first time) and migrations"
compose run --rm --no-deps app php artisan xgp:install "$@"
compose run --rm --no-deps -T app php artisan migrate --path=database/migrations/400 --force

if ! output="$(compose run --rm --no-deps -T app php artisan xgp:instance:migrate 2>&1)"; then
    if ! grep -q 'No universe registered' <<<"$output"; then
        echo "$output"
        echo "The migrations of the universes failed; the running version keeps serving."
        exit 1
    fi
fi
echo "$output"

# --- roll out: the new containers first, then the old ones go ----------------

replicas="$(setting APP_REPLICAS)"
replicas="${replicas:-2}"
# the app service's containers, without the one-off ones of "compose run"
# (the migrations' container may still be going away)
app_containers() {
    docker ps -q --filter label=com.docker.compose.project=xgproyect \
        --filter label=com.docker.compose.service=app --filter label=com.docker.compose.oneoff=False
}

mapfile -t old < <(app_containers)

say "Starting $replicas app containers of $release"
compose up -d --no-deps --no-recreate --scale "app=$(( ${#old[@]} + replicas ))" app

healthy() {
    [ "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$1" 2>/dev/null)" = healthy ]
}

# the start page, rendered by the game inside the container: php-fpm alone
# answering the health check does not mean the release works
renders() {
    docker exec "$1" sh -c 'REQUEST_METHOD=GET REQUEST_URI=/ QUERY_STRING= SCRIPT_NAME=/index.php \
        SCRIPT_FILENAME=/srv/app/public/index.php DOCUMENT_ROOT=/srv/app/public SERVER_NAME=localhost \
        SERVER_PORT=443 HTTPS=on REMOTE_ADDR=127.0.0.1 cgi-fcgi -bind -connect 127.0.0.1:9000' 2>/dev/null |
        head -n 1 | grep -qvE '^(Status: 5|$)'
}

mapfile -t current < <(app_containers)
new=()
for id in "${current[@]}"; do
    case " ${old[*]} " in *" $id "*) ;; *) new+=("$id") ;; esac
done

# a container serves only after rendering the start page (entrypoint.sh);
# one that failed that, or renders nothing now, ends the rollout at once
broken=""
for _ in $(seq 1 60); do
    ready=0
    for id in "${new[@]}"; do
        if [ "$(docker inspect -f '{{.RestartCount}}' "$id")" -gt 0 ]; then
            broken="$id"
        elif healthy "$id"; then
            renders "$id" || broken="$id"
            ready=$((ready + 1))
        fi
    done
    [ -n "$broken" ] || [ "$ready" -eq "${#new[@]}" ] && break
    sleep 2
done

if [ -n "$broken" ] || [ "$ready" -ne "${#new[@]}" ]; then
    echo "The new app containers did not get healthy or did not render the start page; they are removed, $previous keeps serving."
    docker logs --tail 50 "${broken:-${new[0]}}" || true
    docker rm -f "${new[@]}" >/dev/null
    sed -i "s|^XGP_RELEASE=.*|XGP_RELEASE=$previous|" .env
    docker image rm "xgproyect-app:$release" >/dev/null 2>&1 || true
    exit 1
fi

if [ "${#old[@]}" -gt 0 ]; then
    say "Stopping the ${#old[@]} app containers of the previous release"
    # php-fpm ends the requests it serves; the web server sends new ones to
    # the new containers
    docker stop "${old[@]}" >/dev/null
    docker rm "${old[@]}" >/dev/null
fi

say "Scheduler and web server"
compose up -d --wait --no-deps scheduler
compose up -d --wait --no-deps web
# a changed Caddyfile (mounted, so not seen by compose)
compose exec -T web caddy reload --config /etc/caddy/Caddyfile

# --- keep the last three releases (only the tags this script made) ----------

echo "$release" >> .releases
head -n -3 .releases | while read -r tag; do
    docker image rm "xgproyect-app:$tag" >/dev/null 2>&1 || true
done
tail -n 3 .releases > .releases.new && mv .releases.new .releases

port="$(setting HTTPS_PORT)"
say "Release $release is serving at https://$(setting XGP_DOMAIN)$([ "${port:-443}" = 443 ] || echo ":$port")"
