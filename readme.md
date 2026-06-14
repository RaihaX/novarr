# Novarr

A self-hosted web-novel **manager, downloader, and reader** — think "Sonarr for web novels." Novarr discovers series from supported sites, scrapes their tables of contents and chapters on a schedule, stores them locally, and gives you a fast dark-mode reading experience with progress tracking, full-text search, ePub export, Send-to-Kindle, and offline reading as an installable PWA.

Built with **Laravel 11** (PHP 8.3+), Bootstrap 5, Hotwire Turbo, and Vite.

---

## Quick install (one command)

On any Docker host (a Proxmox VM/LXC, a NAS, a mini-PC) — pulls a prebuilt image, no build, no config:

```bash
curl -O https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.oneclick.yml
docker compose -f docker-compose.oneclick.yml up -d
```

That brings up the app, MySQL, Redis, and **FlareSolverr** (for Cloudflare-protected scraping), runs migrations, and generates an app key automatically. Open **http://&lt;host&gt;/** and start adding novels.

- **PWA / install-to-home-screen needs HTTPS** — put a reverse proxy (Nginx Proxy Manager, Caddy, Traefik) or **Tailscale Serve** in front, then set `APP_URL` to that origin in the compose file.
- The image targets **linux/amd64** (typical x86 hosts / Proxmox). MySQL & Redis ports are not exposed; the internal default passwords are safe to leave as-is.
- Email (summary / Send-to-Kindle) is off by default (`MAIL_MAILER: log`) — set `MAIL_MAILER`, `RESEND_KEY`, and `MAIL_FROM_ADDRESS` to enable it.

