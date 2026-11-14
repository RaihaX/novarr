#!/usr/bin/env bash
# Prod deploy script — run on the prod box (root@192.168.1.34) as /var/www/novarr/deploy.sh.
# Usually invoked from a dev machine via the repo-local alias: `git deploy`.
#
#   deploy.sh                 deploy the latest master
#   deploy.sh --rollback      undo the last deploy (asks first)
#   deploy.sh --rollback --yes
#
# Safe to re-run: every step is idempotent. Order matters:
#   DB backup (abort if it fails; site untouched) -> record rollback point ->
#   maintenance mode -> pull (fast-forward only) -> deps/assets ->
#   config:clear -> migrate -> clear + rebuild caches -> queue:restart ->
#   ownership -> php-fpm reload -> up -> health probe.
# New code never serves against the old schema: everything from the pull to
# the cache rebuild happens while the app is down.
#
# Rollback: before pulling, the current commit and this run's DB dump are
# written to $PENDING_STATE_FILE, promoted to $STATE_FILE once the pull has
# actually moved HEAD (a no-op deploy, or one that fails at the pull, keeps the
# previous rollback point). If any later step fails, the exit trap prints the
# exact commands to go back; `--rollback` runs them (code, dependencies,
# assets, database, caches) from that state file.
set -euo pipefail

# bash reads its script lazily from the open file, and `git pull` below
# rewrites deploy.sh — so run from a private copy, or a deploy could execute
# a mix of the old and new script.
if [[ "${NOVARR_DEPLOY_COPY:-}" != 1 ]]; then
    tmp="$(mktemp)"
    cp "${BASH_SOURCE[0]}" "$tmp"
    chmod +x "$tmp"
    NOVARR_DEPLOY_COPY=1 exec bash "$tmp" "$@"
fi
SCRIPT_COPY="${BASH_SOURCE[0]}"

APP_DIR=/var/www/novarr
BACKUP_DIR="$APP_DIR/storage/backups"
KEEP_BACKUPS=5
# Rollback point of the last run: previous commit + the dump taken before it.
STATE_FILE="$BACKUP_DIR/last-deploy.state"
# This run's rollback point until the pull has changed HEAD.
PENDING_STATE_FILE="$STATE_FILE.pending"
IN_MAINTENANCE=0
MAINTENANCE_SECRET=""
# Set once this run starts changing code (deploy) or begins undoing (rollback);
# from then on a failure prints the rollback recipe.
CHANGED=0
MODE=deploy
MODE_LABEL=Deploy
ASSUME_YES=0
PREV_COMMIT=""
LAST_BACKUP=""

usage() {
    cat <<'USAGE'
Usage: deploy.sh [--rollback [--yes]]

  (no option)   Back up the database, then deploy the latest master.
  --rollback    Return to the commit and database dump recorded by the last
                deploy (storage/backups/last-deploy.state). Restores the
                database: anything written since that dump is lost.
  --yes, -y     Do not ask for confirmation (--rollback only).
  --help, -h    Show this help.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --rollback) MODE=rollback; MODE_LABEL=Rollback ;;
        --yes|-y) ASSUME_YES=1 ;;
        --help|-h) usage; rm -f -- "$SCRIPT_COPY"; exit 0 ;;
        *) echo "!! Unknown option: $1" >&2; usage >&2; rm -f -- "$SCRIPT_COPY"; exit 2 ;;
    esac
    shift
done

# The MySQL/MariaDB client used to load a dump back in.
db_client() {
    command -v mariadb || command -v mysql || echo mysql
}

