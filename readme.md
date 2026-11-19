# Novarr

A self-hosted web-novel **manager, downloader, and reader** — think "Sonarr for web novels." Novarr discovers series from supported sites, scrapes their tables of contents and chapters on a schedule, stores them locally, and gives you a fast reading experience — light or dark, with a command palette, a now-reading home, a cover-grid library, continuous reading, cross-device position sync, highlights, read-aloud, reading stats, full-text search, ePub export, Send-to-Kindle, an OPDS catalog, and offline reading as an installable PWA. A JSON API serves two companion readers that live in this repository: a native **iPhone/iPad app** and a **KOReader plugin** for Kindles and other e-readers.

Built with **Laravel 12** (PHP 8.3+), Bootstrap 5 (trimmed to what's used), Hotwire Turbo, and Vite, wearing a bespoke **design system** (see [Design & branding](#design--branding)) — dark canonical with a full light companion, Geist for the UI, Literata for reading, one hairline, no shadows.

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
- The prebuilt image is published for **linux/amd64 and linux/arm64** (x86 hosts, Apple Silicon, ARM boards). MySQL & Redis ports are not exposed; the internal default passwords are safe to leave as-is.
- Email (summary / Send-to-Kindle) is off by default (`MAIL_MAILER: log`) — set `MAIL_MAILER`, `RESEND_KEY`, and `MAIL_FROM_ADDRESS` to enable it.

**Or clone and build it yourself** — the same stack, built from the source in the checkout (also works on Apple Silicon and ARM boards):

```bash
git clone https://github.com/RaihaX/novarr.git && cd novarr
docker compose up -d --build
```

No `.env` and nothing to install besides Docker. Use `NOVARR_PORT=8080 docker compose up -d --build` if port 80 is taken.

> Running on bare metal instead? See [Installation](#installation) and [Deployment](#deployment).

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
- [Apps and JSON API](#apps-and-json-api)
- [Offline reading (PWA)](#offline-reading-pwa)
- [Tailscale](#tailscale)
- [Deployment](#deployment)
  - [Publishing on the internet with a Cloudflare Tunnel](#publishing-on-the-internet-with-a-cloudflare-tunnel)
- [Backup and restore](#backup-and-restore)
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

### Apps & API
- **JSON API** at `/api/v1` — library, chapters, reading progress, Discover, adding novels, on-demand checks for new chapters and ePub builds. See [Apps and JSON API](#apps-and-json-api).
- **iPhone / iPad app** (`ios/`) — a native SwiftUI reader: home, library, novel pages, reader and settings, with reading position shared with the website.
- **KOReader plugin** (`koreader/`) — read on a Kindle or any KOReader device straight from the server: a cover-shelf home, chapters joined into groups so chapter changes are plain page turns, 100 chapters saved ahead with Wi-Fi switched off in between, Discover and "check for new chapters" on the device, reading stats, a second server address for away from home, and plugin updates over Wi-Fi.

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
   JSON API (/api/v1)     ── iPhone/iPad app · KOReader plugin
```

- **Scraping is abstracted behind source adapters** (`app/Sources`). A `SourceResolver` picks the right adapter for a novel's URL; each adapter knows how to fetch that site's table of contents and metadata and declares where chapter text lives. `app/Scraping` holds the shared machinery: `Fetcher` (the only place that touches the network; fakeable in tests), `ContentExtractor` (per-source selectors, then a density scorer), `ChapterLabelParser` (volume / number / part / title / sort key from a label), `NovelUpdatesMatcher` (identity scoring) and `FailureSnapshot`.
- **Background work runs through the database queue.** Commands triggered from the web UI are dispatched as jobs and drained every minute by the scheduler's `queue:work --stop-when-empty` (this is how the Docker stacks run); on bare metal a persistent `queue:work` systemd unit picks them up instantly, with the scheduled drain as a fallback. The scheduler also runs the recurring TOC/chapter/verify/email tasks.
- **Chapter body text is stored separately** (`chapter_texts`, one row per downloaded chapter with a `FULLTEXT` index). The main `novel_chapters` table holds only metadata, keeping every list/stat query and schema change fast. On the `NovelChapter` model, `description` remains a virtual attribute backed by that table.
- **Settings are DB-backed** (`app_settings` table) with an `.env` fallback, so most operational config is editable from the Settings page without redeploying. (Runtime code reads env only via `config/` — the app is safe to run with `config:cache`/`route:cache`/`view:cache`.)

---

## Supported sources

| Adapter | Site | Notes |
|---|---|---|
| `EmpireNovelSource` | `empirenovel.com` | Paginated TOC via FlareSolverr + cookie reuse |
| `NovelFullSource` | `novelfull.com` | AJAX chapter-list endpoint |
| `NovelArrowSource` | NovelPing — `novelping.com` (formerly `novelarrow.com`, which now redirects there; both hosts are recognised) — and the **default** fallback | JSON api-web chapter list or page parse; browse/search results also carry a synopsis. Every outgoing URL (API, chapter pages, `images.{host}` covers) is built on `NOVARR_NOVELARROW_HOST` (config `novarr.novelarrow_host`, default `novelping.com`); migration `2026_10_04_000001` rewrote stored novelarrow URLs |
| `SuwayomiSource` | Manga through your own [Suwayomi-Server](https://github.com/Suwayomi/Suwayomi-Server) (off until `NOVARR_SUWAYOMI_URL` is set) | Local GraphQL API, no account; see [Suwayomi (manga)](#suwayomi-manga) |

Metadata for all sources is enriched from **NovelUpdates** (description, genres, cover, completion status), with the source's own page as a fallback.

### Suwayomi (manga)

`SuwayomiSource` downloads manga through a Suwayomi-Server you run yourself: Suwayomi runs the Tachiyomi/Mihon extensions and fetches from the sites, Novarr only talks to its local API (`POST {url}/api/graphql`, page images from `{url}/api/v1/…`). A series is added by its Suwayomi web-UI URL, `{url}/manga/{id}` (Discover builds it for you); the manga does not have to be in Suwayomi's library.

| Variable | Meaning | Default |
|---|---|---|
| `NOVARR_SUWAYOMI_URL` | Base URL of the server, e.g. `http://192.168.1.37:4567`. Empty = the source is off: it claims no URL, lists nothing on Discover and never makes a request | empty |
| `NOVARR_SUWAYOMI_LANGUAGES` | Comma list of Suwayomi source languages offered on Discover | `en` |
| `NOVARR_SUWAYOMI_EXCLUDE_SOURCES` | Comma list of Suwayomi source names left off Discover (case-insensitive) — Novarr has its own MangaDex source | `MangaDex,Local source` |

Optional: `NOVARR_SUWAYOMI_TIMEOUT` (seconds per API call, default `90` — a chapter list makes Suwayomi fetch from the site and can take a minute). On Discover every remaining Suwayomi source is its own entry ("Suwayomi · MangaPill", key `suwayomi:{source id}`) with Search and Popular; covers are served through Novarr (`/discover/cover`), so the browser never needs to reach the server. All Suwayomi series share the server's host, so the sweep's per-host pace and cooldowns apply to them together.

### Adding a source

A source is one adapter class plus one config entry; the resolver, the TOC and chapter generators and the Discover page all read the registry, so no other file needs editing.

1. **Write an adapter** in `app/Sources` extending `App\Sources\AbstractSource`. Implement `name()`, `tableOfContents(Novel)` (TOC rows from `generateTocChapterInfo($label, $url)`; the caller runs `finalizeTocResult()`) and `metadata(Novel)` (`description`, `author`, `no_of_chapters`, `image`, `genres`, `cover_candidates`). Override `hosts()` so the default `matches()` / `matchesUrl()` recognise your URLs (a URL containing one of the hosts). Optional overrides, all with defaults in `AbstractSource`:
   - `contentSelectors()`, `removeSelectors()`, `supportsMultiPage()` — chapter-extraction hints (where the body is, what to strip, a "next part" link selector or `null`).
   - `chapterContent($url, &$failure)` — an API fast path for chapter text. Return `null` for "no fast path for this URL" (the default: the page is fetched and extracted), or the paragraphs (`[]` plus a `$failure` reason when the API gave nothing; `'rate_limited'` stops the fetch, anything else falls back to the page). `chapterGenerator()` asks the source that claims the chapter URL first, then every other registered source in registry order.
   - `siteMetadata(Novel)` + a `METADATA_PRECEDENCE` constant to merge your site's metadata with NovelUpdates' (`novelUpdatesMetadata()`) through `App\Sources\MetadataMerger::merge()` — the precedence keys are documented on that class; the three built-in adapters are worked examples.
   - Discover: `discoverLists()` (any of `search`, `popular`, `completed`; the default `[]` keeps the source off the Discover page), `discover($type, $query)` (result items, or `null` when the site can't be reached), `discoverLabel()`, `discoverHost()`, and `librarySlug()` for "In library" matching by slug. Sources with browse lists are listed first, then search-only ones.
2. **Register it** in `config/novarr.php` under `sources` (key => class). Order matters: the first adapter whose `matches()` accepts a novel wins, and `NovelArrowSource` accepts everything, so it stays **last** as the catch-all. The key is the source's `?source=` id on the Discover page.
3. **Add fixtures and a golden test**: save real pages under `tests/fixtures/{yoursource}/` and add a `tests/Feature/Golden{YourSource}Test.php` extending `GoldenTestCase`, which serves them through `Tests\Support\FakeFetcher` (any un-faked URL fails the test). All network access must go through `App\Scraping\Fetcher` (`html()` via FlareSolverr, `sessionPage()`, `page()`, `plain()`, `json()`) for that to work. Refresh expectations with `GOLDEN_UPDATE=1 php artisan test --filter Golden` and review the diff.

```php
namespace App\Sources;

use App\Novel;
use App\Scraping\Fetcher;
use Symfony\Component\DomCrawler\Crawler;

class ExampleSource extends AbstractSource
{
    public function name(): string { return 'example'; }

    public function hosts(): array { return ['example-novels.com']; }

    public function tableOfContents(Novel $novel): array
    {
        $html = app(Fetcher::class)->html($novel->translator_url);
        $rows = [];
        if ($html !== null) {
            (new Crawler($html))->filter('.chapter-list a')->each(function (Crawler $a) use (&$rows) {
                $rows[] = generateTocChapterInfo($a->text(), $a->attr('href'));
            });
        }

        return $rows;
    }

    public function metadata(Novel $novel): array
    {
        // NovelUpdates only; see MetadataMerger to merge your own page in.
        $metadata = $this->novelUpdatesMetadata($novel);
        $metadata['cover_candidates'] = array_values(array_filter([$metadata['image'] ?? null]));

        return $metadata;
    }

    public function contentSelectors(): array
    {
        return ['#chapter-body', ...self::GENERIC_CONTENT_SELECTORS];
    }
}
```

```php
// config/novarr.php
'sources' => [
    'empirenovel' => \App\Sources\EmpireNovelSource::class,
    'novelfull' => \App\Sources\NovelFullSource::class,
    'example' => \App\Sources\ExampleSource::class,
    'novelarrow' => \App\Sources\NovelArrowSource::class, // catch-all, keep last
],
```

`tests/Feature/NewSourceDryRunTest.php` registers a fourth adapter through config alone and checks the resolver, TOC generator, chapter fast path and Discover pick it up.

**Install-specific ids.** A few database ids from the original install are config, not code (defaults = the original install; override with comma-separated env lists, empty = none): `alternative_url_group_ids` (`NOVARR_ALTERNATIVE_URL_GROUP_IDS`, `1,3,6`: groups whose novels' `alternative_url` builds chapter URLs), `dotted_chapter_url_novel_ids` (`NOVARR_DOTTED_CHAPTER_URL_NOVEL_IDS`, `72`: novels whose `alternative_url` chapter number is written `12-5` instead of floored), `novelarrow_api_group_ids` (`NOVARR_NOVELARROW_API_GROUP_IDS`, `1`: groups whose NovelPing TOC comes from the JSON API) and `webnovel_unique_id_group_ids` (`NOVARR_WEBNOVEL_UNIQUE_ID_GROUP_IDS`, `6`: webnovel groups whose TOC entries get a `unique_id` from the URL).

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
| `NOVARR_SUWAYOMI_URL` / `_LANGUAGES` / `_EXCLUDE_SOURCES` | Manga through your own Suwayomi-Server (see [Suwayomi (manga)](#suwayomi-manga)); empty URL = off | — / `en` / `MangaDex,Local source` |
| `NOVARR_KOREADER_PLUGIN_PATH` | Folder served to KOReader devices as the plugin update (see [Apps and JSON API](#apps-and-json-api)) | `koreader/novarr.koplugin` |
| `TRUSTED_PROXIES` | Proxies whose `X-Forwarded-*` headers are trusted (nginx / Tailscale / the Cloudflare Tunnel connector in front) | `*` |
| `NOVARR_PUBLIC_HOSTS` / `NOVARR_PUBLIC_URL` | Hostnames published on the internet (see [Publishing on the internet with a Cloudflare Tunnel](#publishing-on-the-internet-with-a-cloudflare-tunnel)); empty = off | — / `https://` + first host |
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

Jobs queued from the web UI use the **database** queue (`jobs` table); failures land in `failed_jobs` and are inspectable/retryable from the **Health** page. For instant pickup, run a **persistent worker** alongside the cron (on bare metal use a systemd unit; the Docker stacks rely on the scheduler's every-minute drain):

```ini
# /etc/systemd/system/novarr-worker.service
[Service]
User=www-data
WorkingDirectory=/path/to/novarr
ExecStart=/usr/bin/php artisan queue:work --queue=commands,default --sleep=1 --tries=1 --timeout=3600 --max-time=3600
Restart=always
```

One worker runs one job at a time; the scheduler's every-minute drain picks up what waits behind a long job within a minute. For instant pickup of a second job, run a second copy of the unit (e.g. `novarr-worker-2.service`, same contents): jobs are claimed atomically from the `jobs` table and each novel's scrape is guarded by its own lock, so workers never share a job or a novel.

Run `php artisan queue:restart` after each deploy so the worker reloads new code. Scheduled scrapes hold `withoutOverlapping` locks of 90 min (TOC) and 150 min (chapters) — short enough that a killed run can't block the next day — and every `novel:chapter` and `novel:toc` run, scheduled or UI-triggered (the reader's single-chapter download excepted), takes a per-novel lock (`novel-scrape:{id}`, released when the run ends, with a 3-hour expiry that long manual runs renew) before working a novel and skips any novel whose lock another run holds, so two scrapes never work the same novel at once.

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

## Apps and JSON API

Two readers besides the website live in this repository. Both talk to the server through the JSON API and share reading position with the website. Like the rest of Novarr, the API has **no login**: keep the server on your own network or tailnet.

### JSON API (`/api/v1`)

| Endpoint | What it does |
|---|---|
| `GET /meta` | App id, API version and server version; the apps use it to check an address is Novarr |
| `GET /home` | The novel being read and the shelves of the home screen |
| `GET /novels`, `GET /novels/{id}` | The library and one novel's details |
| `GET /novels/{id}/chapters` | A novel's chapters in reading order |
| `GET /chapters/{id}` | One chapter (paragraphs and sanitised HTML); never marks it read |
| `POST /chapters/{id}/progress`, `POST /chapters/{id}/read`, `POST /chapters/bulk-read` | Reading position and read marks |
| `GET /discover`, `GET /discover/browse` | The sources with their lists, and one page of a list or search (`type`, `source`, `q`, `page`) |
| `POST /discover/searches`, `GET /discover/searches/{id}` | The same browse or search run in the background (after the response is sent), then fetched by id (kept 15 minutes) |
| `POST /novels` | Add a novel from a Discover result (`title`, `url`) |
| `POST /novels/{id}/check`, `GET /novels/{id}/check` | Ask the server to check a novel's source for new chapters, and follow that check |
| `POST /novels/{id}/epub`, `GET /novels/{id}/epub` | Build a novel's ePub and download it |
| `GET /highlights`, `POST /highlights`, `DELETE /highlights/{id}` | Highlights (the website's bookmarks: an excerpt with an optional note), newest first, per novel with `novel_id` |
| `GET /koreader/plugin`, `GET /koreader/plugin/files/{path}` | The KOReader plugin's update manifest and files |

List endpoints answer conditional requests (`ETag` / `If-None-Match`). A Discover search can take a minute or two when the source sits behind FlareSolverr. A check runs on the queue (`novel:toc`, then `novel:chapter`), so it needs the queue worker or the scheduler's drain. A background Discover search does not use the queue.

### iPhone / iPad app (`ios/`)

A native SwiftUI app (iOS 17+): connect to the server by address, then Home, Library, Discover (browse or search the sources and add novels), novel pages with **Check for new chapters**, the reader, Highlights (per novel and across the library) and Settings. The Xcode project is generated from `ios/project.yml` with [XcodeGen](https://github.com/yonaskolb/XcodeGen); open `ios/Novarr.xcodeproj` to build and run, and `NovarrTests` holds its tests.

### KOReader plugin (`koreader/`)

For a Kindle (jailbroken, with KOReader) or any other KOReader device. Copy `koreader/novarr.koplugin` into the device's `koreader/plugins/` folder once, enter the server address, and read. What it does:

- **Home shelf** with covers; one tap opens the chapter you are on.
- **Joined chapters** — chapters are read in groups of 20, so a chapter change is a plain page turn.
- **100 chapters saved ahead**, and **Wi-Fi switched off** while reading once everything is saved; it comes back by itself to top up and to send progress.
- **Wi-Fi back on when you leave** — closing the book or exiting KOReader switches Wi-Fi on again when Novarr had switched it off; Settings shows how much of the reading time had Wi-Fi on.
- **Discover** — browse or search the sources and add novels from the device; searches run in the background on the server, so the device stays usable.
- **Check for new chapters** when you have caught up, and **reading stats** on the novel page.
- **Highlights** — text highlighted in KOReader (with its note) is sent to the server and appears on the website's Highlights page and in the iPhone app; the novel page lists them.
- **Two server addresses** (home and away, a Tailscale name for example), and `https://` through KOReader's HTTP proxy.
- **Updates over Wi-Fi** — the server serves the plugin folder it was deployed with, so deploying Novarr is how a plugin update reaches the device (Novarr ▸ Settings ▸ version row, or automatically with "Install plugin updates automatically"). The Docker image includes the folder; `NOVARR_KOREADER_PLUGIN_PATH` points it elsewhere.

The full guide, including install, settings and troubleshooting, is [koreader/README.md](koreader/README.md). Development: `cd koreader && make test KOREADER_DIR=<path to a KOReader install>` runs the unit tests and `make package` builds `novarr-koplugin.zip`.

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

## Deployment

Three supported ways to run Novarr:

| Path | Use it when | Details |
|---|---|---|
| **One-click stack** (`docker-compose.oneclick.yml`, or `docker-compose.tailscale.yml` for your tailnet) | Almost always: prebuilt image, nothing to build | [Quick install](#quick-install-one-command), [Tailscale](#tailscale), [DOCKER.md](DOCKER.md) |
| **Bare metal** (`deploy.sh`) | A plain Linux box with nginx, PHP-FPM and MariaDB/MySQL | Below |
| **Clone-and-run stack** (`docker-compose.yml`) | You want to build the image from the source yourself, run on ARM, or change the code | Below, [DOCKER.md](DOCKER.md) |

Unraid: [UNRAID_DEPLOYMENT.md](UNRAID_DEPLOYMENT.md).

### Bare metal (`deploy.sh`)

On a plain Linux box the repo's `deploy.sh` is the whole release process. It:

1. re-executes itself from a temp copy (so the pull can't change the running script);
2. **dumps the database** into `storage/backups/` (five copies kept, written through a private credentials file and verified with `gzip -t`) and refuses to continue if the dump fails — nothing has changed yet;
3. records the current commit and that dump in `storage/backups/last-deploy.state` (the rollback point);
4. enters maintenance mode, printing a bypass secret;
5. pulls with `--ff-only`, installs dependencies (`composer install --no-dev`, `yarn install --frozen-lockfile`), builds assets;
6. migrates from a cleared config, then clears and rebuilds the config/route/view caches one by one, without touching the application cache (so scheduler locks survive);
7. restarts the queue worker, normalises `storage/` and `bootstrap/cache` ownership to `www-data`, and reloads any active `php*-fpm` service (opcache may hold the old code);
8. leaves maintenance mode and **probes `/api/health`** (sent to `127.0.0.1` with the `APP_URL` host, or to `NOVARR_HEALTH_URL` if set). An unhealthy answer makes the script exit non-zero with the response; it does not roll back on its own.

**If a step fails** the script stops (the site stays in maintenance mode if it was) and prints an exact rollback recipe: the commit to reset to, the same `composer`/`yarn` commands, the dump to load (only when migrations had started), the cache rebuild and `php artisan up`. To run that recipe for you:

```bash
/var/www/novarr/deploy.sh --rollback          # shows what it will do, asks for "rollback"
/var/www/novarr/deploy.sh --rollback --yes    # no prompt
```

`--rollback` resets the code to the recorded commit, reinstalls dependencies, rebuilds assets, **replaces the database with the recorded dump** (anything written since is lost; tables are dropped first with `db:wipe`), rebuilds caches, brings the site up and probes it. It works after a failed deploy and after a successful one, once per deploy. The next `deploy.sh` run pulls `master` again, so fix forward before re-deploying.

The script needs `mysqldump`/`mariadb-dump` and the `mariadb`/`mysql` client (`apt install mariadb-client`), plus `curl` for the health probe. Put immutable caching on `/build/assets/` and `no-cache` on `/sw.js` and `/build/manifest.json` in your nginx site (see `docker/nginx/nginx.conf` for the blocks).

### Clone-and-run stack (`docker-compose.yml`)

The same services as the [one-command install](#quick-install-one-command) (app, one-shot migration, scheduler, MySQL, Redis, FlareSolverr), but the image is built from your checkout instead of pulled:

```bash
git clone https://github.com/RaihaX/novarr.git && cd novarr
docker compose up -d --build        # or: make deploy
```

Open **http://localhost/**. There is nothing to configure: the app key is generated on first start and kept in the `storage` volume, migrations run in the `init` service before the app starts, and all data lives in named volumes (`docker compose down` keeps it, `docker compose down -v` deletes it). The image builds for the machine it is built on, so this also works on Apple Silicon and ARM boards.

Optional settings come from the shell or a `.env` file next to the compose file:

| Variable | Default | Purpose |
|---|---|---|
| `NOVARR_PORT` | `80` | Host port |
| `APP_URL` | `http://localhost` | The origin you browse to (set it when behind HTTPS) |
| `APP_TIMEZONE` | `UTC` | Timezone for schedules and dates |
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | internal defaults | Only applied when the MySQL volume is first created; the database port is never published |
| `MAIL_MAILER`, `RESEND_KEY`, `MAIL_FROM_ADDRESS` | mail off | Daily summary and Send-to-Kindle |
| `NOTIFICATION_WEBHOOK_URL` | none | Failure alerts |

Update with `git pull --ff-only && docker compose up -d --build` (take a backup first: `make backup`). The `Makefile` wraps the common commands (run `make help` for the full list):

| Command | Description |
|---|---|
| `make deploy` | Build the image and (re)start the stack; migrations run in `init` |
| `make logs` / `make logs-app` | Tail logs (all, or a single service) |
| `make shell` / `make tinker` | App container shell / Tinker REPL |
| `make db-shell` | MySQL shell |
| `make backup` / `make backup-list` | `php artisan backup:run` / `backup:list` in the app container |
| `make health` / `make smoke-test` | Health endpoints / smoke tests |
| `make restart` / `make down` / `make up` | Lifecycle control |
| `make dev` | Adds `docker-compose.dev.yml`, which bind-mounts the checkout for live editing |

The production image has no Composer, Node or dev dependencies: run tests and linting on a development machine (see [Development](#development)).

- **Full Docker guide:** [DOCKER.md](DOCKER.md)
- **Unraid guide:** [UNRAID_DEPLOYMENT.md](UNRAID_DEPLOYMENT.md)
- **Moving a library to a new install:** take a backup and restore it there (see [Backup and restore](#backup-and-restore)).

For PWA installs you'll want HTTPS in front of the stack — terminate TLS at your reverse proxy (or Tailscale Serve) and point `APP_URL` at the HTTPS origin.

### Continuous integration

`.github/workflows/docker-publish.yml` runs on every push and pull request to `master`. The **test** job installs PHP 8.3 (the image's extensions) and the locked Composer and Yarn dependencies, builds the assets, then runs `php artisan test --fail-on-incomplete` and `yarn test:js`; `composer audit` and `yarn audit` run as advisory steps that never fail the build. A second leg runs the PHP suite against a `mariadb:10.11` service, so migrations run on the production engine; it is allowed to fail for now. Only after the tests pass does the **build** job (pushes and tags only, not pull requests) publish `ghcr.io/raihax/novarr`.

### Publishing on the internet with a Cloudflare Tunnel

Novarr can be reached from the internet at one hostname (say `read.example.com`) through a [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/): Cloudflare terminates HTTPS and a `cloudflared` connector on your LAN forwards plain HTTP to nginx, keeping the public `Host` header and adding `X-Forwarded-Proto: https` and `X-Forwarded-For`. The LAN and Tailscale addresses keep working as before.

1. Point the tunnel's public hostname at nginx (`http://<novarr-box>:80`) and turn on **Always Use HTTPS** for the zone.
2. Turn sign-in on (Settings → Users, or `php artisan novarr:auth on`).
3. In `.env`:

   ```dotenv
   NOVARR_PUBLIC_HOSTS=read.example.com
   # optional, default https:// + the first host:
   # NOVARR_PUBLIC_URL=https://read.example.com
   # the connector's address (127.0.0.1 if cloudflared runs on the Novarr box;
   # keep 127.0.0.1 in the list if Tailscale Serve proxies to it):
   TRUSTED_PROXIES=192.168.1.50,127.0.0.1
   ```

   then `php artisan config:cache` (deploy.sh does it) and check `php artisan novarr:auth status` — it shows the public address and warns while sign-in is off.

What changes **on the public address only**:

- **No admin features, even for the admin.** Every admin page and API endpoint answers 403 "Admin features are only available on your home network."; admin buttons, Home cards and palette commands are hidden. The admin can sign in there and read like any reader. Do admin work (adding series, settings, users, invites) from the home network or Tailscale.
- **Sign-in is required.** With sign-in off every page except sign-in, health and the invite/app-download/pairing endpoints answers 403 "This address is only available once sign-in is turned on.", and `/api/v1/meta` reports `auth.required: true`.
- **HTTPS only:** links are generated as `https://read.example.com/…`, session and remember-me cookies are `Secure`, and responses carry `Strict-Transport-Security: max-age=31536000`.
- **Not for search engines:** `X-Robots-Tag: noindex, nofollow, noarchive` on every response (and `robots.txt` disallows everything on every host), plus `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `X-Frame-Options: DENY`.
- **Tighter limits:** on top of the per-name sign-in limit, at most 20 credential attempts per IP per 10 minutes across the web login, `POST /api/v1/auth/token`, OPDS (HTTP Basic) and invite acceptance (429 with `Retry-After`). This relies on `TRUSTED_PROXIES` naming the connector, so the visitor's address (from `X-Forwarded-For`) is the one counted. `CF-Connecting-IP` is not used.

Invite links and the Android-app link always use the public address once it is set, whichever address you send the invite from.

---

## Backup and restore

### What is backed up

`php artisan backup:run` ([spatie/laravel-backup](https://spatie.be/docs/laravel-backup), configured in `config/backup.php`) writes one zip containing:

- `db-dumps/mysql-<database>.sql` — a dump of the database (`mariadb-<database>.sql` for a `mariadb` connection, `sqlite-….sql` for SQLite);
- `app/…` — `storage/app`: covers (with their thumbnails) and uploads, settings files, and the Docker stacks' `storage/app/.appkey`.

Left out: the backups themselves, `storage/app/backup-temp`, failure snapshots (`storage/app/snapshots`), generated ePubs (`storage/app/ePub`: rebuild them with `php artisan novel:epub`), ePub build scratch directories, framework caches and logs. Code, `vendor/` and `node_modules` come back from git and the lockfiles; **back up `.env` yourself** (it holds `APP_KEY` and passwords).

The scheduler runs `backup:clean` at 03:30 and `backup:run` at 03:45 every day. Retention: one backup a day for 7 days, then one a week for 4 weeks, then one a month for 3 months (the newest is never deleted). Nothing is mailed; a failed run is logged and posted to the notification webhook (Settings, or `NOTIFICATION_WEBHOOK_URL`).

The dump needs the MySQL/MariaDB client tools where the backup runs: `apt install mariadb-client` on bare metal (provides `mysqldump` and `mariadb-dump`); the Docker image already includes them.

`deploy.sh` keeps its own pre-deploy database dumps in `storage/backups/` (last five) for rollbacks; those are separate from these.

### Where backups land

Zips go to the filesystem disk named by `BACKUP_DISK` (default `local`), under `<disk root>/<APP_NAME>/`:

| Install | Default location |
|---|---|
| Bare metal | `/var/www/novarr/storage/app/private/Novarr/` |
| Docker stacks (one-click, Tailscale, clone-and-run) | `storage/app/private/Novarr/` inside the `storage` volume (`docker compose exec scheduler ls storage/app/private/Novarr`) |

```bash
php artisan backup:run      # back up now
php artisan backup:list     # list backups, age, size
# Docker: docker compose -f docker-compose.oneclick.yml exec scheduler php artisan backup:run
```

### Sending backups to a second disk

The default location is on the same disk as the data. To keep copies elsewhere:

- **Bare metal:** mount the second disk (or a directory on it) over the backup folder, so the zips are written there directly:
  ```bash
  mkdir -p /mnt/backup/novarr /var/www/novarr/storage/app/private/Novarr
  chown www-data:www-data /mnt/backup/novarr
  echo '/mnt/backup/novarr /var/www/novarr/storage/app/private/Novarr none bind 0 0' >> /etc/fstab
  mount /var/www/novarr/storage/app/private/Novarr
  ```
  Or copy them off after each run, e.g. a root cron line `15 4 * * * rsync -a --delete /var/www/novarr/storage/app/private/Novarr/ /mnt/backup/novarr/`.
- **Docker:** mount a host directory over the same folder in the `scheduler` service (owned by uid/gid 82, the image's `www-data`) — see [DOCKER.md → Backups](DOCKER.md#backups).

### Restore (bare metal)

```bash
cd /var/www/novarr
php artisan down
mkdir -p /tmp/novarr-restore
unzip -o storage/app/private/Novarr/<backup>.zip -d /tmp/novarr-restore

# Database: drop every table, then load the dump (the client prompts for DB_PASSWORD).
php artisan db:wipe --force
mariadb -h <DB_HOST> -u <DB_USERNAME> -p <DB_DATABASE> < /tmp/novarr-restore/db-dumps/mysql-<DB_DATABASE>.sql

# Files: storage/app as it was.
rsync -a /tmp/novarr-restore/app/ storage/app/
chown -R www-data:www-data storage

php artisan migrate --force   # only if the code is newer than the backup
php artisan up
rm -rf /tmp/novarr-restore
```

### Restore (Docker one-click / Tailscale stack)

Run from the directory with the compose file (`-f docker-compose.tailscale.yml` for the Tailscale stack; for the clone-and-run stack leave `F` empty). Copy the zip out of the volume first if that is where it is (`docker compose -f docker-compose.oneclick.yml cp scheduler:/var/www/html/storage/app/private/Novarr/<backup>.zip .`).

```bash
F="-f docker-compose.oneclick.yml"
mkdir -p restore && unzip -o <backup>.zip -d restore

docker compose $F stop app scheduler

# Database
docker compose $F run --rm --no-deps app php artisan db:wipe --force
docker compose $F exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"' \
  < restore/db-dumps/mysql-novarr.sql

# Files (including storage/app/.appkey, so the restored data keeps its key)
docker compose $F run --rm --no-deps --user root -v "$PWD/restore:/restore:ro" app \
  sh -c 'cp -a /restore/app/. /var/www/html/storage/app/ && chown -R www-data:www-data /var/www/html/storage/app'

docker compose $F up -d          # init re-runs migrations first
docker compose $F exec app php artisan up
rm -rf restore
```

If `BACKUP_ARCHIVE_PASSWORD` is set, the zips are AES-encrypted: extract them with `7z x <backup>.zip -orestore` (p7zip) instead of `unzip`.

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
│   │                      #   Log, Command, Tailscale; Api/V1/ (JSON API: Library,
│   │                      #   Chapter, Discover, AddNovel, Check, Epub, KoreaderPlugin)
│   ├── Helpers.php        # Scraping/TOC helpers, metadata, Kindle helpers
│   └── Middleware/        # PerformanceMonitoring (CSRF exemptions live in bootstrap/app.php)
├── Jobs/                  # RunNovelCommand (queued Artisan command runner, per-novel lock,
│                          #   3600s timeout), FinishNovelCheck (closes an API-started check)
├── Scraping/              # Fetcher (all network I/O), ContentExtractor, ChapterLabelParser
│                          #   (+ ChapterLabel DTO), NovelUpdatesMatcher, FailureSnapshot,
│                          #   OriginInference (translated vs original)
├── Services/              # NovelHealth (needs-attention: scrape, stall, TOC branches; progress),
│                          #   DefaultCoverGenerator (brand fallback covers), ChapterNumberResolver,
│                          #   CoverThumbnails, Discover (source lists/search for web + API),
│                          #   NovelCheck (on-demand check for new chapters)
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
├── api.php                # JSON API v1 (apps)
└── console.php            # Scheduler definitions
tests/
├── Feature/ · Unit/       # PHP suite (incl. Golden*Test adapters-over-fixtures)
├── fixtures/              # Saved source pages + expected JSON per adapter
├── js/                    # node --test suites: offline queue, service worker, palette, perf
└── Support/FakeFetcher    # Network fake that fails any un-faked URL
ios/                       # iPhone/iPad app (SwiftUI; project.yml → Novarr.xcodeproj)
koreader/                  # KOReader plugin: novarr.koplugin/ (served for Wi-Fi updates),
                           #   spec/ (unit tests), Makefile, README
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

`GOLDEN_UPDATE=1 php artisan test --filter Golden` regenerates the adapter expectations (and marks those tests incomplete; CI runs with `--fail-on-incomplete`, so the flag can never make CI green). Tests flush the cache per test; `phpunit.xml` pins the array cache/session drivers.

For remote/tablet access to the Vite dev server (e.g. over Tailscale), the dev assets must be advertised at the externally-reachable HTTPS origin — set `server.origin`, `server.allowedHosts`, and `server.hmr` in `vite.config.js` from `.env`, and confirm `public/hot` shows the external URL.

**Tech stack:** Laravel 12 · PHP 8.3+ · MySQL 8 / MariaDB · Bootstrap 5.3 under the Novarr design system · Hotwire Turbo · Vite · self-hosted fonts (Geist, Geist Mono, Literata via Fontsource; Atkinson Hyperlegible loaded on the reader as the "Legible" option) · Lucide icons (inlined) · FlareSolverr · Resend · GD (cover rendering) · PWA (service worker + IndexedDB) · Swift 6 / SwiftUI (iOS app) · Lua (KOReader plugin).

---

*Novarr is a personal, single-user, self-hosted tool. Scrape responsibly and respect the source sites' terms and rate limits.*
