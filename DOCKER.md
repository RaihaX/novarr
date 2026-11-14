# Novarr Docker Deployment Guide

Novarr ships one Docker image (`ghcr.io/raihax/novarr`, built by CI from `master`) and three compose stacks. Pick one:

| Stack | File | For |
|---|---|---|
| **One-click** | `docker-compose.oneclick.yml` | Most installs. Pulls the prebuilt image; no clone, no build. |
| **One-click on Tailscale** | `docker-compose.tailscale.yml` | Same, plus a Tailscale sidecar so Novarr joins your tailnet with HTTPS. |
| **Clone and run** | `docker-compose.yml` (+ `Makefile`) | Building the image from a checkout: `git clone`, `docker compose up -d --build`. Same services as one-click; also builds on ARM. |

Not using Docker at all? The bare-metal path is `deploy.sh` — see the readme's [Deployment](readme.md#deployment) section. Unraid specifics are in [UNRAID_DEPLOYMENT.md](UNRAID_DEPLOYMENT.md).

## The image

- **PHP 8.3 + Laravel Octane on RoadRunner**, listening on port `8000`. Octane serves PHP and the static assets (`public/`, built Vite bundle) itself.
- Runs as `www-data`. The entrypoint (`docker/docker-entrypoint.sh`) makes sure an `APP_KEY` exists: if none is set in the environment it creates one once and keeps it in `storage/app/.appkey`, so every service in a stack shares the same stable key. Keep that file — it is in the backups.
- Built-in `HEALTHCHECK` on `/api/ping` (liveness). The stacks override it per service: the app probes `/api/health`, the scheduler checks its heartbeat.
- Production only: no Composer, no Node, no dev dependencies. Run tests, linting, `composer` and `yarn` on a development machine, not inside the container.
- Includes `mariadb-client` (`mysqldump` / `mariadb-dump`) for `php artisan backup:run`, and the `tailscale` CLI for the in-app Tailscale panel.
- The published image is linux/amd64. A local build (`docker compose up -d --build`) fetches the RoadRunner binary for the build's own architecture, so it also works on arm64.

## One-click stack

```bash
curl -O https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.oneclick.yml
docker compose -f docker-compose.oneclick.yml up -d
```

Then open `http://<host>/`.

| Service | Purpose |
|---|---|
| `init` | One-shot: waits for MySQL, runs `migrate --force`, exits. Everything else waits for it. |
| `app` | Octane on port 80 → 8000. Healthcheck: `/api/health`. |
| `scheduler` | `schedule:work`: the cron tasks (TOC refresh, chapter downloads, summary email, backups) and, every minute, a `queue:work --stop-when-empty` that drains the command queue. Healthcheck: the scheduler heartbeat. |
| `mysql` | MySQL 8.0. Port not published. |
| `redis` | Cache and sessions. Port not published. |
| `flaresolverr` | Solves Cloudflare challenges for the scrapers. |

**Configuration** lives in the `x-app-env` block at the top of the file (`APP_URL`, `APP_TIMEZONE`, mail, …). The two database passwords can be set without editing the file, from the environment or an `.env` file next to it:

```env
DB_PASSWORD=change-me
MYSQL_ROOT_PASSWORD=change-me-too
```

They default to `novarr_secret` / `root_secret` (fine while the DB port stays unpublished). MySQL only applies them when its volume is first created: on an existing install, change the password inside MySQL first, then here.

**Update:**

```bash
docker compose -f docker-compose.oneclick.yml pull
docker compose -f docker-compose.oneclick.yml up -d
```

`init` runs the new migrations before the app and scheduler restart. Take a backup first (`docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:run`).

## One-click on Tailscale

```bash
curl -O https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.tailscale.yml
TS_AUTHKEY=tskey-auth-xxxx docker compose -f docker-compose.tailscale.yml up -d
```

Same services as the one-click stack plus a `tailscale` sidecar (userspace mode, works in an unprivileged LXC). The app shares the sidecar's network namespace, so its healthcheck still probes `localhost:8000`, and it runs as root so the in-app panel can drive Serve/Funnel. Turn on HTTPS from **Settings → Tailscale**, then set `APP_URL` to the `https://<node>.<tailnet>.ts.net` origin. Passwords, updates and backups work exactly as above (use `-f docker-compose.tailscale.yml`).