# Prints the copy-pasteable recipe that returns the app to $1 (commit) with
# the database from $2 (gzipped dump; empty = database untouched).
print_rollback_recipe() {
    local commit="$1" backup="$2" units
    {
        echo "!!"
        echo "!! To roll back to $commit, run:"
        echo "!!     $APP_DIR/deploy.sh --rollback"
        echo "!! or by hand:"
        echo "    cd $APP_DIR"
        [[ $IN_MAINTENANCE -eq 1 ]] || echo "    php artisan down --retry=15"
        echo "    git reset --hard $commit"
        echo "    composer install --no-dev --no-interaction"
        echo "    yarn install --frozen-lockfile"
        echo "    yarn build"
        echo "    php artisan config:clear"
        if [[ -n "$backup" ]]; then
            case "${DB_CONNECTION:-}" in
                sqlite)
                    echo "    gunzip -c '$backup' > '${DB_DATABASE:-$APP_DIR/database/database.sqlite}'"
                    ;;
                *)
                    echo "    php artisan db:wipe --force"
                    echo "    gunzip -c '$backup' | $(db_client) -h '${DB_HOST:-127.0.0.1}' -P '${DB_PORT:-3306}' -u '${DB_USERNAME:-}' -p '${DB_DATABASE:-}'   # prompts for DB_PASSWORD"
                    ;;
            esac
        else
            echo "    # (database untouched: the failure came before migrations)"
        fi
        echo "    php artisan route:clear && php artisan view:clear && php artisan event:clear"
        echo "    php artisan config:cache && php artisan route:cache && php artisan view:cache"
        echo "    php artisan queue:restart"
        echo "    chown -R www-data:www-data storage bootstrap/cache"
        units="$(fpm_units)"
        [[ -n "$units" ]] && echo "    systemctl reload $units"
        echo "    php artisan up"
    } >&2
}

on_exit() {
    local status=$?
    rm -f -- "$SCRIPT_COPY"
    # A failure between recording the rollback point and promoting it (e.g.
    # at the pull): promote only if HEAD moved, else drop it.
    [[ $MODE == deploy ]] && settle_pending_state
    [[ $status -eq 0 ]] && return

    if [[ $IN_MAINTENANCE -eq 1 ]]; then
        echo "!! $MODE_LABEL failed while the app is in maintenance mode." >&2
        echo "!! Bypass to inspect: /$MAINTENANCE_SECRET — run 'php artisan up' once fixed" >&2
        echo "!! (the pre-deploy DB backups are in $BACKUP_DIR)." >&2
    elif [[ $CHANGED -eq 1 ]]; then
        echo "!! $MODE_LABEL failed after the app came back up: it is serving $(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo 'the new code')." >&2
    fi

    if [[ $CHANGED -eq 1 && -n "$PREV_COMMIT" ]]; then
        if [[ $MODE == rollback ]]; then
            echo "!! Rollback stopped part-way; finish these steps by hand:" >&2
            print_rollback_recipe "$PREV_COMMIT" "$LAST_BACKUP"
        else
            # The database only needs restoring once migrate has run.
            print_rollback_recipe "$PREV_COMMIT" "${ROLLBACK_BACKUP:-}"
        fi
    fi
}
trap on_exit EXIT

cd "$APP_DIR"

