#!/usr/bin/env bash
# Prod deploy script — run on the prod box (root@192.168.1.34) as /var/www/novarr/deploy.sh.
# Usually invoked from a dev machine via the repo-local alias: `git deploy`.
#
# Safe to re-run: every step is idempotent. Order matters:
#   DB backup (abort if it fails; site untouched) -> maintenance mode ->
#   pull (fast-forward only) -> deps/assets -> config:clear -> migrate ->
#   clear + rebuild caches -> up -> queue:restart -> ownership.
# New code never serves against the old schema: everything from the pull to
# the cache rebuild happens while the app is down.
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
IN_MAINTENANCE=0
MAINTENANCE_SECRET=""

on_exit() {
    local status=$?
    rm -f -- "$SCRIPT_COPY"
    if [[ $status -ne 0 && $IN_MAINTENANCE -eq 1 ]]; then
        echo "!! Deploy failed while the app is in maintenance mode." >&2
        echo "!! Bypass to inspect: /$MAINTENANCE_SECRET — run 'php artisan up' once fixed" >&2
        echo "!! (the pre-deploy DB backup is in $BACKUP_DIR)." >&2
    fi
}
trap on_exit EXIT

cd "$APP_DIR"

# DB_* exactly as Laravel sees them: parsed by phpdotenv itself, so quoting,
# escapes (\" \\) and ${VAR} references behave identically. vendor/ is from
# the previous deploy (this runs before the pull). Sets plain shell variables
# DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.
load_db_env() {
    local out key i=0
    local keys=(DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD)
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

# Gzipped dump of the app database into $BACKUP_DIR, keeping the newest
# $KEEP_BACKUPS. Returns non-zero (and leaves no partial file) on any failure.
backup_database() {
    local stamp target tmp dump_bin defaults password
    stamp="$(date +%Y%m%d-%H%M)"
    target="$BACKUP_DIR/novarr-$stamp.sql.gz"
    tmp="$target.partial"

    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    case "${DB_CONNECTION}" in
        mysql|mariadb)
            dump_bin="$(command -v mysqldump || command -v mariadb-dump || true)"
            if [[ -z "$dump_bin" ]]; then
                echo "!! mysqldump / mariadb-dump not found" >&2
                return 1
            fi

            # Credentials go through a private defaults file, never argv
            # (which any local user could read from the process list).
            # Option-file values are double-quoted: escape \ and " inside.
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

echo "==> Backing up the database"
if ! load_db_env; then
    echo "!! Could not read DB_* from .env — refusing to deploy. Nothing was changed." >&2
    exit 1
fi
if ! backup_database; then
    echo "!! Database backup failed — refusing to deploy. Nothing was changed." >&2
    exit 1
fi

# Maintenance mode for the whole code + schema change. The secret lets the
# operator still open the site (https://<host>/<secret>) to check it.
MAINTENANCE_SECRET="${NOVARR_MAINTENANCE_SECRET:-$(php -r 'echo bin2hex(random_bytes(16));')}"
echo "==> Entering maintenance mode (bypass: /$MAINTENANCE_SECRET)"
php artisan down --retry=15 --secret="$MAINTENANCE_SECRET"
IN_MAINTENANCE=1

echo "==> Pulling latest code"
git pull --ff-only

echo "==> Installing PHP dependencies"
composer install --no-dev --no-interaction

echo "==> Installing JS dependencies + building assets"
yarn install --frozen-lockfile
yarn build

# Drop the cached config first so migrate reads the freshly pulled config +
# .env rather than the previous deploy's cache.
echo "==> Clearing cached config"
php artisan config:clear

echo "==> Running migrations"
php artisan migrate --force

# Prod runs with cached config/routes/views/events — these are mandatory after
# every pull, or code changes silently don't apply. Cleared one by one rather
# than optimize:clear, which would also flush the application cache and with
# it the scheduler's withoutOverlapping locks (a running sweep could start a
# second copy).
echo "==> Rebuilding caches"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Leaving maintenance mode"
php artisan up
IN_MAINTENANCE=0

echo "==> Restarting queue worker"
php artisan queue:restart

# Deploys run as root; the queue worker and PHP-FPM run as www-data. Anything
# root leaves behind in storage/ (epub runs, cover downloads, caches, backups)
# breaks later www-data writes with Permission denied — normalize every deploy.
echo "==> Normalizing storage ownership"
chown -R www-data:www-data storage bootstrap/cache

echo "==> Deployed $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
