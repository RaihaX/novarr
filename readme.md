# Novarr

A self-hosted web-novel **manager, downloader, and reader** — think "Sonarr for web novels." Novarr discovers series from supported sites, scrapes their tables of contents and chapters on a schedule, stores them locally, and gives you a fast reading experience — light or dark, with a command palette, a now-reading home, a cover-grid library, continuous reading, cross-device position sync, highlights, read-aloud, reading stats, full-text search, ePub export, Send-to-Kindle, an OPDS catalog, and offline reading as an installable PWA.

Built with **Laravel 11** (PHP 8.3+), Bootstrap 5 (trimmed to what's used), Hotwire Turbo, and Vite, wearing a bespoke **design system** (see [Design & branding](#design--branding)) — dark canonical with a full light companion, Geist for the UI, Literata for reading, one hairline, no shadows.

---

## Quick install (one command)

On any Docker host (a Proxmox VM/LXC, a NAS, a mini-PC) — pulls a prebuilt image, no build, no config:

```bash
curl -O https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.oneclick.yml
docker compose -f docker-compose.oneclick.yml up -d
```

This is fully self-contained — it runs migrations and generates an app key automatically, and the stack already includes **everything Novarr needs**:

- **Novarr app** (Octane, serves the UI + static assets)
- **MySQL** and **Redis**
- **FlareSolverr** — bundled and pre-wired (`FLARESOLVERR_URL=http://flaresolverr:8191/v1`). Scraping Cloudflare-protected sites works out of the box; **you do not need to install or configure FlareSolverr separately.**
- **Scheduler** (runs the TOC/chapter/verify/email tasks and drains the queue)

Open **http://&lt;host&gt;/** and start adding novels.

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
- [OPDS catalog](#opds-catalog)
- [Offline reading (PWA)](#offline-reading-pwa)
- [Tailscale](#tailscale)
- [Deployment (Docker / Unraid)](#deployment-docker--unraid)
- [Design & branding](#design--branding)
- [Project structure](#project-structure)
- [Development](#development)

---

## Features

### Shell
- **Light and dark** — the whole app follows your system theme, with a sun/moon toggle (system → light → dark) applied before first paint. The reader keeps its own dark / sepia / light setting.
- **Phone tab bar** — Home · Library · Discover · Search · More in the thumb zone; More opens Downloads, Highlights, Stats, the System pages, Settings and the theme toggle.
- **Command palette** — ⌘K / Ctrl-K / `/`: jump to a novel or a chapter ("ascending 142"), run a scrape or metadata command, open any page, switch theme, or search chapter text.
- **Home** — a status strip (scheduler, chapters queued — the count of pending chapters, with running jobs noted when there are any — attention, chapters today), your current book as a hero with Resume, a Continue row, and new chapters grouped by novel with aligned queued / downloaded / "Read from" columns. The ops tables live on **Activity**.

### Library & discovery
- **Cover grid** — the Library is a poster grid (seven across on desktop, three on phones) with an amber reading-progress edge on every cover and status flags only for exceptions; filter chips (Reading, New chapters, Needs attention, Offline, Finished, Translated, Original), sort, and a table view for bulk work. Discover uses the same cards, with title, author row and synopsis held to fixed heights so a row of cards lines up.
- **Translated or original** — every novel is marked as a *translated* web novel (with its source language) or an *original* English web serial. The answer comes from the matched NovelUpdates page (its type and language fields) when there is one; otherwise it is inferred from the source, the novel's NovelUpdates match, genres and author name, and left *unknown* rather than guessed when the signals are weak. A quiet chip shows it on covers, the novel header, palette results and the daily email, and the edit page's **Origin** select overrides it permanently (manual choices survive every refresh). `novel:origin` backfills an existing library.
- **Add novels from 3 sources** with a Sonarr-style discover/search flow, or paste a URL directly. Discover cards carry the cover, author, and a **synopsis** — three clamped lines with a More/Less toggle — so you can tell what a novel is about before adding it. (Synopses come from NovelPing's (formerly novelarrow) list API; the other two sources' search endpoints don't return one.) The Popular / Completed lists leave out novels already in your library (matched by slug on either host, else by name) and say how many were hidden; search results show everything, with library ones marked "In library".
- **Automatic metadata** — title, author, description, genres, chapter count, cover and origin (translated / original), pulled from the source and enriched/fallback-resolved via **NovelUpdates** (including alias resolution for series listed under a different title).
- **Tags** (genre/custom) with a multi-select picker, plus tag filtering on the library.
- **Bulk actions** — pause, mark complete, delete across many novels at once (desktop and mobile).
- **Per-novel tools** — remove duplicate chapters, normalize chapter labels/numbers, jump to a chapter, search within the novel, pause/resume automatic downloads, and an **"hourly checks"** priority toggle for actively-updating series.

### Downloading & scraping
- **Scheduled TOC refresh + chapter downloads** run unattended (see [Scheduler](#scheduler--queue)).
- **Cloudflare bypass** via [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr), with `cf_clearance` cookie reuse so most fetches fall back to fast plain HTTP.
- **Polite rate-limiting** with configurable min/max delays between chapter fetches. The 10-minute sweep processes novels oldest-download-first, downloads at most `max_chapters_per_novel_per_run` (25) chapters per novel per run, rotates chapters that failed to the back of the queue, stops starting new novels after `max_run_minutes` (100) so it always finishes inside its 150-minute scheduler lock, and opens a per-novel circuit breaker (plus a 15-minute per-host cooldown) after three consecutive Cloudflare or fetch failures.
- **Resilience** — per-novel consecutive-failure tracking with a webhook alert after repeated failures; content cleaning strips ads (Taboola/Outbrain), leftover `<style>`/`<script>`, and spam lines.
- **Failure diagnostics** — every all-failed scrape run records *why* it failed (invalid chapter URL, Cloudflare challenge, page unfetchable, page loads but has no chapter text, stub-length content), and that cause is shown verbatim on the dashboard, in the daily email, and in the webhook alert instead of a generic "the source may have changed."
- **Fewer false alarms** — freshly-added novels whose chapters are still queued behind the download backlog get a 7-day grace period before being flagged, and a novel with nothing left pending is never reported as failing.
- **Junk-proof TOC parsing** — discovered chapter entries are validated (a CSS fragment scraped as a "chapter link" is skipped with a logged warning, not stored as a phantom chapter that fails forever).
- **Short chapters handled sensibly** — the anti-stub word-count gate (configurable via `min_chapter_words`, default 250) is bypassed for chapters whose label marks them as special (prologue, epilogue, side story, extra, "Chapter 0", …) or as a closing/author note (afterwords, author's note, postscript, thanks, acknowledgements, …), which are accepted from 50 words — so a genuinely short prologue downloads instead of being retried forever. Numbered chapters whose body is only an author/translator message (apology, hiatus notice, thanks) are detected from the content, stored, and shown as an "Author's note" in the reader instead of being retried as stubs. The detector distinguishes real paywall/login boilerplate (which still rejects the page) from a translator merely *mentioning* registering or logging in, and a "Translator:" / "Author:" speaker line wins over site chrome.
- **Per-chapter retry backoff** — every pending chapter records its attempts, last failure reason and next attempt time. Failures back off 10 min → 20 → 40 … up to 3 days (site-wide Cloudflare/fetch failures get a flat 30 min); success resets. After 8 attempts a chapter is **needs review**: still retried every 3 days, shown with a badge in the chapter list, and no longer counted against the novel's health. The novel page's Download button ignores the schedule.
- **NovelUpdates identity scoring** — candidate series are scored against the local title, the series' associated names and the author; a match is persisted only at ≥ 0.85 (`novels.novelupdates_match_score`), sequels are penalised, and the edit page lists the top candidates with a "Use this" button (`novel:metadata --novelupdates-url=` from the CLI). **Completion is refused while a novel's match is unscored or weak**, so existing libraries are re-scored on their next metadata refresh.
- **Structured chapter numbering** — chapters carry `number` (decimals kept), `part`, `title` and a computed `sort_key`; every list, prev/next, ePub and digest orders by it, so parts, decimals, volumes and end matter sort correctly.
- **Table-of-contents health** — every TOC run records its time and count. An empty, partial or shrinking TOC (more than 5% fewer entries) flags the novel in **Needs attention** after two bad runs; a source advertising far more chapters than it lists is called out; a shrink that holds for three runs is accepted as the new baseline. Pending chapters the source stops listing for 3 days are parked as `source_missing` (retried every 3 days) and no longer count as a stall.
- **Tunables in Settings** — minimum chapter words, chapters per novel per run, run-time bound (must stay under the 150-minute scheduler lock), NovelUpdates match threshold, and failure-snapshot retention, each shown with its default and effective value.
- **Auto-complete** — daily verification against NovelUpdates marks a series complete once every chapter is downloaded, then generates the ePub and (optionally) sends it to Kindle, with webhook notifications at each step.
- **On-demand single chapter** — a pending chapter's page offers "Download this chapter now."
- **Content extraction v2** — chapter text is found by each source's own container selectors first, then a readability-style scorer (text length × (1 − link density), penalising comment/nav/footer/sidebar/share blocks), so a reskinned site degrades gracefully instead of yielding nothing. Headings, "report chapter" notices, reader nav and watermark tails (`.me`) are dropped; inline emphasis is kept. Chapters split over several pages (`-2` / `?page=2`) are stitched together for sources that declare it.
- **Failure snapshots** — when a chapter page loads but yields no or too little text, or a table of contents comes back empty, the fetched HTML is gzipped to `storage/app/snapshots/{novel}/` (newest 5 per novel, 14 days; `NOVARR_SNAPSHOTS_ENABLED` / `_KEEP` / `_DAYS`). Browse and open them at `/novels/{id}/snapshots`.

### Reading
- **A proper reading screen** — a centred 680px column set in **Literata** (19px/1.75), a minimal 52px chrome bar, a 2px amber chapter-progress rail, a "CHAPTER 12 OF 323 · 9 MIN LEFT" kicker, and a footer with prev/next blocks around a primary **"Mark read & continue"**.
- **Persisted preferences** behind one **Aa** popover: font size (15–24px), measure (56–80ch), margins, justification/hyphenation, theme (dark/sepia/light), font (Literata / sans / Georgia / Atkinson Hyperlegible), line spacing, auto-scroll and read-aloud — with optional **per-novel overrides** ("This novel only").
- **Continuous reading** — the next chapter loads inline as you approach the end (toggleable), with swipe gestures on touch and a slide-out **chapter list** with filtering.
- **Focus mode** — hides all chrome; tap the page to peek at the controls.
- **`/continue`** — jumps straight to your resume point (the PWA's "Continue reading" shortcut uses it).
- **Read tracking** — chapters auto-mark read on open, "Continue reading" resumes **mid-chapter across devices** (scroll position syncs to the server), "Mark to here" bulk-marks earlier chapters.
- **Auto-scroll** (adjustable speed) and **read-aloud** text-to-speech with paragraph highlighting and speed control.
- **Bookmarks & highlights** — select text to save an excerpt with an optional note; browse them per novel on the **Highlights** page. Single-word selections offer a **dictionary lookup**.
- **Reading stats** — streak, chapters/words per day (30-day chart), all-time totals, most-read novels.
- **Full-text search** across chapter content (MySQL `FULLTEXT`), paginated, scoped to one novel or the whole library, with a Novels section (title/author) above the chapter hits. The navbar field opens the command palette; `/search` is the full results page.

### Export
- **ePub generation** per novel (cover, table of contents, clean formatting).
- **Generated brand covers** — a novel with no artwork gets a designed 1600×2400 fallback cover (title auto-sized to length, brand mark, chapter count) rendered server-side with GD, so nothing ships with a blank cover.
- **Send to Kindle** — emails the ePub to your Kindle address (optionally auto-sent on completion).
- **OPDS catalog** at `/opds` — browse and download your generated ePubs from KOReader, Moon+ Reader, or any OPDS-capable app.

### Offline (PWA)
- **Installable** to a phone/tablet home screen (with a polite install prompt and app shortcuts); chapters you open are cached automatically.
- **Download for offline** with **range options** (next 100 unread, all unread, all, or a custom chapter range) — practical even for multi-thousand-chapter series.
- **Offline library** view and a **read-state sync queue** that replays your offline progress when you reconnect.
- See [Offline reading](#offline-reading-pwa) for details.

### Operations
- **Settings UI** — FlareSolverr URL, notification webhook, Kindle email, scrape delays, summary-email time, minimum chapter words, chapters per novel per run, run-time bound, NovelUpdates match threshold and snapshot retention — each with its default and effective value, plus one-click **test** buttons. The Tailscale **Funnel** switch is red, asks before enabling, and shows an app-wide banner while on.
- **System health** — scheduler heartbeat, queue status, FlareSolverr reachability, failed-job inspection/retry/cleanup, and the **Needs attention** panel with Snooze (7 days) / Wake. **Activity** lists missing chapters and recent downloads; the home status strip links to both.
- **Log viewer** — live tail, download, clear, delete; logs rotate daily with 14-day retention.
- **Command runner** — execute whitelisted Artisan commands from the web UI with async job-status polling; a **persistent queue worker** picks jobs up instantly.
- **Daily email** — one message a day covering everything since the last one was sent (not a fixed 24 hours): where you left off with a Resume link, five figures (chapters downloaded, novels updated, queued, completed, needing attention), one row per novel with the chapter range and a Read link (capped at 25, with the remainder counted), newly completed novels, and an attention list that is only repeated when it changes. Plain-text alternative included; nothing is sent when there is nothing to say.
- **Fast by design** — chapter body text lives in its own `chapter_texts` table so the hot `novel_chapters` table stays small; dashboard panels are pre-warmed by the scheduler.

---

## How it works

```
                 ┌──────────────┐     background jobs (web UI)
                 │   Scheduler  │ ───────────────────────────┐
                 └──────┬───────┘                            │
        novel:toc (daily + hourly priority)                  ▼
        novel:chapter (10 min)                    persistent queue worker
                 │                                 (+ cron fallback drain)
                 ▼                                            │
        ┌──────────────────┐   Fetcher (FlareSolverr   ┌──────────────┐
        │  Source adapter  │ ◄──── / plain HTTP) ────► │ novelping /  │
        │ (TOC + selectors)│                           │ empirenovel /│
        └────────┬─────────┘                           │ novelfull    │
                 │  ContentExtractor + ChapterLabelParser └──────────────┘
                 │  (cleaned chapters, structured numbers; failure snapshots)
                 ▼
          ┌─────────────┐   metadata    ┌──────────────┐
          │  MySQL DB   │ ◄──────────── │ NovelUpdates │
          └──────┬──────┘               └──────────────┘
                 │
                 ▼
   Web UI (Blade + Turbo) ── Reader · Search · ePub · Kindle · PWA offline
```

- **Scraping is abstracted behind source adapters** (`app/Sources`). A `SourceResolver` picks the right adapter for a novel's URL; each adapter knows how to fetch that site's table of contents and metadata and declares where chapter text lives. `app/Scraping` holds the shared machinery: `Fetcher` (the only place that touches the network; fakeable in tests), `ContentExtractor` (per-source selectors, then a density scorer), `ChapterLabelParser` (volume / number / part / title / sort key from a label), `NovelUpdatesMatcher` (identity scoring) and `FailureSnapshot`.
- **Background work runs through the database queue.** Commands triggered from the web UI are dispatched as jobs and picked up by a persistent `queue:work` worker (a dedicated service in Docker, or a systemd unit on bare metal); a cron-driven `queue:work --stop-when-empty` acts as a fallback drain. The scheduler also runs the recurring TOC/chapter/verify/email tasks.
- **Chapter body text is stored separately** (`chapter_texts`, one row per downloaded chapter with a `FULLTEXT` index). The main `novel_chapters` table holds only metadata, keeping every list/stat query and schema change fast. On the `NovelChapter` model, `description` remains a virtual attribute backed by that table.
- **Settings are DB-backed** (`app_settings` table) with an `.env` fallback, so most operational config is editable from the Settings page without redeploying. (Runtime code reads env only via `config/` — the app is safe to run with `config:cache`/`route:cache`/`view:cache`.)

---

## Supported sources

| Adapter | Site | Notes |
|---|---|---|
| `EmpireNovelSource` | `empirenovel.com` | Paginated TOC via FlareSolverr + cookie reuse |
| `NovelFullSource` | `novelfull.com` | AJAX chapter-list endpoint |
| `NovelArrowSource` | NovelPing — `novelping.com` (formerly `novelarrow.com`, which now redirects there; both hosts are recognised) — and the **default** fallback | JSON api-web chapter list or page parse; browse/search results also carry a synopsis. Every outgoing URL (API, chapter pages, `images.{host}` covers) is built on `NOVARR_NOVELARROW_HOST` (config `novarr.novelarrow_host`, default `novelping.com`); migration `2026_10_04_000001` rewrote stored novelarrow URLs |

Metadata for all sources is enriched from **NovelUpdates** (description, genres, cover, completion status), with the source's own page as a fallback.

> Adding a new source = implement the `Source` interface (`matches`, `tableOfContents`, `metadata`, `name`) in `app/Sources` and register it in `SourceResolver`. The first adapter whose `matches()` returns true wins; `NovelArrowSource` matches everything as the default.

> Content hints live on the adapter too: extend `App\Sources\AbstractSource` and override `contentSelectors()` (where the chapter body is), `removeSelectors()` (ads, nav, notices) and `supportsMultiPage()` (a "next part" link selector, or `null`). All network access goes through `App\Scraping\Fetcher` (`html()` via FlareSolverr, `htmlWithSession()`, `plain()`, `json()`), so adapters can be tested offline: the golden tests in `tests/Feature/Golden*Test.php` run the real adapters against pages saved from each site (`tests/fixtures/{novelfull,novelarrow,empirenovel}/`) through `Tests\Support\FakeFetcher`, which fails any un-faked URL. Refresh expectations with `GOLDEN_UPDATE=1 php artisan test --filter Golden` and review the diff.

---

## Requirements

> Using the [one-command Docker install](#quick-install-one-command)? **Skip this section** — that stack bundles MySQL, Redis, **and FlareSolverr**, so the only host requirement is Docker. The list below is for a manual / bare-metal install.

- **PHP 8.3+**
- **MySQL 8 or MariaDB 10.6+** (chapter full-text search uses `FULLTEXT` indexes)
- **Composer** and **Node.js + Yarn**
- **A running [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) instance** (for Cloudflare-protected sites) — *bundled automatically in the one-click stack; only a separate requirement here*
- **Cron** (to drive the scheduler)
- Optional: **Resend** account (or any SMTP server) for summary/Kindle emails; **Redis** for cache/session

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

**Production tips:** enable OPcache in php.ini, run the persistent queue worker (see [Scheduler & queue](#scheduler--queue)), and cache the framework on every deploy:

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart
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
| `NOVARR_SNAPSHOTS_ENABLED` / `_KEEP` / `_DAYS` | Failure-snapshot retention defaults (overridable in Settings) | `true` / `5` / `14` |
| `NOVARR_TLS_VERIFY` | TLS verification for every scraper/FlareSolverr request (keep on) | `true` |
| `NOVARR_NOVELARROW_HOST` | Canonical host for the NovelPing (ex-novelarrow) source; API, chapter and cover URLs are built on it | `novelping.com` |
| `TRUSTED_PROXIES` | Proxies whose `X-Forwarded-*` headers are trusted (nginx / Tailscale in front) | `*` |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | Must exceed the 3600 s job timeout | `3700` |

### DB-backed settings (Settings UI)

| Setting | Purpose |
|---|---|
| `flaresolverr_url` | Override the FlareSolverr endpoint |
| `notification_webhook_url` | Override the alert webhook |
| `scrape_min_delay` / `scrape_max_delay` | Polite delay window between chapter fetches (seconds) |
| `summary_time` | When the daily summary email is sent (e.g. `08:00`) |
| `kindle_email` | Override the Kindle recipient |
| `auto_kindle` | Auto-send the ePub to Kindle when a novel completes |
| `min_chapter_words` | Word count below which a scraped chapter is treated as a stub and rejected (default `250`; special chapters and author notes are accepted from 50 words regardless) |
| `max_chapters_per_novel_per_run` | Cap per novel per 10-minute sweep (default `25`, `0` = no cap) |
| `max_run_minutes` | The sweep starts no new work after this (default `100`; must stay under the 150-minute scheduler lock) |
| `novelupdates_match_threshold` | Minimum identity score to persist a NovelUpdates match and allow auto-completion (default `0.85`) |
| `snapshots_enabled` / `snapshots_keep_per_novel` / `snapshots_days` | Failure-snapshot retention |

---

## Scheduler & queue

Novarr is **scheduler-driven**. Add the single Laravel cron entry and everything else is orchestrated from `routes/console.php`:

```cron
* * * * * cd /path/to/novarr && php artisan schedule:run >> /dev/null 2>&1
```

| Task | Schedule | What it does |
|---|---|---|
| Scheduler heartbeat | every minute | Records last-run time for the health check |
| Attention pre-warm | every 5 min | Pre-computes the Needs attention panel (shown on **System → Health**, summarised on the dashboard) |
| Queue drain (fallback) | every minute | `queue:work --stop-when-empty` — safety net behind the persistent worker |
| TOC refresh | daily @ 01:00 | `novel:toc` — refresh chapter lists for active novels |
| Priority TOC refresh | hourly | `novel:toc --frequent-only` — novels flagged "hourly checks" |
| Chapter download | every 10 min | `novel:chapter` — download newly-found pending chapters |
| Completion verify | daily @ 06:00 | `novel:verify-completion` — mark fully-downloaded series complete |
| Summary email | daily @ `summary_time` | `novel:email-summary` — recap since the previous summary (`summary_last_sent_at`): continue-reading block, headline figures, one row per updated novel, completed novels, and the attention list when it has changed (`summary_attention_hash`); a table-based brand email with a text part, built to survive Gmail/Outlook |

Jobs queued from the web UI use the **database** queue (`jobs` table); failures land in `failed_jobs` and are inspectable/retryable from the **Health** page. For instant pickup, run a **persistent worker** alongside the cron (the Docker stack ships one as a service; on bare metal use a systemd unit):

```ini
# /etc/systemd/system/novarr-worker.service
[Service]
User=www-data
WorkingDirectory=/path/to/novarr
ExecStart=/usr/bin/php artisan queue:work --queue=commands,default --sleep=1 --tries=1 --timeout=3600 --max-time=3600
Restart=always
```

Run `php artisan queue:restart` after each deploy so the worker reloads new code. Scheduled scrapes hold `withoutOverlapping` locks of 90 min (TOC) and 150 min (chapters) — short enough that a killed run can't block the next day — and UI-triggered scrapes take a per-novel lock so the two never interleave.

---

## Artisan commands

| Command | Description |
|---|---|
| `novel:create {name} {url}` | Create a novel and auto-fetch its metadata |
| `novel:toc {novel=0} {--frequent-only}` | Scrape table(s) of contents (0 = all active novels) |
| `novel:chapter {novel=0} {--chapter=}` | Download pending chapters (or one chapter by id) |
| `novel:metadata {novel?} {--novelupdates-url=}` | Refresh metadata (description, author, genres, cover); `--novelupdates-url` forces a NovelUpdates match (score 1.0) |
| `novel:epub {novel=0}` | Generate ePub(s) (0 = all not-yet-generated) |
| `novel:send-to-kindle {novel} {--to=} {--generate}` | Email a novel's ePub to Kindle (`--generate` builds it first and aborts if that fails) |
| `novel:verify-completion {novel=0} {--dry-run} {--force} {--no-kindle}` | Verify against NovelUpdates and mark complete |
| `novel:email-summary {--hours=} {--to=}` | Send the daily summary; the window runs from the last summary sent (else 24 h) unless `--hours` is given |
| `novel:origin {novel=0} {--force} {--no-fetch} {--delay=2}` | Mark novels translated / original from NovelUpdates, else inference; `--force` re-evaluates inferred rows and re-fetches NovelUpdates ones, `--no-fetch` infers from stored data only. Manual choices are never overwritten |
| `novel:normalize_labels {novel} {--dry-run} {--renumber} {--dedupe}` | Tidy labels; keeps stored numbers (decimals included) unless `--renumber`; duplicate removal only with `--dedupe` |
| `novel:fix_chapters {novel=0}` | Resolve chapters with missing numbers by elimination against the sequence |
| `novel:clean_chapter_content {novel} {--dry-run}` | Remove leftover CSS and ad-widget text; refuses to save if more than half the words would go |
| `novel:chaptercleaner {novel} {--dry-run}` | Re-queue downloaded chapters that fail the scraper's word gate (text kept until re-downloaded) |
| `novel:info` | Print novel info, chapter counts, completion % |
| `queue:health-check` | Report queue system health |

Any of these can also be run from the **Commands** page in the UI with live job-status polling, or from the command palette ("scrape toc <novel>"). The destructive ones (normalize, fix, clean, cleaner) run as a **dry run first** from the novel page and ask before applying.

---

## OPDS catalog

Every novel with a generated ePub is published as an **OPDS 1.2 acquisition feed** at `/opds` — point KOReader, Moon+ Reader, Calibre, or any OPDS-capable reader at `https://<your-host>/opds` to browse your library with covers and download ePubs directly. No configuration needed; the feed reflects whatever `novel:epub` has produced.

---

## Offline reading (PWA)

Novarr is an installable Progressive Web App. **HTTPS is required** (service workers only run in a secure context) — `localhost` and an HTTPS origin (e.g. via Tailscale Serve) both qualify.

**App shell & automatic caching** — the manifest + service worker (`public/sw.js`) make Novarr installable; static assets are cached-first and any chapter you open is cached for later. Offline navigations fall back to the cache, then a friendly `/offline` page.

**Download for offline** — on a novel's page, the **Download ▾** menu pre-caches chapters via the service worker with live progress. Range options keep big series manageable:
- **Next 100 unread**
- **All unread**
- **All chapters**
- **Custom range** (from / to chapter number)

Downloads **merge** into any existing offline copy (union by chapter), so you can pull a long series down in chunks. A record of what's saved lives in **IndexedDB**, powering the **Downloads** page (`/library`), which renders with no connection.

**Read-state sync queue** — marking chapters read (and opening cached chapters) while offline is queued in IndexedDB and **replayed automatically when you reconnect** (on the `online` event and next app open — iOS Safari has no Background Sync). The read-state endpoints are CSRF-exempt specifically so these tokenless replays succeed.

> Explicit downloads live in an unversioned `novarr-offline` cache and survive worker updates. Bump `CACHE_VERSION` in `public/sw.js` when changing the app-shell/page caching; auto-cached pages are migrated into the new page cache (bounded to 60 entries) rather than dropped, and old build bundles are pruned only once no cached page still references them.

---

## Tailscale

Novarr can run **on your tailnet** with a bundled Tailscale sidecar, plus an in-app **Settings → Tailscale** panel for status and HTTPS.

**One-command install on your tailnet** (instead of the plain one-click stack):

```bash
curl -O https://raw.githubusercontent.com/RaihaX/novarr/master/docker-compose.tailscale.yml
TS_AUTHKEY=tskey-auth-xxxx docker compose -f docker-compose.tailscale.yml up -d
```

Grab an auth key from the [Tailscale admin → Keys](https://login.tailscale.com/admin/settings/keys) page. The app shares the sidecar's network, so it joins your tailnet automatically (userspace mode — works in an unprivileged Proxmox LXC, no `/dev/net/tun` needed) and can still reach MySQL/Redis/FlareSolverr on the internal network.

**Then, in Settings → Tailscale:**
- See your machine's tailnet status — node name, `100.x` IP, MagicDNS name.
- **Serve over HTTPS** — one switch gives Novarr a `https://<node>.<tailnet>.ts.net/` URL (and satisfies the [PWA's HTTPS requirement](#offline-reading-pwa) with no reverse proxy). The choice persists across restarts.
- **Funnel** — optionally expose it on the public internet (use with care).

After enabling Serve, set `APP_URL` to that HTTPS origin in the compose file.

> **How it works / why a panel, not a magic button.** Tailscale is a root-level daemon, so the *daemon* runs in the sidecar; the app image ships only the `tailscale` CLI and talks to the sidecar's control socket. The panel **degrades gracefully** — on any install without Tailscale it simply shows "not connected," so nothing breaks. (In this stack the app container runs as root so it can drive the Serve/Funnel socket operations.)
>
> Already running Tailscale on the host instead? Point the panel at the host daemon by mounting its socket into the app container — or just use host-level `tailscale serve` directly.

---

## Deployment (Docker / Unraid)

### Bare metal (`deploy.sh`)

On a plain Linux box the repo's `deploy.sh` is the whole release process: it re-executes itself from a temp copy (so the pull can't change the running script), **dumps the database first** into `storage/backups/` (five copies kept) and refuses to continue if the dump fails, enters maintenance mode (printing a bypass secret), pulls with `--ff-only`, installs dependencies, builds assets, migrates from a cleared config, rebuilds the config/route/view caches without touching the application cache (so scheduler locks survive), leaves maintenance mode, restarts the queue worker and normalises storage ownership. Put immutable caching on `/build/assets/` and `no-cache` on `/sw.js` and `/build/manifest.json` in your nginx site (see `docker/nginx/nginx.conf` for the blocks).


This is the **build-from-source** stack with Nginx and zero-downtime updates — for most people the [one-command install](#quick-install-one-command) is easier. A full container stack is included (PHP-FPM app, Nginx, MySQL, Redis, scheduler, and queue worker), driven by a `Makefile`.

> Unlike the one-click stack, this Makefile stack does **not** bundle FlareSolverr — point `FLARESOLVERR_URL` in `.env` at an existing FlareSolverr instance, or add a `flaresolverr` service to the compose file.

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

## Design & branding

Novarr's look is a documented design system, not ad-hoc CSS. The full brand pack (spec, tokens, logo SVGs, visual reference canvas) lives in **`design_handoff_novarr_brand/`**; the implementation follows it exactly.

- **Dark is canonical, light is real** — `#0F1216` ground with two surface steps, and a full light companion (`#F7F8FA`) the whole shell renders in. Every colour token is exposed at runtime as a `--nv-*` custom property under `[data-bs-theme=dark|light]`, so a page reads correctly in both themes without per-page work; the reader's own dark / sepia / light setting overrides on its page. Contrast is ≥ 4.5:1 for every text token on every surface in both themes. One 1px hairline separates everything; there are **no shadows** anywhere.
- **Tokens are the single source of truth** — `resources/css/_variables.scss` holds the palette, type scale, spacing, and radii, mapped onto Bootstrap 5.3's variables (Bootstrap itself is imported trimmed via `_bootstrap-trim.scss`). Component recipes live in `_components.scss`; per-area styling in `_dashboard` / `_home` / `_library` / `_reader` / `_views` / `_palette` / `_tables` / `_a11y` / `_status`.
- **Type**: **Geist** for UI, **Geist Mono** for counts/timestamps/chapter numbers, **Literata** for reading — all self-hosted (Fontsource, OFL). Static TTF instances are bundled in `resources/fonts/` for server-side (GD) rendering of ePub covers.
- **One status recipe everywhere** — badges, panels, and progress bars all use the same triad (full-value text, 12% fill, 35% border) across the states in `App\Enums\NovelState`, rendered through `<x-status>`: downloaded (green), queued (**cyan**, deliberately not blue so it never collides with links), needs-attention (amber), failed (red), paused (muted). Amber is otherwise reserved for *reading* signals (bookmark, reading-progress bars); indigo `#6470FF` carries all primary action.
- **Logo suite** — **Serial**: four flat horizontal bars on a 32×32 grid (three indigo `#6470FF`, stepping down in length, the last and shortest in amber `#F0B429`; radius 0, no gradient), beside the "NOVARR." wordmark (Geist 600, amber full stop). Below 20px a heavier three-bar cut takes over; the mono variant is `currentColor` with the last bar at 55%. Ships as `<x-brand-mark>` in Blade (`variant="mono"`/`"favicon"`, auto three-bar under 20px), `public/logo.svg` (wordmark outlined, no font dependency), `favicon.svg` + pixel-hinted 16/32/48 `favicon.ico`, PWA icons (`icon-192/512`, full-bleed `icon-maskable-512`, `apple-touch-icon`), and the GD-drawn mark on generated ePub covers.

Restyling something? Start from the tokens and the recipes in `_components.scss`; if a value isn't a token, it probably shouldn't exist.

---

## Project structure

```
app/
├── Console/Commands/      # Artisan commands (novel:toc, novel:chapter, …)
├── Enums/NovelState       # Status states → triad classes (used by <x-status>)
├── Http/
│   ├── Controllers/       # Novel, NovelChapter, Discover, Search (+ palette),
│   │                      #   Home, Activity, Attention (snooze), Metadata
│   │                      #   (NovelUpdates candidates), Snapshot, Stats,
│   │                      #   Bookmark, Opds, Settings, SystemHealth, Health,
│   │                      #   Log, Command, Tailscale
│   ├── Helpers.php        # Scraping/TOC helpers, metadata, Kindle helpers
│   └── Middleware/        # PerformanceMonitoring (CSRF exemptions live in bootstrap/app.php)
├── Jobs/RunNovelCommand   # Queued Artisan command runner (per-novel lock, 3600s timeout)
├── Scraping/              # Fetcher (all network I/O), ContentExtractor, ChapterLabelParser
│                          #   (+ ChapterLabel DTO), NovelUpdatesMatcher, FailureSnapshot,
│                          #   OriginInference (translated vs original)
├── Services/              # NovelHealth (needs-attention: scrape, stall, TOC branches; progress),
│                          #   DefaultCoverGenerator (brand fallback covers), ChapterNumberResolver
├── Sources/               # Source interface, AbstractSource (content selectors),
│                          #   NovelArrow/EmpireNovel/NovelFull adapters, SourceResolver
└── *.php                  # Models: Novel, NovelChapter, ChapterText (body text),
                           #   Bookmark, File, Tag, Group, Language, Setting
database/migrations/       # Schema (novels, novel_chapters, chapter_texts,
                           #   bookmarks, tags, app_settings, …)
design_handoff_novarr_brand/  # Brand pack: spec (README), tokens, logo SVGs, canvas
resources/
├── css/                   # Design system: _variables (tokens) → _bootstrap-trim →
│   │                      #   _components → _dashboard / _home / _library / _reader /
│   │                      #   _views / _status / _palette / _tables / _a11y, entry app.scss
├── fonts/                 # Static Geist/Literata TTFs for GD cover rendering (OFL)
├── js/
│   ├── app.js             # Entry: Turbo, window.Novarr API, SW + install prompt
│   ├── fonts.js           # Self-hosted faces; Atkinson loaded on the reader only
│   ├── bootstrap.js       # The Bootstrap plugins in use (aliased as 'bootstrap')
│   ├── theme.js · modal.js · palette.js   # Light/dark, focus traps + sheets, command palette
│   ├── commands.js        # Async command execution + job polling
│   ├── offline.js         # PWA: IndexedDB library, range downloads, sync queue
│   └── funnel.js · navsearch.js · tagpicker.js · toast.js · confirm.js
└── views/                 # Blade templates (home, activity, novels, chapters, library, …)
    ├── components/        # <x-icon> (34 Lucide icons), <x-brand-mark>, <x-status>, <x-cover>
    └── partials/          # tab bar, palette, breadcrumb, snoozed note
public/
├── sw.js                  # Service worker (app shell + offline downloads)
├── manifest.webmanifest   # PWA manifest
└── icon-*.png             # App / maskable / apple-touch icons
routes/
├── web.php                # All web routes
└── console.php            # Scheduler definitions
tests/
├── Feature/ · Unit/       # PHP suite (incl. Golden*Test adapters-over-fixtures)
├── fixtures/              # Saved source pages + expected JSON per adapter
├── js/                    # node --test suites: offline queue, service worker, palette, perf
└── Support/FakeFetcher    # Network fake that fails any un-faked URL
deploy.sh                  # Bare-metal release script (backup → down → pull → migrate → up)
docker/ · Dockerfile · docker-compose.yml · Makefile   # Container stack
```

---

## Development

```bash
yarn dev          # Vite dev server (HMR)
yarn build        # production assets (Bootstrap trimmed, Latin-only font faces, vendor chunk)
php artisan serve # local app server
php artisan test  # PHP suite (golden adapter tests make no network calls)
yarn test:js      # JS suites: offline queue, service worker, palette, bundle guards
```

`GOLDEN_UPDATE=1 php artisan test --filter Golden` regenerates the adapter expectations (and marks those tests incomplete so the flag can never make CI green). Tests flush the cache per test; `phpunit.xml` pins the array cache/session drivers.

For remote/tablet access to the Vite dev server (e.g. over Tailscale), the dev assets must be advertised at the externally-reachable HTTPS origin — set `server.origin`, `server.allowedHosts`, and `server.hmr` in `vite.config.js` from `.env`, and confirm `public/hot` shows the external URL.

**Tech stack:** Laravel 11 · PHP 8.3+ · MySQL 8 / MariaDB · Bootstrap 5.3 under the Novarr design system · Hotwire Turbo · Vite · self-hosted fonts (Geist, Geist Mono, Literata via Fontsource; Atkinson Hyperlegible loaded on the reader as the "Legible" option) · Lucide icons (inlined) · FlareSolverr · Resend · GD (cover rendering) · PWA (service worker + IndexedDB).

---

*Novarr is a personal, single-user, self-hosted tool. Scrape responsibly and respect the source sites' terms and rate limits.*