## Clone-and-run stack (`docker-compose.yml`)

```bash
git clone https://github.com/RaihaX/novarr.git && cd novarr
docker compose up -d --build        # or: make deploy
```

Then open `http://localhost/`. It runs the same services as the one-click stack, with the image (`novarr:local`) built from the checkout:

| Service | Purpose |
|---|---|
| `init` | One-shot `migrate --force`; the app and scheduler wait for it. |
| `app` | Octane on port 8000, published on `NOVARR_PORT` (default 80). Healthcheck: `/api/health`. |
| `scheduler` | `schedule:work`: scheduled scrapes, email, backups, and the command queue. Healthcheck: the scheduler heartbeat. |
| `mysql`, `redis` | Database; cache and sessions. Neither is published on the host. |
| `flaresolverr` | Cloudflare solver, pre-wired through `FLARESOLVERR_URL`. |

Nothing has to be configured. The entrypoint creates the `APP_KEY` on first start and keeps it in the `storage` volume. Optional settings are read from the shell or a `.env` file next to the compose file: `NOVARR_PORT`, `APP_URL`, `APP_TIMEZONE`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `MAIL_MAILER`, `RESEND_KEY`, `MAIL_FROM_ADDRESS`, `NOTIFICATION_WEBHOOK_URL`. Anything else can be added under `x-app-env` in the compose file.

**Update:** `git pull --ff-only && make backup && docker compose up -d --build`.

Common `make` targets (`make help` lists them all):

