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
- [Accounts and sign-in](#accounts-and-sign-in)
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
- **No duplicate series** — adding a series that is already in the library is refused, even when it comes from a different source: titles are compared with punctuation, case and tags such as "(Novel)" or "[Official]" ignored. Discover marks results you already have, and the add form, the command runner, `novel:create` and the API all apply the same check (each has an explicit override).
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

### Books (ePub uploads)
- **A third library, Books**, beside Novels and Manga, for books you have as ePub files (from Calibre, a publisher or a store without DRM).
- **Any reader can upload** an ePub (Requests → *Upload a book*, or *+ Upload a book* in the Books library). It is checked at once: a file that is not a readable ePub, a file already in the library or one already waiting is refused there and then. Nothing reaches the library before the owner approves it.
- **The owner is emailed** a link to the book's **review page** (the owner's own upload goes straight there). The page shows what the file says about itself (title, author, ISBN, language, publisher, series, chapter list, size) and an excerpt of how it begins, so a "dump" ePub with a junk title or no metadata can be told for what it is. Beside it are catalogue matches from **Google Books and Open Library** (English editions first), with a search by title, author or ISBN to find the right book. Choosing a match fills in the title, author, description, genres and cover, all editable; the cover can be the match's, the file's own or none. **Add to the library** imports it (a different book with the same title needs *add anyway*); **Decline** (with a reason) removes the file. The reader is told either way.
- **Read like everything else**: the book is cut into chapters along its own table of contents (EPUB 3 nav or EPUB 2 NCX; a contents entry pointing into the middle of a file starts a chapter there; title, copyright and contents pages are dropped; a near-empty part page joins the chapter after it). The web reader, reading position across devices, highlights, stats, full-text search, the apps and the KOReader plugin all work on it. Text only: pictures inside a book are left out.
- **The original file is kept** (`storage/app/books/{id}.epub`) and is what *Download ePub*, Send to Kindle and the OPDS catalogue hand out; nothing is regenerated. A book arrives complete, so it is never scraped, never counted as new chapters (Home, Activity, the daily email, the apps' updates) and never flagged for attention.

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

### Accounts & sharing
- **Optional sign-in** — off by default (everyone on your network is the owner). Turn it on and every page and API call needs an account.
- **One admin, invited readers** — the owner manages the library; readers can read everything, check a series for new chapters, download ePubs and see their own stats, and nothing else.
- **Each reader has their own progress** — read marks, reading position, highlights and stats are kept per account.
- **See what your readers read** — System → **Readers** (admin, home network only) shows who is reading right now, each account's chapters today, this week and this month, their streak and last read, the most popular series, a stacked chart of chapters per day by reader, and the full reading history, with the device and address each chapter was read from. Highlights stay private.
- **Invite by email** — type a name and an email; the person opens the link, picks a password and is signed in. The email also links to the Android app when one is published.
- **Link a Kindle with a code** — the KOReader plugin shows a 6-character code that you enter on the website; no typing passwords on an e-reader.
- **Sign-in history and alerts** — every sign-in, failed attempt and account change is logged (Settings → Users → Sign-in history); each reader sees their own recent sign-ins and signed-in browsers (each one can be signed out on its own), and gets an email when their account is signed in to from a new device.
- **A second step on the public address** — after the password, a passkey, a code from an authenticator app, an emailed code or a recovery code; "Sign in with a passkey" does it in one step. The home network keeps the password alone.
- **Catalogue passwords** — e-reader apps browse OPDS with a generated password of their own, never the account password, on the public address.
- **A daily summary for each reader** — new chapters and completed series across the library, emailed once a day; each reader can turn it off.
- **Reader requests** — readers search every source for a novel or manga and request it (or tap **Me too** on someone else's request); the owner approves or declines. The Requests page also has a **Discover manga** shelf: MangaDex's Popular, New & popular (most followed of the last 30 days) and Top rated lists, ten pages each, with the series already in the library left out. A request made from the shelf then looks the title up on every other manga source for its shortlist.
- See [Accounts and sign-in](#accounts-and-sign-in).

### Apps & API
- **JSON API** at `/api/v1` — library, chapters, reading progress, Discover, adding novels, on-demand checks for new chapters and ePub builds. See [Apps and JSON API](#apps-and-json-api).
- **iPhone / iPad app** (`ios/`) — a native SwiftUI reader: sign-in, home, library, novel pages, reader and settings, with reading position shared with the website.
- **Android app** (`android/`) — a native Compose reader for novels and manga: scroll or page-turn reading, offline downloads and the same shared reading position. The server can hand the APK out at `/app/android`.
- **KOReader plugin** (`koreader/`) — read on a Kindle or any KOReader device straight from the server: a cover-shelf home, chapters joined into groups so chapter changes are plain page turns, 100 chapters saved ahead with Wi-Fi switched off in between, a quick menu while reading (hold the status bar) and gestures for the chapter list, novel page and home, reading info at the bottom or the top of the page, Discover and "check for new chapters" on the device, reading stats, a second server address for away from home, and plugin updates over Wi-Fi.

### Operations
- **Settings UI** — FlareSolverr URL, notification webhook, Kindle email, scrape delays, summary-email time, minimum chapter words, chapters per novel per run, run-time bound, NovelUpdates match threshold and snapshot retention — each with its default and effective value, plus one-click **test** buttons. The Tailscale **Funnel** switch is red, asks before enabling, and shows an app-wide banner while on.
- **System health** — scheduler heartbeat, queue status, FlareSolverr reachability, failed-job inspection/retry/cleanup, and the **Needs attention** panel with Snooze (7 days) / Wake. **Activity** lists missing chapters and recent downloads; the home status strip links to both.
- **Log viewer** — live tail, download, clear, delete; logs rotate daily with 14-day retention.
- **Command runner** — execute whitelisted Artisan commands from the web UI with async job-status polling; a **persistent queue worker** picks jobs up instantly.
- **Daily email** — one message a day covering everything since the last one was sent (not a fixed 24 hours): where you left off with a Resume link, five figures (chapters downloaded, novels updated, queued, completed, needing attention), one row per novel with the chapter range and a Read link (capped at 25, with the remainder counted), newly completed novels, and an attention list that is only repeated when it changes. Plain-text alternative included; nothing is sent when there is nothing to say.
- **Stalled-source alert** — an hourly check emails you when a source has stopped delivering chapters, without waiting for the daily email (`novel:email-summary --stalled-only`).
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
| `NovelFireSource` | `novelfire.net` | Plain HTTP (FlareSolverr only if refused). TOC from `/book/{slug}/chapters?page=N&sort_by=desc`, 100 rows a page, with the incremental early stop; chapters fetched plain, retried when the site diverts a request to its partner site. Metadata from the book page, NovelUpdates fills gaps. Discover: search only |
| `RoyalRoadSource` | `www.royalroad.com` | Whole TOC from the fiction page's `window.chapters` JSON (table as fallback), one request; `chapter` = position in the site's list (titles are free text). Identity is the fiction id. Metadata from the fiction page only (no NovelUpdates). Hidden anti-piracy lines stripped via `PageNoiseSelectors`. Discover: search only |
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

**Chapters a manga's source keeps failing on** are downloaded from the same series on another installed source (`manga:fill-missing`, hourly; `NOVARR_MANGA_FALLBACK_ENABLED=false` turns it off). The series is matched by exact title and the chapter by number; a chapter much shorter than the series' usual length is refused unless two sources list the same page count for it (a short extra). A chapter no source can provide (none lists its number, or none could deliver it) is set aside until the next weekly search: nothing retries it meanwhile, the series is no longer counted as failing, and it shows as **Unavailable** on the series page and under *Not available on the source* on the Health page. `php artisan manga:fill-missing --novel=<id> --refresh` searches the other sources again now; `--dry-run` only lists what it would do.

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
| `NOVARR_REQUESTS_BROWSE` / `_BROWSE_CACHE_MINUTES` | The Requests page's Discover manga shelf (`false` = off), and how long each page of its lists is kept | `true` / `360` |
| `NOVARR_BOOKS_MAX_UPLOAD_MB` | Largest ePub a reader can upload (PHP's `upload_max_filesize` / `post_max_size` and nginx's `client_max_body_size` must allow it) | `50` |
| `NOVARR_BOOKS_LOOKUP` / `NOVARR_GOOGLE_BOOKS_KEY` / `NOVARR_BOOKS_LOOKUP_TIMEOUT` | Book matches from Google Books and Open Library (`false` = the ePub's own details only), an optional Google Books key, seconds per lookup | `true` / — / `15` |
| `NOVARR_BOOKS_PATH` | Where uploaded ePubs are kept (inside `storage/app` by default, so the daily backup includes them) | `storage/app/books` |
| `NOVARR_KOREADER_PLUGIN_PATH` | Folder served to KOReader devices as the plugin update (see [Apps and JSON API](#apps-and-json-api)) | `koreader/novarr.koplugin` |
| `TRUSTED_PROXIES` | Proxies whose `X-Forwarded-*` headers are trusted (nginx / Tailscale / the Cloudflare Tunnel connector in front) | `*` |
| `NOVARR_AUTH` | Force sign-in `on` or `off`, overriding the switch in Settings → Users (which is then shown locked); see [Accounts and sign-in](#accounts-and-sign-in) | — (use the switch) |
| `NOVARR_PUBLIC_HOSTS` / `NOVARR_PUBLIC_URL` | Hostnames published on the internet (see [Publishing on the internet with a Cloudflare Tunnel](#publishing-on-the-internet-with-a-cloudflare-tunnel)); empty = off | — / `https://` + first host |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile keys: the human check on the public address's sign-in and invite forms (see [Publishing on the internet](#publishing-on-the-internet-with-a-cloudflare-tunnel)); either empty = off | — |
| `NOVARR_PASSWORD_BREACH_CHECK` | `off` stops refusing new passwords found in a data leak (Have I Been Pwned range API; see [Accounts and sign-in](#accounts-and-sign-in)) | on |
| `NOVARR_AUTH_LOG_DAYS` | Days of sign-in history kept, and of the addresses recorded with reads (Readers); `0` = forever | `180` |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | Must exceed the 3600 s job timeout | `3700` |

### DB-backed settings (Settings UI)

| Setting | Purpose |
|---|---|
| `flaresolverr_url` | Override the FlareSolverr endpoint |
| `notification_webhook_url` | Override the alert webhook |
| `scrape_min_delay` / `scrape_max_delay` | Polite delay window between chapter fetches (seconds) |
| `summary_time` | When the daily summary email is sent (e.g. `08:00`) |
| `summary_readers` | Send each reader their own daily summary (default on; see [Accounts and sign-in](#accounts-and-sign-in)) |
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
| Requests shelf warm-up | every 3 hours | `requests:browse-warm` — refetch page 1 of each Discover shelf list on the Requests page, so no reader waits for a cold list |
| Sign-in history prune | daily @ 04:35 | `novarr:prune-auth-log` — drop history older than `NOVARR_AUTH_LOG_DAYS` (180 days) and clear the addresses of reads that old |
| Summary email | daily @ `summary_time` | `novel:email-summary` — recap since the previous summary (`summary_last_sent_at`): continue-reading block, headline figures, one row per updated novel, completed novels, and the attention list when it has changed (`summary_attention_hash`); a table-based brand email with a text part, built to survive Gmail/Outlook. Then each reader's own summary of the same downloads (see [Readers' daily summary](#accounts-and-sign-in)) |

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
| `novel:create {name} {url} {--force}` | Create a novel and auto-fetch its metadata; refuses a series already in the library unless `--force` |
| `novel:toc {novel=0} {--frequent-only}` | Scrape table(s) of contents (0 = all active novels) |
| `novel:chapter {novel=0} {--chapter=}` | Download pending chapters (or one chapter by id) |
| `novel:metadata {novel?} {--novelupdates-url=}` | Refresh metadata (description, author, genres, cover); `--novelupdates-url` forces a NovelUpdates match (score 1.0) |
| `novel:epub {novel=0}` | Generate ePub(s) (0 = all not-yet-generated) |
| `novel:send-to-kindle {novel} {--to=} {--generate}` | Email a novel's ePub to Kindle (`--generate` builds it first and aborts if that fails) |
| `novel:verify-completion {novel=0} {--dry-run} {--force} {--no-kindle}` | Verify against NovelUpdates and mark complete |
| `novel:email-summary {--hours=} {--to=} {--reader=} {--stalled-only}` | Send the daily summary; the window runs from the last summary sent (else 24 h) unless `--hours` is given. Then each reader's own summary (with sign-in on). `--to` sends only the owner's summary, to that address. `--reader=<sign-in name>` sends only that reader's summary (with `--to`, a preview to that address, sent even when nothing is new). `--stalled-only` sends only the stalled-source alert (run hourly by the scheduler) |
| `novel:origin {novel=0} {--force} {--no-fetch} {--delay=2}` | Mark novels translated / original from NovelUpdates, else inference; `--force` re-evaluates inferred rows and re-fetches NovelUpdates ones, `--no-fetch` infers from stored data only. Manual choices are never overwritten |
| `novel:normalize_labels {novel} {--dry-run} {--renumber} {--dedupe}` | Tidy labels; keeps stored numbers (decimals included) unless `--renumber`; duplicate removal only with `--dedupe` |
| `novel:fix_chapters {novel=0}` | Resolve chapters with missing numbers by elimination against the sequence |
| `novel:clean_chapter_content {novel} {--dry-run}` | Remove leftover CSS and ad-widget text; refuses to save if more than half the words would go |
| `novel:chaptercleaner {novel} {--dry-run}` | Re-queue downloaded chapters that fail the scraper's word gate (text kept until re-downloaded) |
| `novel:info` | Print novel info, chapter counts, completion % |
| `queue:health-check` | Report queue system health |
| `novarr:auth {on\|off\|status}` | Turn "Require sign-in" on or off, or show its state, the public address and emails two accounts share |
| `novarr:password {username?} {--password=}` | Set an account's password (prompts when `--password` is not given; same rules as the website); the way back in if you are locked out |
| `requests:browse-warm` | Refresh page 1 of the Requests page's Discover shelf lists (every three hours); a list that can't be fetched keeps its cached page |
| `novarr:prune-auth-log` | Delete sign-in history older than `NOVARR_AUTH_LOG_DAYS` and clear the addresses of reads that old, forget signed-in browsers and remember-me rows past their lifetime, and unfinished second steps (daily at 04:35) |

Any of these can also be run from the **Commands** page in the UI with live job-status polling, or from the command palette ("scrape toc <novel>"). The destructive ones (normalize, fix, clean, cleaner) run as a **dry run first** from the novel page and ask before applying.

---

## Accounts and sign-in

Novarr starts with **sign-in off**: there is one account, the owner, and every request is treated as the owner. That suits a server only you can reach. To share the library, turn sign-in on.

**Turning it on**

1. Settings → Users: give the owner account a sign-in name and a password. The switch stays locked until that is done.
2. Flip **Require sign-in** (or run `php artisan novarr:auth on`). You are signed in on the spot.
3. Sign in again on each app: the iPhone and Android apps ask for the name and password (and, on the public address, a sign-in code: see [The second step](#the-second-step-public-address)), or choose **Link with a code** and enter the code they show under Account → Link a device on the website; a Kindle is always linked with a code (below).

`NOVARR_AUTH=on|off` in `.env` overrides the switch. Locked out? `php artisan novarr:password <name>` sets a new password and `php artisan novarr:auth off` turns sign-in off.

**Who can do what**

| | Owner (admin) | Reader |
|---|---|---|
| Read novels and manga, own progress, highlights and stats | ✓ | ✓ |
| Check one series for new chapters, build and download its ePub, OPDS | ✓ | ✓ |
| Add, edit, pause or delete series; Discover; tags; bulk actions | ✓ | — |
| Settings, sources, commands, logs, health, Send to Kindle, users | ✓ | — |
| Readers: what every account reads and when | ✓ | — |

The rule is enforced on the server for every route; a route that is not explicitly listed as public or reader is admin-only, and a test walks the route list to keep it that way. There is one admin.

**Inviting a reader**

Settings → Users → **Invite a reader**: enter a name and an email. Novarr emails a link (valid 7 days, shown once on screen as well) where the person picks a sign-in name and password. Pending invites can be resent (the old link stops working) or cancelled. Readers can sign in with their name or their email. Disabling an account stops its sessions and app tokens at once; deleting it removes its read state.

**Readers' daily summary**

With sign-in on, the daily summary run (`novel:email-summary`, at `summary_time`) also emails each reader a summary of their own, after the owner's. It goes to every reader who accepted their invite, is not turned off and has an email address (and only when mail is set up). Like the owner's, it lists everything downloaded and every series completed (with the ePub link), but with that reader's own progress, "Read from" link and Continue reading block. It has no attention list, stalled sources, queue figures or admin links. Links use the public address when one is set up. A reader's window runs from their last summary (24 hours the first time, never more than 7 days back), and nothing is sent when nothing new was downloaded or completed.

A reader turns it off under Account → **Daily summary email**, or with **Stop these emails** at the bottom of the email (the link opens a page with a button; opening it alone changes nothing). The owner can turn all reader summaries off with Settings → **Send readers their daily summary**. To see what a reader would get, run `php artisan novel:email-summary --reader=<sign-in name> --to=you@example.com`: it is sent to that address even when nothing is new, and does not move the reader's window.

**Passwords**

Every new or changed password (accepting an invite, the Account page, Settings → Users, "Add without an invite", `php artisan novarr:password`) must be 12 to 255 characters, with no rules about which characters, and must not be the account's display name, sign-in name or email. It is also refused when it appears in a known data leak: Novarr asks [Have I Been Pwned](https://haveibeenpwned.com/Passwords)'s range API, sending only the first 5 characters of the password's SHA-1 (with padding on), so neither the password nor its hash leaves the server. If that service cannot be reached within 3 seconds the password is allowed (a warning goes to the log, at most once an hour). `NOVARR_PASSWORD_BREACH_CHECK=off` turns the check off. Existing passwords keep working; nobody is asked to change one.

**Sign-in history**

Settings → Users → **Sign-in history** (admin, home network only) lists, newest first, every sign-in and sign-out, failed attempt, refusal by a limit, account lock, second step passed or failed, sign-in code emailed, recovery code used, passkey or authenticator added or removed, recovery codes made, emailed codes turned on or off, catalogue password made or revoked, browser signed out, second step reset by the admin, password change or reset, account created / edited / turned off / deleted, invite sent / resent / cancelled / accepted, app or device signed in or out, and the sign-in switch (also when changed with `novarr:auth`). Each line shows the account, the address (the visitor's, through `TRUSTED_PROXIES`), whether it came in on the public address, and the browser or app. Filter by account or by sign-ins / failures / admin actions. A failed attempt names the account only when the name matches one. Passwords, tokens and invite links are never stored. History older than 180 days is dropped daily (`NOVARR_AUTH_LOG_DAYS`, `0` = keep forever); the addresses recorded with reads (Readers, below) are cleared after the same time.

Each reader's **Account** page shows their own **Recent sign-ins** (the last 20, failed ones and failed second steps included) and their **Signed-in browsers** (device, address, public address or home network, when it signed in and was last active, "This browser"). **Sign out** on a row ends that browser's session at once and forgets its remember-me; every other browser stays signed in. **Sign out all other browsers** does it for all but this one; apps stay. Signing out of a browser never signs out another. Settings → Users shows "N browsers" next to "N devices" for each account.

**Readers (reading activity)**

System → **Readers** (`/readers`; admin, so home network only, like every admin page) is the owner's view of what every account reads, sign-in on or off (with it off it is usually just the owner). It shows what each reader reads and when, but never their highlights, which stay private to each reader.

- **Reading now**: a reader whose reading position (the one the apps, the Kindle plugin and the web reader write) moved in the last 10 minutes, with the series, chapter, how far in, the device, the address (flagged "Public address" when it came in on the public address) and how long ago. One per reader, their newest. The panel refreshes every 30 seconds while the page is open.
- **Figures**: readers active today, chapters today, this week (7 days) and in the last 30 days, a 30-day chart of chapters per day stacked by reader (also as a table), and one row per account with last seen, last read (with its device), today, 7 days, 30 days, all-time and streak. Days follow the server's timezone, as on Stats. These figures are cached for a minute.
- **Most popular**: the series with the most different readers in the last 30 days, then the most chapters.
- **History** (`/readers/history`): every read, newest first, filtered by reader, series and address (`?ip=`; click an address anywhere to filter by it), 100 reads a page. Reads are grouped into **sessions**: one reader's chapters of one series on one device read no more than 30 minutes apart ("Chapters 12–31 (20 chapters)"), grouped within a page; reading on the phone and then on the Kindle is two sessions. Each session shows its device and address ("+1" when it used more than one). "Marked by hand" shows chapters ticked as read rather than opened.
- **One reader** (`/readers/{id}`, also "Reading activity" on Settings → Users): their stats as on their own Stats page, their reading positions (with device and address), their signed-in apps and browsers (read-only; browsers with their address), an **Addresses** card (each address they read from in the last 30 days, home network or public address, how many chapters and when last) and their recent sessions, with links to their sign-in history and account settings.

Activity is a chapter read, ticked or finished. Chapters **marked read in bulk** (catching up on a series) are not reading: they count in the all-time totals only, and the history leaves them out unless you tick **Include chapters marked read in bulk**. Chapters that were deleted are left out everywhere.

**Device and address.** When a chapter is marked read, Novarr stores where from with the read mark (and with the reading position): the visitor's address (through `TRUSTED_PROXIES`, as in the sign-in history), whether it came in on the public address, the device and a short description of the client ("KOReader", "Novarr app on Android", "Firefox on macOS"). The device is the name the device gives itself (the apps and the KOReader plugin send one; a browser names itself after its platform, such as "Web · iPhone"), else the name of the app token it signed in with. A chapter already read keeps the device and address of its first read, as it keeps its date; marking it unread forgets them; a bulk mark stores the device and address that made it. Only reads after the upgrade carry them (older ones show "—"). Offline reads sent later carry the address they were sent from. Addresses are cleared with the sign-in history, after `NOVARR_AUTH_LOG_DAYS` (180 days by default, `0` = keep), by `novarr:prune-auth-log`; the device stays.

**Remember-me**

Every web sign-in is remembered on that browser by its own remember-me row (a random selector and token in the cookie; only the token's SHA-256 is stored). A row stops working after 30 days without use (any use of the browser keeps it alive) and a year after it was made whatever happens. Signing out deletes only that browser's row. Sign out everywhere, a password change (the other browsers), an admin password reset, turning an account off and "Reset second step" delete the account's rows. Remember-me cookies from before this release are not honoured anywhere (on the home network too): everyone signs in once more on each browser after the upgrade.

<a id="the-second-step-public-address"></a>
**The second step (public address)**

On the public address (`NOVARR_PUBLIC_HOSTS`, sign-in on) a correct password is not enough: the website shows "One more step", and the apps get `401 second_step_required` (see the API below). Ways through, in the order they are offered:

1. **Passkey** (website only) — added under Account → **Sign-in steps**. A passkey belongs to the address it was made on (the public hostname, or an https Tailscale name) and only works there; plain-http addresses cannot use passkeys at all (browsers allow them only on https), so the page says so there. **Sign in with a passkey** on the sign-in page replaces the password and the second step. Novarr requires the device to verify it is you (fingerprint, face, PIN), checks the origin, the address, a one-time challenge and the signature counter.
2. **Authenticator app** — any app that makes 6-digit codes (RFC 6238: 30 seconds, SHA-1). Set up under Account → Sign-in steps: scan the QR code (drawn by Novarr itself, so the secret never goes anywhere else) and type a code. The secret is stored encrypted with `APP_KEY`. A code is accepted for 30 seconds either side, and never twice.
3. **Emailed code** — "Email me a code" (never sent unasked): 6 digits, valid 10 minutes, at most 3 per sign-in and 6 per account an hour. Every account with an email address has it without any setup, as long as mail is set up (`MAIL_MAILER` not `log`). It can be turned off once a passkey or authenticator exists, and comes back on when the last of those is removed.
4. **Recovery codes** — once a passkey or authenticator exists, Account → Sign-in steps → **Make recovery codes**: 10 codes like `k7m4q-x9p2a`, shown once, each usable once; making new ones kills the old set.

Five wrong codes end the attempt (sign in again); the attempt also expires after 10 minutes and grants nothing on its own. Wrong codes count toward the account lock and the per-address limit, like wrong passwords. A browser that passed the second step stays signed in there (remember-me, above); a browser signed in on the home network does not count on the public address. An account with no way through there (no email, nothing set up) is refused after the correct password with "This account needs a second sign-in step before it can be used here. Set one up from your home network, or ask the person who runs this Novarr." — methods are only ever set up after signing in, never with the password alone on the public address. Someone who lost their phone or passkey: Settings → Users → **Reset second step** (removes passkeys, authenticator and recovery codes, turns emailed codes back on, signs them out everywhere). Each account's second steps are listed on Settings → Users.

An invite accepted on the public address makes the account and goes on to the second step (the emailed code: the invite went to that address).

**Confirm it's you**

Adding or removing a passkey or the authenticator, new recovery codes, emailed codes on or off, a new password, a catalogue password and linking a device need a confirmation from this browser within the last 10 minutes: the password on the home network, a second step on the public address. Signing in counts. Wrong passwords there are limited to 5 per 15 minutes per account; on the public address the per-address cap and the account lock apply first, and wrong codes count toward them, as when signing in. The Account page is sent `Cache-Control: no-store, private` (it can show an authenticator key, recovery codes or a new catalogue password). Bearer tokens do not open it.

**New sign-in email**

When an account is signed in to with its password (the website or an app) from a device it has not used before, Novarr emails the account's address: when, which device, from which address, and whether it was the public address. A browser is recognised by a random id in a long-lived cookie, an app by the device id it sends; only their SHA-256 is kept (50 per account). The first device of an account (the browser an invite is accepted on, or the first sign-in after this was installed) sends nothing, nor does an account without an email, nor a server whose mail is not set up. The email goes out after the sign-in has answered, so a slow mail server never holds it up.

**Devices**

- **Apps** sign in with name and password (plus a sign-in code on the public address) and keep a token; each one is listed under **Account → Signed-in apps and devices** with a Revoke button. An account whose only second step is a passkey links its apps with a code instead (**Link with a code** in the app, then Account → Link a device on the website).
- **Kindle / KOReader**: the plugin (1.9.0+) shows a 6-character code; enter it under **Account → Link a device** on the website and confirm. See [koreader/README.md](koreader/README.md).
- **OPDS** readers use HTTP Basic: the sign-in name (or email) and a **catalogue password** (below); on the home network the account password works too.

**Details**

- Sign-in attempts are limited to 5 a minute per name and address. On the public address there are two more limits: 20 attempts per address per 10 minutes, and a per-account lock (see [Publishing on the internet](#publishing-on-the-internet-with-a-cloudflare-tunnel)). Every 429 says how long to wait and carries `Retry-After`.
- Emails are unique, ignoring case (Settings → Users → Edit sets or changes one). On SQLite, whose unique index on emails is case-sensitive, older data can hold one address twice in different cases (MySQL/MariaDB's index already refuses that): signing in with such an address is then refused (the sign-in names still work), and `php artisan novarr:auth status` lists it.
- Cover and manga page image URLs are signed per reader, so they load in `<img>` tags and in the apps without a session.
- Pages and API responses that contain reader state are cached per account (`Cache-Control: private`, per-reader `ETag`).
- To reach Novarr from outside your network, see [Publishing on the internet with a Cloudflare Tunnel](#publishing-on-the-internet-with-a-cloudflare-tunnel): admin features are switched off on the public address.

---

## OPDS catalog

Every novel with a generated ePub is published as an **OPDS 1.2 acquisition feed** at `/opds` — point KOReader, Moon+ Reader, Calibre, or any OPDS-capable reader at `https://<your-host>/opds` to browse your library with covers and download ePubs directly. No configuration needed; the feed reflects whatever `novel:epub` has produced.

With sign-in on, the reader app asks for a user name and password (HTTP Basic). Use your sign-in name (or email) and a **catalogue password**: Account → **Catalogue passwords** → name it after the app → **Make a catalogue password**, and copy it (it is shown once; only its SHA-256 is kept). It opens `/opds` and the ePub downloads only — no other page or API — is listed with when it was last used, and can be revoked. On the public address the catalogue does not accept the account password at all (it is not even checked); on the home network the account password still works as before.

---

## Apps and JSON API

Three readers besides the website live in this repository: the iPhone / iPad app, the Android app and the KOReader plugin. All talk to the server through the JSON API and share reading position with the website. With sign-in off the API needs no credentials, so keep the server on your own network or tailnet; with sign-in on, every call except `GET /meta`, the sign-in endpoints and the plugin files needs a token (see [Accounts and sign-in](#accounts-and-sign-in)).

### JSON API (`/api/v1`)

| Endpoint | What it does |
|---|---|
| `GET /meta` | App id, API version and server version; the apps use it to check an address is Novarr. `auth: {required, available, second_step, methods}` says whether to sign in, whether this address accepts sign-ins (`false` on the public address while sign-in is off), and whether a sign-in code follows the password here |
| `POST /auth/token`, `DELETE /auth/token`, `GET /auth/me` | Sign in with name (or email) and password for a bearer token, sign out, and who is signed in. On the public address the password answers `401 {"code":"second_step_required","message","challenge","expires_in":600,"methods":["totp","email","recovery"],"email_hint"}` (only the account's methods; `email_hint` whenever `"email"` is listed), or `403 {"code":"second_step_unavailable","message"}` when the account has no method an app can use |
| `POST /auth/token/email-code`, `POST /auth/token/second-step` | The second step: `{challenge, device_id}` emails a code (204); `{challenge, method: totp\|email\|recovery, code, device_id}` answers `200 {token, user}`, `422 {"code":"wrong_code","message","tries_left"}` (always ≥ 1) or `404 {"code":"challenge_expired","message"}` (timed out, used, or the 5th wrong code). A challenge is single use, 10 minutes, bound to the account and the `device_id` of the password request: when that sent one, both calls must send the same (missing or different: 404) |
| `POST /auth/pairings`, `GET /auth/pairings/{secret}` | Link a device by code: the device asks for a code, the user enters it on the website, the device collects its token |
| `GET /home` | The novel being read and the shelves of the home screen |
| `GET /novels`, `GET /novels/{id}` | The library and one novel's details |
| `GET /novels/{id}/chapters` | A novel's chapters in reading order |
| `GET /chapters/{id}` | One chapter (paragraphs and sanitised HTML); never marks it read |
| `POST /chapters/{id}/progress`, `POST /chapters/{id}/read`, `POST /chapters/bulk-read` | Reading position and read marks |
| `GET /discover`, `GET /discover/browse` | The sources with their lists, and one page of a list or search (`type`, `source`, `q`, `page`) |
| `POST /discover/searches`, `GET /discover/searches/{id}` | The same browse or search run in the background (after the response is sent), then fetched by id (kept 15 minutes) |
| `POST /novels` | Add a novel from a Discover result (`title`, `url`); 409 with the existing series when it is already in the library, unless `force=1` |
| `POST /novels/{id}/check`, `GET /novels/{id}/check` | Ask the server to check a novel's source for new chapters, and follow that check |
| `POST /novels/{id}/epub`, `GET /novels/{id}/epub` | Build a novel's ePub and download it |
| `GET /requests/browse` | The Requests page's Discover shelf: `medium` (`manga`), `list` (default the first; `popular`, `trending`, `top` as the source offers them), `page` (1–10). Answers `{medium, lists: [{id, name}], list, source_name, page, has_more, results}` with results shaped as a request search's (`requested` included; series already in the library are left out); an empty `lists` when the shelf is off. 502 `browse_unavailable` when the source can't be reached, 503 `browse_busy` (`Retry-After`) while another reader loads the same page; 30 calls a minute per reader |
| `POST /requests` | Request a series: `{search_id, key, note?}` from a request search, `{list, page?, key, note?, medium?}` from the Discover shelf (410 `browse_expired` when that result is no longer on the list), or `{title, medium, link?, note?}` as free text. 201 a new request, 200 `joined` when someone else already asked for it, 409 `in_library` / `already_requested`, 429 `pending_limit` |
| `GET /highlights`, `POST /highlights`, `DELETE /highlights/{id}` | Highlights (the website's bookmarks: an excerpt with an optional note), newest first, per novel with `novel_id` |
| `GET /koreader/plugin`, `GET /koreader/plugin/files/{path}` | The KOReader plugin's update manifest and files |

Every 429 from the sign-in endpoints is `{"code":"throttled","message":"… Try again in N seconds.","retry_after":N}` with `Retry-After`. With sign-in on, send `Authorization: Bearer <token>` (it is read on `/api/*`, `/opds` and the ePub download only; on web pages a bearer token is ignored); a missing or bad token answers 401 `{"message", "code": "unauthenticated"}` and an admin-only endpoint answers 403 `{"message", "code": "forbidden"}` to a reader (Discover and adding novels are admin-only). List endpoints answer conditional requests (`ETag` / `If-None-Match`). A Discover search can take a minute or two when the source sits behind FlareSolverr. A check runs on the queue (`novel:toc`, then `novel:chapter`), so it needs the queue worker or the scheduler's drain. A background Discover search does not use the queue.

### iPhone / iPad app (`ios/`)

A native SwiftUI app (iOS 17+): connect to the server by address, sign in when the server has accounts (name and password, then a sign-in code when the address asks for a second step: authenticator app, emailed code or recovery code; or **Link with a code instead**, entered on the website under Account → Link a device), then Home, Library, novel pages with **Check for new chapters**, the reader, Highlights (per novel and across the library) and Settings. Like the Android app it reads only: Discover and library management stay on the website. The Xcode project is generated from `ios/project.yml` with [XcodeGen](https://github.com/yonaskolb/XcodeGen); open `ios/Novarr.xcodeproj` to build and run, and `NovarrTests` holds its tests.

### Android app (`android/`)

A native Kotlin / Jetpack Compose reader (Android 8.0+, API 26): connect to the server by address, sign in when the server has accounts, then Home, Library (novels and manga on separate shelves), series pages with **Check for new chapters** and offline downloads, the text reader, a manga reader, Highlights and Settings. It reads only: there is no Discover or library management, whoever signs in. Reading progress and read marks sync through the API and are queued while offline.

**Signing in.** With sign-in on, the app asks for the name (or email) and password; on the public address it then asks for a sign-in code: from an authenticator app, emailed on request (**Email me a code**, **Send again** after 30 seconds) or a recovery code, whichever the account has. The password is never stored, and the code step lives only in memory (it survives turning the device, not the app being closed by Android). **Link with a code instead** (when the server offers linking, and always after the server says the account can't pass the second step in an app, such as a passkey-only account) shows a code like `K7M 4QX` to enter on the website under Account → Link a device; the app checks every few seconds until the code is used or expires. When sign-in is switched off at the address the app uses, it says so instead of showing the form.

- **Scroll or Pages** — the text reader scrolls continuously or turns screen-sized pages (tap the right or left side, swipe, or use the volume keys). The place is kept when you switch modes, rotate or change the text size.
- **Manga** — paged left-to-right or right-to-left, or vertical scroll, following the series' reading mode; each series can be overridden on the device (Follow series / Pages / Scroll).
- **Reading fonts** — Literata (default), Geist, or the device's serif and sans; the app uses the same mark, colours and fonts as the website.
- **Update notifications** — about every 12 hours, while it has a network connection and even when closed, the app asks the saved server's public `GET /meta` (no credentials) whether a newer build is published, and posts one quiet notification per build ("Novarr 1.4 is available", channel *App updates*). Tapping it opens Home, whose banner offers **Update**. Settings › Notifications › **Notify me about app updates** turns it off; on Android 13+ the app asks for the notification permission once on Home after you connect, and Settings explains how to allow it if it was denied. No Google services are involved (WorkManager on the device); failures, such as being away from your home network, wait quietly for the next check.
- **Requests** — when the server offers them (`meta.requests`), Home's **Requests** row and its top bar (the rail on tablets) open Requests: search every source for a novel or manga and request it (with a note, or "Request it anyway" by title), or tap **Me too** on a series someone else already asked for to be told when it's ready, follow your requests (cancel a pending one, leave one you joined, open an available one), and, for the owner on the home network, review everyone's pending requests oldest first (pick a source from the ranked shortlist, or give a series URL; approve or decline with a reason). These need a connection; nothing is queued. The app reads the server's request feed (`GET /notifications`) hourly in the background and posts a notification per item (channel *Requests*; Settings › **Notify me about requests**), and when opened shows a dot on Requests instead. Each item is shown once: the feed's cursor is saved per server and account only after the items were shown.

Build with the Gradle wrapper from `android/` (JDK 17 and the Android SDK): `./gradlew :app:assembleDebug`, and `./gradlew :app:testDebugUnitTest` for its unit tests.

**Release and hand-out.** `./gradlew :app:assembleRelease -Pnovarr.release=/path/to/signing.properties` builds a signed APK; the properties file (kept outside the repository) names the keystore and may set `defaultServer`, the address the connect screen starts with. Keep the keystore: an APK signed with another key cannot update an installed one. Copy the APK to `storage/app/apps/novarr.apk` on the server (with an optional `novarr.apk.json` holding `{"version", "build"}`) and Novarr serves it at **`/app/android`** and links it from invite emails.

**One-command publish.** `make release-android` builds the signed APK, writes `novarr.apk.json` from `versionName` / `versionCode` in `android/app/build.gradle.kts` and copies both to the server with scp (`NOVARR_ANDROID_SIGNING`, `NOVARR_DEPLOY_HOST` and `NOVARR_ANDROID_APK_DEST` override the defaults); `make release-android-dry` prints what it would run. Bump `versionCode` first: installed apps see the new build in meta, offer **Update**, download it, check its size and SHA-256 against the server and hand it to Android's installer (the system still asks to confirm, and the first time asks to allow installs from Novarr). Google Play Protect may call the sideloaded update harmful and block it; the banner says so before the first install, and after one that didn't finish it offers **Update** again (reusing the checked download) and **Download in browser**: choose More details → Install anyway.

### KOReader plugin (`koreader/`)

For a Kindle (jailbroken, with KOReader) or any other KOReader device. Copy `koreader/novarr.koplugin` into the device's `koreader/plugins/` folder once, enter the server address, and read. What it does:

- **Home shelf** with covers; one tap opens the chapter you are on.
- **Joined chapters** — chapters are read in groups of 20, so a chapter change is a plain page turn.
- **100 chapters saved ahead**, and **Wi-Fi switched off** while reading once everything is saved; it comes back by itself to top up and to send progress.
- **Wi-Fi back on when you leave** — closing the book or exiting KOReader switches Wi-Fi on again when Novarr had switched it off; Settings shows how much of the reading time had Wi-Fi on.
- **Discover** — browse or search the sources and add novels from the device; searches run in the background on the server, so the device stays usable.
- **Check for new chapters** when you have caught up, and **reading stats** on the novel page.
- **Reading info line** — chapters and time left, the clock (it moves on every minute) and the sync state, in the status bar at the bottom, at the top (KOReader's Alt status bar, switched on in Novarr chapters only), or both.
- **Quick menu while reading** (1.11.0+) — hold the status bar for the chapter list, the novel's page, previous / next chapter, sync, check for new chapters and Novarr home. The same places are in KOReader's reader menu and can be given gestures (Novarr: home, chapter list, novel page, quick menu, sync now).
- **Leaving a book** takes you back to the Novarr screen it was opened from.
- **Highlights** — text highlighted in KOReader (with its note) is sent to the server and appears on the website's Highlights page and in the iPhone app; the novel page lists them.
- **Accounts** — when the server requires sign-in, the plugin shows a 6-character code to enter on the website (Account → Link a device); from then on the Kindle reads as that reader.
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
   # optional: Cloudflare's human check on the sign-in and invite forms
   # TURNSTILE_SITE_KEY=…
   # TURNSTILE_SECRET_KEY=…
   ```

   then `php artisan config:cache` (deploy.sh does it) and check `php artisan novarr:auth status` — it shows the public address and warns while sign-in is off.

What changes **on the public address only**:

- **No admin features, even for the admin.** Every admin page and API endpoint answers 403 "Admin features are only available on your home network."; admin buttons, Home cards and palette commands are hidden. The admin can sign in there and read like any reader. Do admin work (adding series, settings, users, invites) from the home network or Tailscale.
- **Sign-in is required, with a second step.** With sign-in off every page except sign-in, health and the app-download/pairing endpoints answers 403 "This address is only available once sign-in is turned on." — accepting an invite and the apps' sign-in (`POST /api/v1/auth/token` and its second step) included, so no account or token is made there before sign-in is on — and `/api/v1/meta` reports `auth.required: true, auth.available: false`. With it on, the password is followed by a second step (see [The second step](#the-second-step-public-address)) and `auth.second_step` is `true`; OPDS takes catalogue passwords only.
- **HTTPS only:** links are generated as `https://read.example.com/…`, session and remember-me cookies are `Secure`, and responses carry `Strict-Transport-Security: max-age=31536000`.
- **Not for search engines:** `X-Robots-Tag: noindex, nofollow, noarchive` on every response (and `robots.txt` disallows everything on every host), plus `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `X-Frame-Options: DENY`.
- **Tighter limits:** on top of the per-name sign-in limit, at most 20 credential attempts per IP per 10 minutes across the web login, `POST /api/v1/auth/token`, wrong second-step codes and passkeys, OPDS (HTTP Basic) and invite acceptance (429 with `Retry-After`). The second-step and passkey endpoints are also limited to 30 requests a minute per address. This relies on `TRUSTED_PROXIES` naming the connector, so the visitor's address (from `X-Forwarded-For`) is the one counted. `CF-Connecting-IP` is not used.
- **Account lock:** 10 wrong passwords for one sign-in name as typed (a name, an email, or a name nobody has: all alike), or 10 wrong second-step codes for one account (Confirm it's you included), within 15 minutes and from any addresses (home network included), lock password sign-in with that name — or the account, under every name — on the public address for 15 minutes: web login, `POST /api/v1/auth/token`, the second step and Confirm it's you alike (429 "Too many sign-in attempts. Try again in N seconds.", `Retry-After`). Every failure is counted before the answer is checked, so requests sent in parallel cannot get past it. The home network is never locked: signing in there works throughout and lifts the locks. Known and unknown names get exactly the same lock and answers, so they never tell whether a name or an email belongs to an account. The lock is logged in the sign-in history.
- **Human check (optional):** with `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` set (a [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) widget for the public hostname), the sign-in and invite forms on the public address show Cloudflare's check and Novarr verifies the answer with Cloudflare before looking at the password. A missing or wrong answer gets "Please complete the check below and try again." and counts as a failed attempt; if Cloudflare cannot be reached the form says "The sign-in check is not available right now. Try again in a minute." (it fails closed). The home network never shows it (the forms work without JavaScript there), and the apps, OPDS and Kindle pairing never need it.

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

The Android app follows the same system: the Serial mark as its launcher icon, the `_variables.scss` palette as its Material colour scheme, and Geist, Geist Mono and Literata bundled under the OFL (`android/app/src/main/res/font/`).

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
android/                   # Android app (Kotlin, Jetpack Compose; Gradle wrapper)
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
