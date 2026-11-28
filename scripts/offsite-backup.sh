#!/bin/bash
# Novarr's nightly offsite backup to the Hetzner Storage Box (restic, encrypted client-side).
# Installed on the Novarr LXC (CT 109) as /usr/local/bin/novarr-offsite-backup, run by
# novarr-offsite-backup.timer. Restores: readme.md › Backup and restore › Offsite (Hetzner, restic).
#
# What goes offsite (only what changed since last night is uploaded):
#   - the database, as a consistent mariadb-dump (NOT /mnt/manga/mysql: copying a live datadir
#     gives a torn, unrestorable copy). Written UNCOMPRESSED so restic can dedup night to night.
#   - /mnt/manga: manga + comic pages, audiobooks, books (the library itself)
#   - /var/www/novarr/storage: covers, uploads, EPUB builds, the Android APKs
#   - /var/www/novarr/.env: keys and passwords (the repository is encrypted)
# Left out: Novarr's own local copies (/mnt/manga/backups, storage/backups: offsite is restic's job),
# caches, logs, the CBZ cache - all rebuildable.
#
# Repository  sftp:hetzner-backup:novarr  (ssh alias in /root/.ssh/config: u732311, port 23)
# Passphrase  /root/.restic-hetzner.pass (also in the owner's password manager)

set -uo pipefail

export RESTIC_REPOSITORY="sftp:hetzner-backup:novarr"
export RESTIC_PASSWORD_FILE="/root/.restic-hetzner.pass"
export RESTIC_CACHE_DIR="/root/.cache/restic"
APP=/var/www/novarr
DUMP_DIR=/mnt/manga/.offsite-dump
LOG=/var/log/novarr-offsite-backup.log
METRICS_DIR=/var/lib/prometheus/node-exporter
START=$(date +%s)

log() { echo "$(date -Is) $*" | tee -a "$LOG"; }

# Failure email to the owner through Novarr's own mailer (the address is never in this file).
fail() {
  log "FAILED: $1"
  sudo -u www-data HOME=/tmp php "$APP/artisan" tinker --execute='
    $owner = App\User::find(App\Support\CurrentReader::ownerId());
    if ($owner && $owner->email) {
        Illuminate\Support\Facades\Mail::raw(
            "Novarr'"'"'s offsite backup to Hetzner failed: '"${1//\"/}"'\n\nLog: '"$LOG"' on the Novarr server.",
            fn ($m) => $m->to($owner->email)->subject("Novarr backup failed")
        );
    }' >/dev/null 2>&1 || true
  write_metrics 0
  exit 1
}

write_metrics() {
  [ -d "$METRICS_DIR" ] || return 0
  local out="$METRICS_DIR/novarr_offsite_backup.prom" tmp
  tmp=$(mktemp "$out.XXXXXX")
  {
    echo "novarr_offsite_backup_last_run_timestamp_seconds $(date +%s)"
    if [ "$1" = 1 ]; then echo "novarr_offsite_backup_last_success_timestamp_seconds $(date +%s)"
    elif [ -f "$out" ]; then grep '^novarr_offsite_backup_last_success_timestamp_seconds ' "$out" || true; fi
    echo "novarr_offsite_backup_duration_seconds $(( $(date +%s) - START ))"
  } > "$tmp" && chmod 644 "$tmp" && mv "$tmp" "$out"
}

[ -s "$RESTIC_PASSWORD_FILE" ] || fail "the restic passphrase file is missing"
restic cat config >/dev/null 2>>"$LOG" || fail "the Hetzner repository could not be opened"
restic unlock >>"$LOG" 2>&1 || true
log "=== start ==="

# ---- database ---------------------------------------------------------------
mkdir -p "$DUMP_DIR"; chmod 700 "$DUMP_DIR"
DB=$(sed -n 's/^DB_DATABASE=//p' "$APP/.env" | tr -d '"')
DB=${DB:-novarr}
if ! mariadb-dump --single-transaction --quick --routines --triggers --events \
       --skip-dump-date "$DB" > "$DUMP_DIR/$DB.sql.tmp" 2>>"$LOG"; then
  rm -f "$DUMP_DIR/$DB.sql.tmp"; fail "the database dump failed"
fi
mv "$DUMP_DIR/$DB.sql.tmp" "$DUMP_DIR/$DB.sql"
log "database dumped ($(du -h "$DUMP_DIR/$DB.sql" | cut -f1))"

# ---- files ------------------------------------------------------------------
restic backup --host novarr --tag novarr --compression auto -q \
  --exclude /mnt/manga/mysql --exclude /mnt/manga/backups \
  --exclude "$APP/storage/backups" --exclude "$APP/storage/framework" --exclude "$APP/storage/logs" \
  --exclude "$APP/storage/app/backup-temp" --exclude "$APP/storage/app/cbz-cache" \
  --exclude "$APP/storage/app/private/Novarr" \
  --exclude "$APP/storage/backup-webp-*" --exclude '.DS_Store' \
  /mnt/manga "$APP/storage" "$APP/.env" >>"$LOG" 2>&1 \
  || fail "restic backup failed"
log "backed up"

# ---- retention: 7 daily, 4 weekly, 6 monthly; prune on Sundays ------------------
restic forget --host novarr --keep-daily 7 --keep-weekly 4 --keep-monthly 6 >>"$LOG" 2>&1 || log "forget failed"
if [ "$(date +%u)" = 7 ]; then
  restic prune --max-unused 10% >>"$LOG" 2>&1 || log "prune failed"
  restic check --read-data-subset=2% >>"$LOG" 2>&1 || fail "the weekly integrity check failed"
  log "pruned and checked"
fi

write_metrics 1
log "=== done in $(( ($(date +%s) - START) / 60 ))m ==="