| Command | Description |
|---|---|
| `make build` | Build `novarr:local` |
| `make deploy` | Build and start or recreate the stack; `init` migrates |
| `make up` / `make down` / `make restart` | Lifecycle |
| `make logs` / `make logs-app` / `make logs-scheduler` | Tail logs |
| `make shell` / `make tinker` / `make db-shell` | Shells (the DB shell reads the password from the mysql container's env, not argv) |
| `make backup` / `make backup-list` | `php artisan backup:run` / `backup:list` in the app container |
| `make restore BACKUP=dump.sql` | Load a plain SQL dump into MySQL |
| `make health` / `make smoke-test` | Probe the health endpoints / run `docker/scripts/smoke-test.sh` |
| `make queue-health` / `make queue-failed` / `make queue-retry` | Queue tools |

**Development:** `make dev` adds `docker-compose.dev.yml`, which bind-mounts the working tree into the app and scheduler. The image has no Composer or Node, so run `composer install && yarn install && yarn build` on the host first. Octane runs with `--max-requests=1` there, so code changes show up on the next request.

`docker/nginx/` is a reference configuration for running nginx in front of Novarr (caching rules for `/build/assets`, `/storage`, `sw.js`); no stack here uses it.

## Health checks and monitoring

| Endpoint | Checks | Response |
|---|---|---|
| `/api/ping` | The PHP server answers | `pong` |
| `/api/health` | Database, cache, storage, and Redis when anything uses it | JSON, HTTP 503 when any is unhealthy |
| `/api/health/db` | Database only | JSON |
| `/api/health/cache` | Cache (and Redis) only | JSON |
| `/health` | Human page: scheduler heartbeat, queue depth, failed jobs | HTML |

Responses carry only a status and a latency per service — failure details go to the log:

```json
{
  "status": "healthy",
  "timestamp": "2026-11-14T10:30:00+00:00",
  "app": "Novarr",
  "version": "1.0.0",
  "services": {
    "database": { "status": "healthy", "latency_ms": 2.5 },
    "cache":    { "status": "healthy", "latency_ms": 0.4 },
    "storage":  { "status": "healthy" },
    "redis":    { "status": "healthy", "latency_ms": 0.8 }
  }
}
```

The scheduler records a heartbeat in the cache every minute. Its container healthcheck is `php artisan queue:health-check --heartbeat=3 --max-age=525600`: unhealthy once the heartbeat is more than 3 minutes old (the very large `--max-age` keeps a long job backlog from counting against the scheduler).

```bash
docker compose ps                                        # health column per service
docker inspect --format='{{.State.Health.Status}}' novarr-app
```

## Logs

All stacks set `LOG_STACK=daily,stderr` and `LOG_DAILY_DAYS=14`: Laravel writes `storage/logs/laravel-YYYY-MM-DD.log` (14 days kept) **and** stderr, so errors also appear in `docker compose logs <service>`.

## Volumes and data

| Stack | Data | Where |
|---|---|---|
| One-click / Tailscale | `storage` volume → `/var/www/html/storage` | ePubs, covers, logs, `.appkey`, backups |
| | `mysql` volume | Database |
| | `redis` volume | Cache/session data (disposable) |
| | `tailscale-state` (Tailscale stack) | Node identity |
| Clone and run | Same three volumes (`novarr_storage`, `novarr_mysql`, `novarr_redis`) | As above |

```
storage/
├── app/
│   ├── ePub/, Novel/       # generated ePubs and their sources
│   ├── public/             # covers and other public files (served at /storage)
│   ├── private/<APP_NAME>/ # backup zips (default BACKUP_DISK=local)
│   ├── snapshots/          # failure snapshots (not backed up)
│   └── .appkey             # generated APP_KEY (one-click stacks)
├── framework/              # cache, sessions, compiled views
└── logs/
```

## Backups

`php artisan backup:run` (scheduled daily at 03:45, with `backup:clean` at 03:30) zips a dump of the database plus `storage/app` into `storage/app/private/<APP_NAME>/` — inside the `storage` volume for the one-click stacks. It keeps one backup a day for 7 days, one a week for 4 weeks and one a month for 3 months. Failures are logged and sent to the notification webhook (Settings).

```bash
docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:run    # now
docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:list
```

To keep them off the Docker disk, mount a directory from another disk over the backup folder in the `scheduler` service (and in `app`, if you run backups from there). The image's `www-data` is uid/gid 82:

```yaml
  scheduler:
    volumes:
      - storage:/var/www/html/storage
      - /mnt/backup/novarr:/var/www/html/storage/app/private/Novarr
```

```bash
mkdir -p /mnt/backup/novarr && chown 82:82 /mnt/backup/novarr
```

Restore steps for every stack are in the readme's [Backup and restore](readme.md#backup-and-restore) section.

## Moving an existing library to a new install

Take a backup on the old install, copy the zip to the new host, and follow the restore steps in [Backup and restore](readme.md#backup-and-restore). The schema is brought up to date by the next `migrate --force` (the `init` service in every stack).

## Troubleshooting

**Permission denied in storage.** The app writes as `www-data` (uid 82). For bind-mounted storage: `chown -R 82:82 storage bootstrap/cache`. For the volume: `docker compose exec --user root app chown -R www-data:www-data /var/www/html/storage`.

**Database connection failures.** `docker compose ps mysql` should show `healthy`; MySQL can take a minute on first start. Check that the passwords the app uses match the ones the MySQL volume was created with.

**Redis connection issues.** `docker compose exec redis redis-cli ping` should answer `PONG`.

**Port 80 already in use** (common on Unraid/NAS hosts): change the left side of the app's (or nginx's) port mapping, e.g. `"8080:8000"`.

**Octane/RoadRunner not starting.** `docker compose logs app`. Try it in the foreground: `docker compose exec app php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8001`.

**Scheduler unhealthy.** `docker compose exec scheduler php artisan queue:health-check` prints which check failed. A stale heartbeat means `schedule:work` stopped or cannot reach the cache.

**Covers or uploads 404.** `docker compose exec app php artisan storage:link --force` (the one-click stacks do this on every start).

## Security

1. Keep MySQL and Redis unpublished, as every stack here does.
2. Set real `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` before the first start if anything else shares the Docker host.
3. Put HTTPS in front: a reverse proxy, or Tailscale Serve. Novarr has no login — anything that can reach it can use it, so do not expose it to the internet without an authenticating proxy.
4. Update regularly: `docker compose pull && docker compose up -d`.
5. Back up `storage/app/.appkey` (the backups include it) and your `.env`, and keep both private.
