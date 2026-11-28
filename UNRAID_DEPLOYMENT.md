# Novarr Unraid Deployment Guide

Novarr runs on Unraid as the **one-click compose stack**: a prebuilt image from GHCR, nothing to clone or build. This guide covers the Unraid-specific parts; [DOCKER.md](DOCKER.md) is the full reference for the stacks, health checks, logs and troubleshooting.

## Table of Contents

1. [What you get](#what-you-get)
2. [Before you start](#before-you-start)
3. [Install with Docker Compose Manager](#install-with-docker-compose-manager)
4. [Install over SSH](#install-over-ssh)
5. [Keeping data in appdata](#keeping-data-in-appdata)
6. [Reverse proxy and HTTPS](#reverse-proxy-and-https)
7. [Updating](#updating)
8. [Backups](#backups)
9. [Monitoring](#monitoring)
10. [Troubleshooting](#troubleshooting)
11. [Performance](#performance)
12. [Security](#security)
13. [Quick reference](#quick-reference)

## What you get

`docker-compose.oneclick.yml` runs:

| Container | Purpose |
|---|---|
| `init` | Runs database migrations, then exits |
| `app` | Novarr (Laravel Octane), port 80 on the host |
| `scheduler` | Cron tasks (TOC checks, chapter downloads, summary email, daily backups) and the command queue |
| `mysql` | Database (port not published) |
| `redis` | Cache and sessions (port not published) |
| `flaresolverr` | Cloudflare challenge solver for the scrapers |

Prefer Novarr on your tailnet with HTTPS and no reverse proxy? Use `docker-compose.tailscale.yml` the same way (it needs `TS_AUTHKEY`); see the readme's Tailscale section.

## Before you start

- Unraid 6.12 or later.
- **Docker Compose Manager** plugin (Community Applications → search "Docker Compose Manager"), or SSH access.
- Port 80 free on the host, or pick another (see [Troubleshooting](#port-80-is-taken)).
- Disk: a few GB for the image and database; the novel library (ePubs, covers) grows with use, and the daily backups add roughly the size of the library again.

## Install with Docker Compose Manager

1. **Docker → Compose → Add New Stack**, name it `novarr`.
2. **Edit Stack → Compose File**: paste the contents of [`docker-compose.oneclick.yml`](https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.oneclick.yml).
3. In the `x-app-env` block at the top, set `APP_URL` (e.g. `http://192.168.1.50` or your proxied `https://novarr.example.com`) and `APP_TIMEZONE` (match Unraid's).
4. **Edit Stack → Env File**: set the database passwords (they are only applied when the MySQL volume is first created):
   ```env
   DB_PASSWORD=change-me
   MYSQL_ROOT_PASSWORD=change-me-too
   ```
5. **Compose Up**. Open `http://<unraid-ip>/` once `app` shows healthy.

## Install over SSH

```bash
mkdir -p /mnt/user/appdata/novarr && cd /mnt/user/appdata/novarr
curl -O https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.oneclick.yml
printf 'DB_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > .env
chmod 600 .env
nano docker-compose.oneclick.yml        # APP_URL, APP_TIMEZONE
docker compose -f docker-compose.oneclick.yml up -d
```

## Keeping data in appdata

By default the stack keeps its data in named Docker volumes (inside Unraid's Docker image/directory). To keep the library and backups in appdata instead, replace the `storage:` named volume with a bind mount in the `init`, `app` and `scheduler` services:

```yaml
    volumes:
      - /mnt/user/appdata/novarr/storage:/var/www/html/storage
```

Create it owned by the image's `www-data` user (uid/gid 82) before the first start:

```bash
mkdir -p /mnt/user/appdata/novarr/storage && chown -R 82:82 /mnt/user/appdata/novarr/storage
```

The database stays in the `mysql` volume; it is captured by Novarr's own backups (below).

## Reverse proxy and HTTPS

HTTPS is needed to install Novarr as a PWA. Sign-in is **off by default** (everyone who can reach Novarr is the owner): turn it on in Settings → Users (or `php artisan novarr:auth on`) before anything outside your LAN can reach it, and see the readme's [Publishing on the internet with a Cloudflare Tunnel](readme.md#publishing-on-the-internet-with-a-cloudflare-tunnel) for a public address.

- **Nginx Proxy Manager:** new Proxy Host → your domain → forward to `<unraid-ip>` port `80` (or the port you mapped) → enable SSL. Then set `APP_URL` to the `https://` origin and recreate the stack.
- **SWAG:**
  ```nginx
  server {
      listen 443 ssl;
      server_name novarr.yourdomain.com;
      location / {
          proxy_pass http://<unraid-ip>:80;
          proxy_set_header Host $host;
          proxy_set_header X-Real-IP $remote_addr;
          proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
          proxy_set_header X-Forwarded-Proto $scheme;
      }
  }
  ```
- **Tailscale:** use `docker-compose.tailscale.yml` and turn on Serve from Settings → Tailscale.

## Updating

Back up, then pull and recreate (Compose Manager: **Update Stack**, which does the same):

```bash
cd /mnt/user/appdata/novarr
docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:run
docker compose -f docker-compose.oneclick.yml pull
docker compose -f docker-compose.oneclick.yml up -d
docker image prune -f
```

`init` applies new migrations before the app and scheduler start.

## Backups

The scheduler runs `php artisan backup:run` every day at 03:45 (and `backup:clean` at 03:30): a zip with a dump of the database plus `storage/app` (ePubs, covers, the generated `.appkey`), kept as one a day for 7 days, one a week for 4 weeks and one a month for 3 months. Zips land in `storage/app/private/Novarr/` — in appdata if you bind-mounted storage as above. Failures go to the log and to the notification webhook (Settings).

To put them on the array or another pool instead of the cache drive, mount a share over the backup folder in the `scheduler` service:

```yaml
  scheduler:
    volumes:
      - storage:/var/www/html/storage
      - /mnt/user/backups/novarr:/var/www/html/storage/app/private/Novarr
```

```bash
mkdir -p /mnt/user/backups/novarr && chown 82:82 /mnt/user/backups/novarr
```

Run one now / list them:

```bash
docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:run
docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:list
```

The **Appdata Backup** plugin can additionally capture `/mnt/user/appdata/novarr/` (stop the stack during its run for a consistent copy). To restore, or to move to a new Unraid server, follow the Docker steps in the readme's [Backup and restore](readme.md#backup-and-restore) section.

## Monitoring

- **Docker tab:** each container shows healthy/unhealthy. `app` probes `/api/health` (database, cache, storage); `scheduler` checks its once-a-minute heartbeat.
- **Logs:** Laravel logs to stderr as well as `storage/logs/laravel-YYYY-MM-DD.log` (14 days), so the container log icon shows application errors.
- **In the app:** the `/health` page shows the scheduler heartbeat, queue depth and failed jobs (with retry).
- **User Scripts** health check with an Unraid notification:
  ```bash
  #!/bin/bash
  if ! curl -fsS http://localhost/api/health > /dev/null; then
      /usr/local/emhttp/webGui/scripts/notify -s "Novarr" -d "Health check failed" -i "warning"
  fi
  ```

## Troubleshooting

#### Permission errors

The containers write as uid 82. For bind-mounted storage: `chown -R 82:82 /mnt/user/appdata/novarr/storage`.

#### Port 80 is taken

Change the app's mapping, e.g. `"8080:8000"`, and use that port in `APP_URL` and your proxy.

#### A container keeps restarting or stays unhealthy

```bash
docker compose -f docker-compose.oneclick.yml logs --tail 100 app
docker compose -f docker-compose.oneclick.yml exec scheduler php artisan queue:health-check
```

`init` failing means migrations failed: its log has the error, and the app will not start until it succeeds.

#### Appdata on the cache drive vanished after the mover ran

Set the `appdata` share to stay on the cache pool (Unraid 6.12: primary storage = cache, secondary = none), or use `/mnt/cache/appdata/novarr` paths directly.

## Performance

- Keep `appdata` (and Docker's image/directory) on an SSD cache pool.
- Optional limits per container: Docker Compose Manager honours `mem_limit` / `cpus` in the compose file, e.g. `mem_limit: 2g` on `app`.

## Security

- Keep MySQL and Redis unpublished (the stack does not publish them).
- Set real `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` before the first start.
- No built-in authentication: LAN, Tailscale, or an authenticating proxy only.
- Update regularly (see [Updating](#updating)).

## Quick reference

| Task | Command (from `/mnt/user/appdata/novarr`, with `-f docker-compose.oneclick.yml`) |
|---|---|
| Start / stop | `docker compose up -d` / `docker compose down` |
| Update | `docker compose pull && docker compose up -d` |
| Logs | `docker compose logs -f app` |
| Shell | `docker compose exec app sh` |
| Artisan | `docker compose exec app php artisan <command>` |
| Backup now | `docker compose exec scheduler php artisan backup:run` |
| Health | `curl http://localhost/api/health` |

| URL | Purpose |
|---|---|
| `http://<ip>/` | Novarr |
| `http://<ip>/health` | System health page |
| `http://<ip>/api/health` | Health JSON (503 when unhealthy) |
| `http://<ip>/api/ping` | Liveness probe |