# DB_* and APP_URL exactly as Laravel sees them: parsed by phpdotenv itself,
# so quoting, escapes (\" \\) and ${VAR} references behave identically. Needs
# a working vendor/ (deploy: the previous deploy's, as this runs before the
# pull). Sets plain shell variables DB_CONNECTION, DB_HOST, DB_PORT,
# DB_DATABASE, DB_USERNAME, DB_PASSWORD and APP_URL.
load_db_env() {
    local out key i=0
    local keys=(DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD APP_URL)
    out="$(mktemp)"
    if ! php -r '
        require "vendor/autoload.php";
        $v = Dotenv\Dotenv::createArrayBacked(getcwd())->load();
        foreach (array_slice($argv, 1) as $k) { echo $v[$k] ?? "", "\0"; }
    ' -- "${keys[@]}" > "$out"; then
        rm -f "$out"
        return 1
    fi
    while IFS= read -r -d '' value; do
        printf -v "${keys[$i]}" '%s' "$value"
        i=$((i + 1))
    done < "$out"
    rm -f "$out"
    [[ $i -eq ${#keys[@]} ]]
}

# Writes a private MySQL option file with the DB credentials and prints its
# path. Credentials go through this file, never argv (which any local user
# could read from the process list). Option-file values are double-quoted:
# escape \ and " inside.
write_db_defaults() {
    local defaults password
    password="${DB_PASSWORD}"
    password="${password//\\/\\\\}"
    password="${password//\"/\\\"}"
    defaults="$(mktemp)"
    chmod 600 "$defaults"
    {
        echo "[client]"
        echo "host=${DB_HOST:-127.0.0.1}"
        echo "port=${DB_PORT:-3306}"
        echo "user=${DB_USERNAME}"
        echo "password=\"$password\""
    } > "$defaults"
    echo "$defaults"
}

# Gzipped dump of the app database into $BACKUP_DIR, keeping the newest
# $KEEP_BACKUPS. Returns non-zero (and leaves no partial file) on any failure.
# Sets LAST_BACKUP to the file written.
backup_database() {
    local stamp target tmp dump_bin defaults
    stamp="$(date +%Y%m%d-%H%M)"
    target="$BACKUP_DIR/novarr-$stamp.sql.gz"
    tmp="$target.partial"

    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    case "${DB_CONNECTION}" in
        mysql|mariadb)
            dump_bin="$(command -v mysqldump || command -v mariadb-dump || true)"
            if [[ -z "$dump_bin" ]]; then
                echo "!! mysqldump / mariadb-dump not found (apt install mariadb-client)" >&2
                return 1
            fi

            defaults="$(write_db_defaults)"
            if ! (umask 077 && "$dump_bin" --defaults-extra-file="$defaults" \
                    --single-transaction --quick --routines --triggers --no-tablespaces \
                    "${DB_DATABASE}" | gzip -c > "$tmp"); then
                rm -f "$defaults" "$tmp"
                return 1
            fi
            rm -f "$defaults"
            ;;
        sqlite)
            local db_file="${DB_DATABASE:-$APP_DIR/database/database.sqlite}"
            if [[ ! -f "$db_file" ]]; then
                echo "!! SQLite database $db_file not found" >&2
                return 1
            fi
            if ! (umask 077 && gzip -c "$db_file" > "$tmp"); then
                rm -f "$tmp"
                return 1
            fi
            ;;
        *)
            echo "!! Don't know how to back up DB_CONNECTION='${DB_CONNECTION}'" >&2
            return 1
            ;;
    esac

    # An empty or corrupt archive is a failed backup.
    if [[ ! -s "$tmp" ]] || ! gzip -t "$tmp"; then
        rm -f "$tmp"
        return 1
    fi
    mv -f "$tmp" "$target"
    LAST_BACKUP="$target"
    echo "    Backup written: $target ($(du -h "$target" | cut -f1))"

    # Keep only the newest $KEEP_BACKUPS dumps.
    local old=() file
    while IFS= read -r file; do
        old+=("$file")
    done < <(ls -1t "$BACKUP_DIR"/novarr-*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)))
    for file in ${old[@]+"${old[@]}"}; do
        rm -f -- "$file"
    done
}

# Replaces the app database with a gzipped dump made by backup_database:
# every table is dropped first (db:wipe, using the app's own config) so
# tables a newer migration created do not linger.
restore_database() {
    local file="$1" client defaults
    if ! gzip -t "$file"; then
        echo "!! $file is not a readable gzip file" >&2
        return 1
    fi
    case "${DB_CONNECTION}" in
        mysql|mariadb)
            client="$(command -v mariadb || command -v mysql || true)"
            if [[ -z "$client" ]]; then
                echo "!! mariadb / mysql client not found (apt install mariadb-client)" >&2
                return 1
            fi
            php artisan db:wipe --force
            defaults="$(write_db_defaults)"
            if ! gunzip -c "$file" | "$client" --defaults-extra-file="$defaults" "${DB_DATABASE}"; then
                rm -f "$defaults"
                return 1
            fi
            rm -f "$defaults"
            ;;
        sqlite)
            local db_file="${DB_DATABASE:-$APP_DIR/database/database.sqlite}"
            gunzip -c "$file" > "$db_file.restoring"
            mv -f "$db_file.restoring" "$db_file"
            ;;
        *)
            echo "!! Don't know how to restore DB_CONNECTION='${DB_CONNECTION}'" >&2
            return 1
            ;;
    esac
}

