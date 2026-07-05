#!/usr/bin/env bash
# Prod deploy script — run on the prod box (root@192.168.1.34) as /var/www/novarr/deploy.sh.
# Usually invoked from a dev machine via the repo-local alias: `git deploy`.
set -euo pipefail

cd /var/www/novarr

echo "==> Pulling latest code"
git pull

echo "==> Installing PHP dependencies"
composer install --no-dev --no-interaction

echo "==> Installing JS dependencies + building assets"
yarn install --frozen-lockfile
yarn build

echo "==> Running migrations"
php artisan migrate --force

# Prod runs with cached config/routes/views — these are mandatory after every
# pull, or code changes silently don't apply.
echo "==> Rebuilding caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Restarting queue worker"
php artisan queue:restart

echo "==> Deployed $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