> Prefer to build from source, or want the full production stack with nginx and zero-downtime updates? See [Installation](#installation) and [Deployment](#deployment-docker--unraid).

---

## Table of contents

- [Quick install (one command)](#quick-install-one-command)
- [Features](#features)
- [How it works](#how-it-works)
- [Supported sources](#supported-sources)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Scheduler & queue](#scheduler--queue)
- [Artisan commands](#artisan-commands)
- [Offline reading (PWA)](#offline-reading-pwa)
- [Deployment (Docker / Unraid)](#deployment-docker--unraid)
- [Project structure](#project-structure)
- [Development](#development)

---

## Features

### Library & discovery
- **Add novels from 3 sources** with a Sonarr-style discover/search flow, or paste a URL directly.
- **Automatic metadata** — title, author, description, genres, chapter count, and cover, pulled from the source and enriched/fallback-resolved via **NovelUpdates** (including alias resolution for series listed under a different title).
- **Tags** (genre/custom) with a multi-select picker, plus tag filtering on the library.
- **Bulk actions** — pause, mark complete, delete across many novels at once.
- **Per-novel tools** — remove duplicate chapters, normalize chapter labels/numbers, jump to a chapter, pause/resume automatic downloads.

### Downloading & scraping
- **Scheduled TOC refresh + chapter downloads** run unattended (see [Scheduler](#scheduler--queue)).
- **Cloudflare bypass** via [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr), with `cf_clearance` cookie reuse so most fetches fall back to fast plain HTTP.
- **Polite rate-limiting** with configurable min/max delays between chapter fetches.
- **Resilience** — per-novel consecutive-failure tracking with a webhook alert after repeated failures; content cleaning strips ads (Taboola/Outbrain), leftover `<style>`/`<script>`, and spam lines.
- **Auto-complete** — daily verification against NovelUpdates marks a series complete once every chapter is downloaded.

### Reading
- **In-app reader** with persisted preferences: font size, width, theme (dark/sepia/light), serif/sans, and a distraction-free **focus mode**.
- **Read tracking** — chapters auto-mark read on open, "Continue reading" jumps to your next unread chapter, "Mark to here" bulk-marks earlier chapters, and per-chapter **scroll position resume**.
- **Full-text search** across chapter content (MySQL `FULLTEXT`), plus a navbar quick-search with autocomplete.

### Export
- **ePub generation** per novel (cover, table of contents, clean formatting).
- **Send to Kindle** — emails the ePub to your Kindle address (optionally auto-sent on completion).

### Offline (PWA)
- **Installable** to a phone/tablet home screen; chapters you open are cached automatically.
- **Download for offline** with **range options** (next 100 unread, all unread, all, or a custom chapter range) — practical even for multi-thousand-chapter series.
- **Offline library** view and a **read-state sync queue** that replays your offline progress when you reconnect.
- See [Offline reading](#offline-reading-pwa) for details.

### Operations
- **Settings UI** — FlareSolverr URL, notification webhook, Kindle email, scrape delays, summary-email time, with one-click **test** buttons.
- **System health** dashboard — scheduler heartbeat, queue status, failed-job inspection/retry/cleanup.
- **Log viewer** — live tail, download, clear, delete.
- **Command runner** — execute any Artisan command from the web UI with async job-status polling.

---

## How it works

```
                 ┌──────────────┐     cron (every minute)
                 │   Scheduler  │ ───────────────────────────┐
                 └──────┬───────┘                            │
        novel:toc (01:00)  novel:chapter (10 min)            ▼
                 │                                   queue:work --stop-when-empty
                 ▼                                            │
        ┌──────────────────┐    FlareSolverr / HTTP    ┌──────────────┐
        │  Source adapter  │ ◄───────────────────────► │ novelbin /   │
        │ (TOC + content)  │                           │ empirenovel /│
        └────────┬─────────┘                           │ novelfull    │
                 │  cleaned chapters                    └──────────────┘
                 ▼
          ┌─────────────┐   metadata    ┌──────────────┐
          │  MySQL DB   │ ◄──────────── │ NovelUpdates │
          └──────┬──────┘               └──────────────┘
                 │
                 ▼
   Web UI (Blade + Turbo) ── Reader · Search · ePub · Kindle · PWA offline
```

- **Scraping is abstracted behind source adapters** (`app/Sources`). A `SourceResolver` picks the right adapter for a novel's URL; each adapter knows how to fetch that site's table of contents and metadata. Chapter *content* extraction is generic (a multi-selector scraper) and shared across sources.
- **Background work runs through the database queue.** Commands triggered from the web UI are dispatched as jobs; a cron-driven `queue:work --stop-when-empty` drains them, and the scheduler also runs the recurring TOC/chapter/verify/email tasks.
- **Settings are DB-backed** (`app_settings` table) with an `.env` fallback, so most operational config is editable from the Settings page without redeploying.

---

## Supported sources

| Adapter | Site | Notes |
|---|---|---|
| `EmpireNovelSource` | `empirenovel.com` | Paginated TOC via FlareSolverr + cookie reuse |
| `NovelFullSource` | `novelfull.com` | AJAX chapter-list endpoint |
| `NovelBinSource` | `novelbin.com` (and **default** fallback) | AJAX chapter archive or page parse |

Metadata for all sources is enriched from **NovelUpdates** (description, genres, cover, completion status), with the source's own page as a fallback.

> Adding a new source = implement the `Source` interface (`matches`, `tableOfContents`, `metadata`, `name`) in `app/Sources` and register it in `SourceResolver`. The first adapter whose `matches()` returns true wins; `NovelBinSource` matches everything as the default.

---

## Requirements

- **PHP 8.3+** (developed/tested on 8.5)
- **MySQL 8** (the chapter full-text search uses a MySQL `FULLTEXT` index)
- **Composer** and **Node.js + Yarn**
- **A running [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) instance** (for Cloudflare-protected sites)
- **Cron** (to drive the scheduler)
- Optional: **Resend** account (or any SMTP server) for summary/Kindle emails; **Redis** for cache/session

> Prefer containers? The included Docker stack bundles PHP-FPM, Nginx, MySQL, Redis, the scheduler, and a queue worker — see [Deployment](#deployment-docker--unraid).

---

## Installation

Bare-metal / manual install:

```bash
# 1. Clone & install dependencies
git clone <repo> novarr && cd novarr
composer install
yarn install

# 2. Environment
cp .env.example .env
php artisan key:generate
#   → edit .env (DB credentials, APP_URL, FlareSolverr URL, mail — see Configuration)

# 3. Database
php artisan migrate
php artisan storage:link        # serve covers from storage/app/public

# 4. Build front-end assets
yarn build

# 5. Wire up the scheduler (see Scheduler & queue)
#    * * * * * cd /path/to/novarr && php artisan schedule:run >> /dev/null 2>&1
```

Then visit `APP_URL`. Add your first novel from **Novels → Add Novel**, or via the CLI:

```bash
php artisan novel:create "Beyond the Timescape" "https://novelfull.com/outside-of-time.html"
```

---

## Configuration

Most operational settings are editable from the **Settings** page (stored in `app_settings`); they fall back to the corresponding `.env` value when unset.

### Key `.env` variables

| Variable | Purpose | Default |
|---|---|---|
| `APP_NAME` | Display name | `Novarr` |
| `APP_URL` | Public URL (set to your **HTTPS** origin — required for the PWA) | — |
| `APP_TIMEZONE` | Schedule/timestamp timezone | `UTC` |
| `DB_CONNECTION` / `DB_*` | MySQL connection | `mysql` |
| `CACHE_STORE` | Cache driver | `redis` (or `file`/`database`) |
| `SESSION_DRIVER` | Session driver | `redis` (or `file`) |
| `QUEUE_CONNECTION` | Queue driver — keep as `database` | `database` |
| `MAIL_MAILER` | `resend`, `smtp`, or `failover` | `resend` |
| `RESEND_KEY` | Resend API key (if using Resend) | — |
| `MAIL_FROM_ADDRESS` | Sender address | — |
| `FLARESOLVERR_URL` | FlareSolverr endpoint | `http://192.168.1.41:8191/v1` |
| `KINDLE_EMAIL` | Send-to-Kindle recipient | — |
| `NOTIFICATION_WEBHOOK_URL` | Discord/ntfy webhook for scraping alerts | — |

### DB-backed settings (Settings UI)

| Setting | Purpose |
|---|---|
| `flaresolverr_url` | Override the FlareSolverr endpoint |
| `notification_webhook_url` | Override the alert webhook |
| `scrape_min_delay` / `scrape_max_delay` | Polite delay window between chapter fetches (seconds) |
| `summary_time` | When the daily summary email is sent (e.g. `08:00`) |
| `kindle_email` | Override the Kindle recipient |
| `auto_kindle` | Auto-send the ePub to Kindle when a novel completes |

---

## Scheduler & queue

Novarr is **scheduler-driven**. Add the single Laravel cron entry and everything else is orchestrated from `routes/console.php`:

```cron
* * * * * cd /path/to/novarr && php artisan schedule:run >> /dev/null 2>&1
```

| Task | Schedule | What it does |
|---|---|---|
| Scheduler heartbeat | every minute | Records last-run time for the health check |
| Queue drain | every minute | `queue:work --queue=commands,default --stop-when-empty --timeout=3600` (non-overlapping) |
| TOC refresh | daily @ 01:00 | `novel:toc` — refresh chapter lists for active novels |
| Chapter download | every 10 min | `novel:chapter` — download newly-found pending chapters |
| Completion verify | daily @ 06:00 | `novel:verify-completion` — mark fully-downloaded series complete |
| Summary email | daily @ `summary_time` | `novel:email-summary` — recap of new chapters & completed novels |

Jobs queued from the web UI use the **database** queue (`jobs` table); failures land in `failed_jobs` and are inspectable/retryable from the **Health** page. (The Docker stack runs the scheduler and a queue worker as dedicated services.)

---

## Artisan commands

| Command | Description |
|---|---|
| `novel:create {name} {url}` | Create a novel and auto-fetch its metadata |
| `novel:toc {novel=0}` | Scrape table(s) of contents (0 = all active novels) |
| `novel:chapter {novel=0}` | Download pending chapters |
| `novel:metadata {novel?}` | Refresh metadata (description, author, genres, cover) |
| `novel:epub {novel=0}` | Generate ePub(s) (0 = all not-yet-generated) |
| `novel:send-to-kindle {novel} {--to=} {--generate}` | Email a novel's ePub to Kindle |
| `novel:verify-completion {novel=0} {--dry-run} {--force} {--no-kindle}` | Verify against NovelUpdates and mark complete |
| `novel:email-summary {--hours=24} {--to=}` | Send the new-chapters/completed-novels summary |
| `novel:normalize_labels {novel=0} {--dry-run}` | Normalize labels and fix chapter numbers for sorting |
| `novel:chapter_cleanser {novel=0}` | Strip unwanted tags/characters from chapter content |
| `novel:clean_chapter_content` | Remove leftover CSS and ad-widget text from chapters |
| `novel:calculate_chapter` | Recompute chapter counts |
| `novel:info` | Print novel info, chapter counts, completion % |
| `queue:health-check` | Report queue system health |

Any of these can also be run from the **Commands** page in the UI with live job-status polling.

---

## Offline reading (PWA)

Novarr is an installable Progressive Web App. **HTTPS is required** (service workers only run in a secure context) — `localhost` and an HTTPS origin (e.g. via Tailscale Serve) both qualify.

**App shell & automatic caching** — the manifest + service worker (`public/sw.js`) make Novarr installable; static assets are cached-first and any chapter you open is cached for later. Offline navigations fall back to the cache, then a friendly `/offline` page.

**Download for offline** — on a novel's page, the "Download for offline" dropdown pre-caches chapters via the service worker with live progress. Range options keep big series manageable:
- **Next 100 unread**
- **All unread**
- **All chapters**
- **Custom range** (from / to chapter number)

Downloads **merge** into any existing offline copy (union by chapter), so you can pull a long series down in chunks. A record of what's saved lives in **IndexedDB**, powering the **Offline Library** page (`/library`), which renders with no connection.

**Read-state sync queue** — marking chapters read (and opening cached chapters) while offline is queued in IndexedDB and **replayed automatically when you reconnect** (on the `online` event and next app open — iOS Safari has no Background Sync). The read-state endpoints are CSRF-exempt specifically so these tokenless replays succeed.

> Bump `CACHE_VERSION` in `public/sw.js` when changing caching behaviour; old caches are purged on the next activation.

---

## Deployment (Docker / Unraid)

A full container stack is included (PHP-FPM app, Nginx, MySQL, Redis, scheduler, and queue worker), driven by a `Makefile`.

```bash
git clone <repo> novarr && cd novarr
cp .env.example .env          # edit DB credentials, APP_URL, mail, FlareSolverr
make deploy                   # initial build + migrate + start
```

Common targets (run `make help` for the full list):

| Command | Description |
|---|---|
| `make deploy` | Initial deployment (build, migrate, start) |
| `make update` | Zero-downtime update (pull, build, migrate) |
| `make rollback` | Roll back the last update |
| `make logs` / `make logs-app` | Tail logs (all, or a single service) |
| `make shell` / `make tinker` | App container shell / Tinker REPL |
| `make db-shell` | MySQL shell |
| `make backup` | Back up database + storage |
| `make restart` / `make down` / `make up` | Lifecycle control |

- **Full Docker guide:** [DOCKER.md](DOCKER.md)
- **Unraid guide:** [UNRAID_DEPLOYMENT.md](UNRAID_DEPLOYMENT.md)
- **Migrating dev → prod data:** `make migrate-export`, copy the `migrate/` dir to the server, then `./docker-deploy.sh --migrate` (see [DOCKER.md](DOCKER.md#migrating-from-development-to-production)).

For PWA installs you'll want HTTPS in front of the stack — terminate TLS at your reverse proxy (or Tailscale Serve) and point `APP_URL` at the HTTPS origin.

---

## Project structure

```
app/
├── Console/Commands/      # Artisan commands (novel:toc, novel:chapter, …)
├── Http/
│   ├── Controllers/       # Novel, NovelChapter, Discover, Search, Settings,
│   │                      #   SystemHealth, Log, Command, Home controllers
│   ├── Helpers.php        # Scraping, metadata, FlareSolverr, ePub, Kindle helpers
│   └── Middleware/        # incl. CSRF exemptions for offline replay
├── Jobs/RunNovelCommand   # Queued Artisan command runner (3600s timeout)
├── Sources/               # Source interface + NovelBin/EmpireNovel/NovelFull adapters
└── *.php                  # Models: Novel, NovelChapter, File, Tag, Group,
                           #   Language, Setting, User
database/migrations/       # Schema (novels, novel_chapters, tags, app_settings, …)
resources/
├── css/app.scss           # Bootstrap 5 dark theme + custom styles
├── js/
│   ├── app.js             # Entry: Turbo, window.Novarr API, SW registration
│   ├── commands.js        # Async command execution + job polling
│   ├── offline.js         # PWA: IndexedDB library, range downloads, sync queue
│   ├── navsearch.js · tagpicker.js · toast.js
│   └── bootstrap.js       # Axios + CSRF setup
└── views/                 # Blade templates (novels, chapters, library, settings, …)
public/
├── sw.js                  # Service worker (app shell + offline downloads)
├── manifest.webmanifest   # PWA manifest
└── icon-*.png             # App / maskable / apple-touch icons
routes/
├── web.php                # All web routes
└── console.php            # Scheduler definitions
docker/ · Dockerfile · docker-compose.yml · Makefile   # Container stack
```

---

## Development

```bash
yarn dev          # Vite dev server (HMR)
php artisan serve # local app server
php artisan test  # run the test suite
```

For remote/tablet access to the Vite dev server (e.g. over Tailscale), the dev assets must be advertised at the externally-reachable HTTPS origin — set `server.origin`, `server.allowedHosts`, and `server.hmr` in `vite.config.js` from `.env`, and confirm `public/hot` shows the external URL.

**Tech stack:** Laravel 11 · PHP 8.3+ · MySQL 8 · Bootstrap 5 (dark) · Hotwire Turbo · Vite · FlareSolverr · Resend · PWA (service worker + IndexedDB).

---

*Novarr is a personal, single-user, self-hosted tool. Scrape responsibly and respect the source sites' terms and rate limits.*