# Active php-fpm units (php8.3-fpm, php-fpm, …), space separated; empty when
# there are none or no systemd. Never fails: in a container / LXC without
# systemd as PID 1, `systemctl list-units` exits non-zero, and under
# `set -euo pipefail` that would abort the deploy inside maintenance mode
# (and stop the exit trap's recipe before `php artisan up`).
fpm_units() {
    command -v systemctl >/dev/null 2>&1 || return 0
    systemctl list-units --type=service --state=active --plain --no-legend 'php*-fpm.service' 2>/dev/null \
        | awk '{print $1}' | xargs echo || true
}

# The rollback point: plain key=value lines, parsed (never sourced: storage/
# is www-data's, and this script runs as root).
# write_state writes this run's point to $PENDING_STATE_FILE (or the file
# given); settle_pending_state promotes it to $STATE_FILE only when HEAD has
# moved off PREV_COMMIT, so a run that changed no code keeps the real point.
write_state() {
    local target="${1:-$PENDING_STATE_FILE}"
    local tmp="$target.partial"
    (umask 077 && {
        echo "PREV_COMMIT=$PREV_COMMIT"
        echo "BACKUP_FILE=$LAST_BACKUP"
        echo "STARTED_AT=$(date '+%Y-%m-%d %H:%M:%S')"
    } > "$tmp")
    mv -f "$tmp" "$target"
}

# Returns 0 when the pending point was promoted, 1 when HEAD hadn't moved
# (pending file removed, $STATE_FILE untouched) or there was nothing pending.
settle_pending_state() {
    [[ -f "$PENDING_STATE_FILE" ]] || return 1
    local head
    head="$(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || true)"
    if [[ -n "$PREV_COMMIT" && -n "$head" && "$head" != "$PREV_COMMIT" ]]; then
        mv -f "$PENDING_STATE_FILE" "$STATE_FILE"
        return 0
    fi
    rm -f -- "$PENDING_STATE_FILE"
    return 1
}

read_state() {
    local key value
    PREV_COMMIT="" LAST_BACKUP=""
    while IFS='=' read -r key value; do
        case "$key" in
            PREV_COMMIT) PREV_COMMIT="$value" ;;
            BACKUP_FILE) LAST_BACKUP="$value" ;;
            STARTED_AT) STATE_STARTED_AT="$value" ;;
        esac
    done < "$STATE_FILE"

    if [[ ! "$PREV_COMMIT" =~ ^[0-9a-f]{40}$ ]] || ! git cat-file -e "$PREV_COMMIT^{commit}" 2>/dev/null; then
        echo "!! $STATE_FILE has no usable PREV_COMMIT" >&2
        return 1
    fi
    if [[ "${LAST_BACKUP%/*}" != "$BACKUP_DIR" || "${LAST_BACKUP##*/}" != novarr-*.sql.gz || ! -f "$LAST_BACKUP" ]]; then
        echo "!! The backup named in $STATE_FILE ('$LAST_BACKUP') is missing" >&2
        return 1
    fi
}

enter_maintenance() {
    # The secret lets the operator still open the site (https://<host>/<secret>).
    MAINTENANCE_SECRET="${NOVARR_MAINTENANCE_SECRET:-$(php -r 'echo bin2hex(random_bytes(16));')}"
    echo "==> Entering maintenance mode (bypass: /$MAINTENANCE_SECRET)"
    php artisan down --retry=15 --secret="$MAINTENANCE_SECRET"
    IN_MAINTENANCE=1
}

