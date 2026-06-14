#!/bin/sh
# =============================================================================
# Novarr container entrypoint
# =============================================================================
# Ensures an APP_KEY exists and is STABLE across restarts, then execs the
# container's command (Octane, the scheduler, an artisan one-shot, …).
#
# This is intentionally additive and a no-op for deployments that already
# provide APP_KEY via the environment / .env — in that case the block below is
# skipped entirely and we just `exec "$@"`. It only generates a key for the
# zero-config one-click stack, persisting it to the (volume-backed) storage
# dir so every service in the stack shares the same key on every boot.
# =============================================================================
set -e

KEY_FILE=/var/www/html/storage/app/.appkey

if [ -z "${APP_KEY}" ]; then
    if [ -f "${KEY_FILE}" ]; then
        APP_KEY="$(cat "${KEY_FILE}")"
    else
        APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
        mkdir -p "$(dirname "${KEY_FILE}")"
        printf '%s' "${APP_KEY}" > "${KEY_FILE}"
        echo "Generated a new APP_KEY and stored it at ${KEY_FILE}"
    fi
    export APP_KEY
fi

exec "$@"