install_dependencies() {
    echo "==> Installing PHP dependencies"
    composer install --no-dev --no-interaction

    echo "==> Installing JS dependencies + building assets"
    yarn install --frozen-lockfile
    yarn build
}

# Prod runs with cached config/routes/views/events — these are mandatory after
# every pull, or code changes silently don't apply. Cleared one by one rather
# than optimize:clear, which would also flush the application cache and with
# it the scheduler's withoutOverlapping locks (a running sweep could start a
# second copy).
rebuild_caches() {
    echo "==> Rebuilding caches"
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan event:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
}

# Probe /api/health (503 when the database, cache, storage or Redis is down).
# It goes to this box: the APP_URL host is pinned to 127.0.0.1, so nginx gets
# the right Host/SNI without depending on outside DNS. NOVARR_HEALTH_URL
# overrides the URL (requested as is).
probe_health() {
    local url resolve=() rest hostport host port proto code body attempt
    if ! command -v curl >/dev/null 2>&1; then
        echo "!! curl not found — skipping the health probe" >&2
        return 0
    fi
    if [[ -n "${NOVARR_HEALTH_URL:-}" ]]; then
        url="$NOVARR_HEALTH_URL"
    else
        url="${APP_URL:-http://localhost}"
        url="${url%/}/api/health"
        proto="${url%%://*}"
        rest="${url#*://}"
        hostport="${rest%%/*}"
        host="${hostport%%:*}"
        if [[ "$hostport" == *:* ]]; then
            port="${hostport##*:}"
        elif [[ "$proto" == https ]]; then
            port=443
        else
            port=80
        fi
        resolve=(--resolve "$host:$port:127.0.0.1")
    fi

    echo "==> Probing $url"
    body="$(mktemp)"
    for attempt in 1 2 3 4 5; do
        code="$(curl -sS -o "$body" -w '%{http_code}' --max-time 15 \
            ${resolve[@]+"${resolve[@]}"} "$url" 2>/dev/null || true)"
        if [[ "$code" == 200 ]]; then
            echo "    Healthy (HTTP 200)"
            rm -f "$body"
            return 0
        fi
        sleep 3
    done
    # APP_URL is often an HTTPS origin terminated in front of this box (a
    # reverse proxy, Tailscale Serve), which 127.0.0.1 does not answer on.
    # A connection failure there says nothing about the app, so ask the local
    # web server directly before calling it unhealthy.
    if [[ -z "${NOVARR_HEALTH_URL:-}" && ( -z "$code" || "$code" == 000 ) ]]; then
        echo "    $url is not reachable from this host; trying http://127.0.0.1/api/health"
        code="$(curl -sS -o "$body" -w '%{http_code}' --max-time 15 \
            http://127.0.0.1/api/health 2>/dev/null || true)"
        if [[ "$code" == 200 ]]; then
            echo "    Healthy (HTTP 200)"
            rm -f "$body"
            return 0
        fi
        url="http://127.0.0.1/api/health"
    fi
    echo "!! $url answered HTTP ${code:-000} after 5 attempts, not 200:" >&2
    head -c 600 "$body" >&2 || true
    echo >&2
    echo "!! The app is up but reports unhealthy. Not rolling back automatically;" >&2
    echo "!! check storage/logs/laravel.log, then fix forward or roll back." >&2
    rm -f "$body"
    return 1
}

# Shared tail of a deploy and a rollback, from the rebuilt caches onwards.
finish_release() {
    local units

    echo "==> Restarting queue worker"
    php artisan queue:restart

    # Deploys run as root; the queue worker and PHP-FPM run as www-data.
    # Anything root leaves behind in storage/ (epub runs, cover downloads,
    # caches, backups) breaks later www-data writes with Permission denied —
    # normalize every deploy, before the app serves again.
    echo "==> Normalizing storage ownership"
    chown -R www-data:www-data storage bootstrap/cache

    # opcache may still hold the previous code (validate_timestamps / revalidate_freq).
    units="$(fpm_units)"
    if [[ -n "$units" ]]; then
        echo "==> Reloading $units"
        # shellcheck disable=SC2086 # word-splitting the unit list is intended
        systemctl reload $units || echo "!! php-fpm reload failed — the health probe will tell" >&2
    fi

    echo "==> Leaving maintenance mode"
    php artisan up
    IN_MAINTENANCE=0

    probe_health
}

if [[ $MODE == rollback ]]; then
    if [[ ! -f "$STATE_FILE" ]]; then
        echo "!! No rollback point: $STATE_FILE does not exist (it is written by a deploy, and consumed by a rollback)." >&2
        exit 1
    fi
    STATE_STARTED_AT=""
    read_state
    # For the confirmation text and the recipe if this stops part-way.
    load_db_env || true

    echo "==> Rollback point (deploy started ${STATE_STARTED_AT:-at an unknown time}):"
    echo "    code:     $(git log -1 --format='%h %s' "$PREV_COMMIT")  (now at $(git rev-parse --short HEAD))"
    echo "    database: $LAST_BACKUP"
    echo "    The database is REPLACED by that dump: everything written since is lost."
    if [[ $ASSUME_YES -ne 1 ]]; then
        if [[ ! -t 0 ]]; then
            echo "!! Not a terminal: pass --yes to roll back without asking." >&2
            exit 1
        fi
        read -r -p "Type 'rollback' to continue: " answer
        if [[ "$answer" != rollback ]]; then
            echo "Cancelled. Nothing was changed."
            exit 1
        fi
    fi

    enter_maintenance
    CHANGED=1

    echo "==> Resetting code to $(git rev-parse --short "$PREV_COMMIT")"
    git reset --hard "$PREV_COMMIT"

    install_dependencies

    php artisan config:clear
    # DB_* from the restored .env/vendor (the .env itself is untracked).
    if ! load_db_env; then
        echo "!! Could not read DB_* from .env" >&2
        exit 1
    fi
    echo "==> Restoring the database from $LAST_BACKUP"
    restore_database "$LAST_BACKUP"

    rebuild_caches
    finish_release

    # Consumed: a second --rollback must not restore this dump again.
    mv -f "$STATE_FILE" "$STATE_FILE.rolled-back"
    CHANGED=0
    echo "==> Rolled back to $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
    exit 0
fi

echo "==> Backing up the database"
if ! load_db_env; then
    echo "!! Could not read DB_* from .env — refusing to deploy. Nothing was changed." >&2
    exit 1
fi
if ! backup_database; then
    echo "!! Database backup failed — refusing to deploy. Nothing was changed." >&2
    exit 1
fi

# Rollback point, recorded before anything changes (pending until the pull
# moves HEAD; the exit trap's recipe uses these in-memory values either way).
PREV_COMMIT="$(git rev-parse HEAD)"
write_state

# Maintenance mode for the whole code + schema change.
enter_maintenance

CHANGED=1
echo "==> Pulling latest code"
git pull --ff-only
if settle_pending_state; then
    NEW_ROLLBACK_POINT=1
else
    NEW_ROLLBACK_POINT=0
    echo "==> Already at $(git rev-parse --short HEAD): no new code, the previous rollback point is kept"
fi

install_dependencies

# Drop the cached config first so migrate reads the freshly pulled config +
# .env rather than the previous deploy's cache.
echo "==> Clearing cached config"
php artisan config:clear

echo "==> Running migrations"
# From here the schema may have changed: a rollback must restore the dump.
ROLLBACK_BACKUP="$LAST_BACKUP"
php artisan migrate --force

rebuild_caches
finish_release

CHANGED=0
echo "==> Deployed $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
if [[ $NEW_ROLLBACK_POINT -eq 1 ]]; then
    echo "    Undo with: $APP_DIR/deploy.sh --rollback (back to $(git rev-parse --short "$PREV_COMMIT"))"
fi
