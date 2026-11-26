<?php

/*
|--------------------------------------------------------------------------
| Novarr integration endpoints
|--------------------------------------------------------------------------
|
| env() must only be read inside config files — once `config:cache` is in
| play, .env is no longer loaded at runtime and env() calls elsewhere
| silently return null. Runtime code reads these via config('novarr.*'),
| usually as the fallback behind the DB-backed setting() store.
|
*/

// "1,3,6" -> [1, 3, 6] for the id-list settings below. Unset -> the
// default; set but empty -> [] (the behaviour is switched off). Anything
// that is not a list of whole numbers (a typo, "true") keeps the default
// rather than silently switching the behaviour off or matching id 0.
$idList = static function (string $env, array $default): array {
    $value = env($env);
    if ($value === null || !is_string($value)) {
        return $default;
    }

    $tokens = array_values(array_filter(array_map('trim', explode(',', $value)), fn($id) => $id !== ''));
    foreach ($tokens as $token) {
        if (!ctype_digit($token) || (int) $token < 1) {
            return $default;
        }
    }

    return array_map('intval', $tokens);
};

return [
    /*
    |--------------------------------------------------------------------------
    | Source registry
    |--------------------------------------------------------------------------
    |
    | Ordered map of source key => adapter class (App\Sources\Source). The
    | first adapter whose matches() / matchesUrl() accepts a novel or URL
    | wins, so order matters: NovelArrowSource accepts everything and must
    | stay LAST as the catch-all. The key is the source's id on the Discover
    | page (?source=…). Add a site by writing an adapter and listing it here.
    |
    */
    'sources' => [
        // Manga (medium 'manga'): claims only mangadex.org URLs. Listed
        // first so no group-id logic of the novel adapters ever sees one.
        'mangadex' => \App\Sources\MangaDexSource::class,
        // Manga through the user's own Suwayomi-Server (config `suwayomi`
        // below). Claims nothing and lists nothing until NOVARR_SUWAYOMI_URL
        // is set; on Discover it is one entry per Suwayomi source.
        'suwayomi' => \App\Sources\SuwayomiSource::class,
        // Books uploaded as ePubs (medium 'book'): claims only its own
        // book:// URLs (and rows of medium 'book'), so it never takes a web
        // address from another source; before the novel adapters so none of
        // their group-id logic ever sees a book.
        'book' => \App\Sources\BookSource::class,
        'empirenovel' => \App\Sources\EmpireNovelSource::class,
        'novelfull' => \App\Sources\NovelFullSource::class,
        'novelfire' => \App\Sources\NovelFireSource::class,
        'royalroad' => \App\Sources\RoyalRoadSource::class,
        'novelarrow' => \App\Sources\NovelArrowSource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Books (ePub uploads, App\Books)
    |--------------------------------------------------------------------------
    |
    | max_upload_mb      largest ePub accepted (PHP's upload_max_filesize and
    |                    post_max_size, and nginx's client_max_body_size, must
    |                    allow it too).
    | lookup             fill what an ePub leaves out (description, genres,
    |                    cover) from Google Books and Open Library. Off: only
    |                    the ePub's own metadata is used.
    | google_books_key   optional Google Books API key (the API answers without
    |                    one, with a lower daily quota).
    | timeout            seconds per lookup request.
    |
    */
    'books' => [
        // Where uploaded originals are kept: back this folder up, it is the only copy.
        // A relative path is taken from the app's folder.
        'path' => ($booksPath = trim((string) env('NOVARR_BOOKS_PATH', ''))) === ''
            ? storage_path('app/books')
            : (str_starts_with($booksPath, '/') ? $booksPath : base_path($booksPath)),
        // Originals of books imported from Calibre-Web: they can be fetched
        // again from Calibre, so they may live outside the backups (a big
        // second disk). Empty = with the uploads, in `path`.
        'calibre_path' => ($calibrePath = trim((string) env('NOVARR_BOOKS_CALIBRE_PATH', ''))) === ''
            ? null
            : (str_starts_with($calibrePath, '/') ? $calibrePath : base_path($calibrePath)),
        'max_upload_mb' => (int) env('NOVARR_BOOKS_MAX_UPLOAD_MB', 50),
        'lookup' => (bool) env('NOVARR_BOOKS_LOOKUP', true),
        'google_books_key' => (string) env('NOVARR_GOOGLE_BOOKS_KEY', ''),
        'timeout' => (int) env('NOVARR_BOOKS_LOOKUP_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calibre-Web (App\Books\CalibreWeb, App\Books\CalibreImport)
    |--------------------------------------------------------------------------
    |
    | The owner's Calibre-Web, read through its OPDS feed with a read-only
    | account (download permission). Empty url = off.
    | languages  Calibre language codes listed and imported (a book with no
    |            language is included); empty = every language.
    | sync       import books added to Calibre from now on, hourly
    |            (`books:calibre-import`); the first run only marks where
    |            "from now on" starts. `--all` imports the whole library.
    |
    */
    'calibre_web' => [
        'url' => rtrim(trim((string) env('NOVARR_CALIBRE_WEB_URL', '')), '/'),
        'username' => (string) env('NOVARR_CALIBRE_WEB_USERNAME', ''),
        'password' => (string) env('NOVARR_CALIBRE_WEB_PASSWORD', ''),
        'languages' => array_values(array_filter(array_map(
            fn($l) => strtolower(trim($l)),
            explode(',', (string) env('NOVARR_CALIBRE_WEB_LANGUAGES', 'eng'))
        ), fn($l) => $l !== '')),
        'sync' => (bool) env('NOVARR_CALIBRE_WEB_SYNC', true),
        'timeout' => (int) env('NOVARR_CALIBRE_WEB_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | NovelFire (novelfire.net)
    |--------------------------------------------------------------------------
    |
    | The site answers 429 after a burst of about ten requests. A chapter
    | list is 100 rows a page, so a long book is a walk of 30+ pages:
    | request_delay_ms is the pause between two pages, rate_limit_wait_ms
    | the wait before a rate-limited page is asked for again (up to
    | NovelFireSource::RATE_LIMIT_WAITS times).
    |
    */
    'novelfire' => [
        'request_delay_ms' => (int) env('NOVARR_NOVELFIRE_REQUEST_DELAY_MS', 1500),
        'rate_limit_wait_ms' => (int) env('NOVARR_NOVELFIRE_RATE_LIMIT_WAIT_MS', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Install-specific database ids
    |--------------------------------------------------------------------------
    |
    | Rows in the original install's database that older code singled out by
    | id. The defaults match the original install; other installs can
    | override them (comma-separated ids in the env var; empty = none). Read
    | through App\Sources\InstallIds.
    |
    */
    // Groups whose novels' alternative_url replaces the chapter URL: the
    // chapter is fetched from {alternative_url}{chapter number}
    // (chapterSourceUrl()).
    'alternative_url_group_ids' => $idList('NOVARR_ALTERNATIVE_URL_GROUP_IDS', [1, 3, 6]),
    // Novels whose alternative_url chapter number keeps its decimal part,
    // written with a dash ("12.5" -> "12-5") instead of being floored.
    'dotted_chapter_url_novel_ids' => $idList('NOVARR_DOTTED_CHAPTER_URL_NOVEL_IDS', [72]),
    // Groups whose NovelPing novels take their TOC from the chapter-list
    // JSON API (NovelArrowSource::tableOfContents()); other groups (and a
    // failed API call) use the novel page's embedded list.
    'novelarrow_api_group_ids' => $idList('NOVARR_NOVELARROW_API_GROUP_IDS', [1]),
    // Webnovel.com groups: TOC entries get a unique_id taken from the
    // webnovel chapter URL (the segment after the book id) — NovelScraper.
    'webnovel_unique_id_group_ids' => $idList('NOVARR_WEBNOVEL_UNIQUE_ID_GROUP_IDS', [6]),

    /*
    |--------------------------------------------------------------------------
    | Sign-in (reader accounts)
    |--------------------------------------------------------------------------
    |
    | NOVARR_AUTH=on|off forces "Require sign-in" on or off and locks the
    | Settings → Users toggle. Unset (null) → the app setting `auth_required`
    | decides (default off). Read through App\Support\AuthMode. Anything that
    | is not on/off (a typo) counts as unset rather than guessing.
    |
    */
    'auth' => (static function () {
        $value = env('NOVARR_AUTH');
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return in_array($value, ['on', 'off'], true) ? $value : null;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Public address (internet, e.g. through a Cloudflare Tunnel)
    |--------------------------------------------------------------------------
    |
    | NOVARR_PUBLIC_HOSTS: comma-separated hostnames Novarr is published on to
    | the internet (read.example.com). Requests on these hosts get no admin
    | features, need sign-in, and are HTTPS-only (App\Support\PublicAddress,
    | App\Http\Middleware\HardenPublicAddress). Empty (the default) = off:
    | nothing changes. Entries are lowercased; a scheme, port or path typed by
    | mistake is dropped ("https://Read.example.com:443/" → read.example.com).
    |
    | NOVARR_PUBLIC_URL: the address invite and app-download links use.
    | Default https:// + the first public host; null when no host is set.
    |
    */
    'public_hosts' => $publicHosts = array_values(array_unique(array_filter(array_map(
        static function (string $host): string {
            $host = strtolower(trim($host));
            $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
            $host = (string) preg_replace('#[/?\#].*$#', '', $host);

            return (string) preg_replace('#:\d+$#', '', $host);
        },
        explode(',', (string) env('NOVARR_PUBLIC_HOSTS', ''))
    ), fn(string $host) => $host !== ''))),
    // LAN addresses of the tunnel connector(s): requests coming straight
    // from them count as public whatever hostname they carry.
    'public_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOVARR_PUBLIC_PROXIES', ''))))),
    'public_url' => (static function () use ($publicHosts) {
        $url = trim((string) env('NOVARR_PUBLIC_URL', ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        return $publicHosts === [] ? null : 'https://' . $publicHosts[0];
    })(),

    /*
    |--------------------------------------------------------------------------
    | Sign-in hardening
    |--------------------------------------------------------------------------
    |
    | auth_log_days: days of sign-in history kept (App\AuthEvent, pruned
    | daily by novarr:prune-auth-log), and of the addresses recorded with
    | reads (chapter_reads.ip / reading_positions.ip, cleared after it).
    | NOVARR_AUTH_LOG_DAYS, default 180; 0 = keep forever.
    | password_breach_check: refuse new passwords found in a data leak.
    |
    */
    // Unset or empty = 180 (an empty line copied from .env.example must
    // not mean "forever").
    'auth_log_days' => is_numeric($days = env('NOVARR_AUTH_LOG_DAYS')) ? max(0, (int) $days) : 180,
    // New passwords are checked against Have I Been Pwned's range API
    // (App\Services\BreachedPasswords; only 5 characters of the SHA-1 are
    // sent). NOVARR_PASSWORD_BREACH_CHECK=off turns it off; default on.
    'password_breach_check' => (static function () {
        $value = env('NOVARR_PASSWORD_BREACH_CHECK');
        if (is_bool($value)) {
            return $value;
        }
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return !in_array($value, ['off', 'false', '0', 'no'], true);
    })(),

    'flaresolverr_url' => env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'),
    // Verify TLS certificates on every scraper request (App\Scraping\Fetcher).
    // Only turn off for a source with a broken certificate chain.
    'tls_verify' => (bool) env('NOVARR_TLS_VERIFY', true),
    'notification_webhook_url' => env('NOTIFICATION_WEBHOOK_URL'),

    // Canonical host for the NovelArrowSource adapter. The site rebranded
    // novelarrow.com → novelping.com (the old host 302-redirects); both are
    // recognised, but every outgoing URL (API, chapter pages, covers on
    // images.{host}) is built on this one. Bare host, no scheme.
    'novelarrow_host' => env('NOVARR_NOVELARROW_HOST', 'novelping.com'),

    /*
    |--------------------------------------------------------------------------
    | Chapter watermarks
    |--------------------------------------------------------------------------
    |
    | Site watermark lines injected into chapter text; see
    | isChapterWatermark() / cleanChapterParagraphText(). Patterns have no
    | delimiters and match case-insensitively. Only paragraphs under 200
    | characters are dropped whole; on longer ones only a matching final
    | sentence is cut. Keep patterns specific — a loose one deletes story text.
    |
    | sites   — site names. Matched against the FOLDED line (lowercased, leet
    |           undone 0→o 1→l 3→e 4→a 5→s @→a, whitespace and dots removed)
    |           and only as the WHOLE line followed by a TLD, so "N0velB1n.c0m"
    |           matches but "a novel full of wonders" does not. Write names in
    |           folded form (1 becomes l: novelbin → novelb[il]n).
    | phrases — matched against the lowercased raw text (single spaces), and
    |           only when the text also contains a literal domain token
    |           ("site.com", "n0velb1n.c0m"), so "Find them on the planet."
    |           and "Visit the telecom tower" survive.
    | lines   — whole-line phrases on the folded text that need no domain.
    |
    | Bare domain lines ("novelfull.me") are always dropped (in code).
    |
    */
    'watermarks' => [
        'sites' => [
            'novelb[il]n',
            'lightnovelpub',
            'lightnovelworld',
            'novelfull',
            'novelarrow',
            'novelping',
            'freewebnovel',
        ],
        'phrases' => [
            '^(you can )?read (the )?latest\\b',
            '^(you can )?find\\b.*\\b(at|on)\\b',
            '^visit\\b',
            '\\bsource of this (content|chapter)\\b',
            '^(this )?((chapter|content) )?(is )?(first )?updated by\\b',
        ],
        'lines' => [
            '^pleasesupporttheauthor$',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure snapshots
    |--------------------------------------------------------------------------
    |
    | When a chapter page fetches but yields no/too little text, or a table
    | of contents comes back empty, the fetched HTML is gzipped to
    | {path}/{novel_id}/{chapter_id}-{reason}-{Ymd_His}.html.gz so the markup
    | can be inspected later (Novel page → Snapshots). keep_per_novel newest
    | files are kept per novel; anything older than `days` is pruned.
    |
    */
    'snapshots' => [
        'enabled' => env('NOVARR_SNAPSHOTS_ENABLED', true),
        'keep_per_novel' => (int) env('NOVARR_SNAPSHOTS_KEEP', 5),
        'days' => (int) env('NOVARR_SNAPSHOTS_DAYS', 14),
        'path' => storage_path('app/snapshots'),
    ],

    /*
    | The KOReader plugin folder the server offers for "update over Wi-Fi"
    | (GET /api/v1/koreader/plugin). Missing folder = the endpoints answer
    | 404 and the plugin says this server doesn't offer updates.
    */
    'koreader_plugin_path' => env('NOVARR_KOREADER_PLUGIN_PATH', base_path('koreader/novarr.koplugin')),

    /*
    | The Android app readers can install (GET /app/android, public). Put the
    | APK here out of band; an optional sidecar <path>.json next to it may
    | hold {"version": "1.0", "build": 1}. Missing file = no download offered
    | (App\Support\AndroidApp).
    */
    'android_apk_path' => env('NOVARR_ANDROID_APK', storage_path('app/apps/novarr.apk')),

    /*
    | Random politeness delay (milliseconds, [min, max]) before each chapter
    | fetch in chapterGenerator(). Tests set it to [0, 0].
    */
    'chapter_fetch_delay_ms' => [500, 1500],

    /*
    | Minutes a host's Cloudflare clearance (cf_clearance cookie + the user
    | agent it was issued to, from a FlareSolverr solve) is reused for plain
    | requests (App\Scraping\Fetcher::page()), shared between processes via
    | the application cache. 0 disables the store: every chapter page goes
    | through FlareSolverr as before. Only works while FlareSolverr and
    | Novarr reach the sites from the same public IP.
    */
    'clearance_minutes' => (int) env('NOVARR_CLEARANCE_MINUTES', 25),

    /*
    |--------------------------------------------------------------------------
    | MangaDex (App\Sources\MangaDexSource)
    |--------------------------------------------------------------------------
    |
    | Public API at api.mangadex.org (no account). `languages`: translated
    | languages the chapter list is built from (NOVARR_MANGADEX_LANGUAGES,
    | comma list, default "en"). MangaDex requires an honest User-Agent on
    | every request (no browser spoofing): `user_agent`.
    |
    */
    'mangadex' => [
        'languages' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOVARR_MANGADEX_LANGUAGES', 'en'))
        ), fn($l) => $l !== '')) ?: ['en'],
        'user_agent' => env('NOVARR_MANGADEX_USER_AGENT', 'Novarr/1.0'),
        'api' => 'https://api.mangadex.org',
        'uploads' => 'https://uploads.mangadex.org',
        // MangaDex@Home nodes: report every image fetched from a node that
        // is not on mangadex.org (their documented requirement).
        'report_url' => 'https://api.mangadex.network/report',
        // Pause between two API requests of one operation (TOC pages). The
        // documented global limit is ~5 requests/second per IP.
        'request_delay_ms' => (int) env('NOVARR_MANGADEX_REQUEST_DELAY_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Suwayomi-Server (App\Sources\SuwayomiSource)
    |--------------------------------------------------------------------------
    |
    | Manga fetched through the user's own Suwayomi-Server over its local
    | GraphQL API (no account). `url`: the server's base URL, e.g.
    | http://192.168.1.37:4567 (NOVARR_SUWAYOMI_URL; empty = the source is
    | off: it claims no URL, lists nothing on Discover and never makes a
    | request). `languages`: the Suwayomi source languages offered on
    | Discover (NOVARR_SUWAYOMI_LANGUAGES, comma list, default "en").
    | `exclude_sources`: Suwayomi source names left off Discover, case-
    | insensitive (NOVARR_SUWAYOMI_EXCLUDE_SOURCES; default MangaDex — Novarr
    | has its own MangaDex source — and the local source). `timeout`: seconds
    | a GraphQL call may take; a chapter list makes Suwayomi fetch from the
    | site and can take a minute (NOVARR_SUWAYOMI_TIMEOUT, default 90).
    |
    */
    'suwayomi' => [
        'url' => rtrim(trim((string) env('NOVARR_SUWAYOMI_URL', '')), '/'),
        'languages' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOVARR_SUWAYOMI_LANGUAGES', 'en'))
        ), fn($l) => $l !== '')) ?: ['en'],
        'exclude_sources' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOVARR_SUWAYOMI_EXCLUDE_SOURCES', 'MangaDex,Local source'))
        ), fn($n) => $n !== '')),
        'timeout' => (int) env('NOVARR_SUWAYOMI_TIMEOUT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manga page downloads (App\Scraping\PageDownloader)
    |--------------------------------------------------------------------------
    |
    | max_page_bytes — largest page image kept (bytes); a bigger one fails
    |                  the chapter's download.
    | page_delay_ms  — pause between two page image requests. Tests set 0.
    |
    | Fallback sources (App\Scraping\MangaFallback, `manga:fill-missing`,
    | hourly): a manga chapter its own source keeps failing on is downloaded
    | from the same series on another installed source (exact title match).
    | fallback_enabled            — the hourly job (the command still runs by hand).
    | fallback_after_attempts     — failed attempts before a chapter qualifies.
    | fallback_search_days        — re-search the other sources this often per series.
    | fallback_max_sources        — sources searched per series per run.
    | fallback_chapters_per_run   — chapters filled per series per run.
    | fallback_max_minutes        — total run time of one run.
    |
    */
    'manga' => [
        'max_page_bytes' => (int) env('NOVARR_MANGA_MAX_PAGE_BYTES', 30 * 1024 * 1024),
        'page_delay_ms' => (int) env('NOVARR_MANGA_PAGE_DELAY_MS', 200),
        'fallback_enabled' => (bool) env('NOVARR_MANGA_FALLBACK_ENABLED', true),
        'fallback_after_attempts' => (int) env('NOVARR_MANGA_FALLBACK_AFTER_ATTEMPTS', 3),
        'fallback_search_days' => (int) env('NOVARR_MANGA_FALLBACK_SEARCH_DAYS', 7),
        'fallback_max_sources' => (int) env('NOVARR_MANGA_FALLBACK_MAX_SOURCES', 8),
        'fallback_chapters_per_run' => (int) env('NOVARR_MANGA_FALLBACK_CHAPTERS_PER_RUN', 30),
        'fallback_max_minutes' => (int) env('NOVARR_MANGA_FALLBACK_MAX_MINUTES', 20),
    ],

    /*
    | Hosts whose Discover covers are served through Novarr
    | (discover.cover) because the host refuses hotlinked images.
    */
    'discover_cover_proxy_hosts' => ['uploads.mangadex.org'],

    /*
    |--------------------------------------------------------------------------
    | Reader requests (App\Services\Requests\*)
    |--------------------------------------------------------------------------
    |
    | Readers search every source of a medium and ask the owner to add a
    | series; the owner approves (the series is added as Discover adds it)
    | or declines.
    |
    | enabled                  — the feature (also reported by /api/v1/meta).
    | max_concurrent_searches  — request searches running at once, across
    |                            ALL accounts; one more is refused (429).
    | searches_per_window /    — per-account rate limit on starting a
    | search_window_minutes      search (default 12 per 10 minutes).
    | search_budget_seconds    — overall time a search may take; sources not
    |                            answered by then are skipped (timeout).
    | search_parallelism       — sources asked at the same time by one search,
    |                            each batch in its own PHP process
    |                            (App\Services\Requests\ProcessSearchRunner).
    |                            1 = all sources one after another in the
    |                            web process.
    | search_host_parallelism  — calls one host gets at the same time: the
    |                            sources on one host (every Suwayomi entry)
    |                            are spread over this many batches. 1 = one
    |                            after another.
    | search_cache_minutes     — a finished search's results are reused for
    |                            the same medium + query (by anyone) this
    |                            long; a search that skipped a source only 2
    |                            minutes. 0 = never reused.
    | max_sources_per_search   — sources asked per search (source_order
    |                            first, then Discover order).
    | max_pending_per_reader   — a reader's pending requests (the admin is
    |                            not limited).
    | max_candidates           — sources kept on a request (one per source).
    | rank_budget_seconds      — overall time evaluating a manga shortlist.
    | rank_tie_chapters /      — a shortlist's top tier: candidates within
    | rank_tie_percent           max(rank_tie_chapters, rank_tie_percent % of
    |                            the best count) chapters of the best count
    |                            are ordered by most recently updated, so a
    |                            stale source does not win on a handful of
    |                            chapters.
    | source_order             — preferred sources, best first: a source key
    |                            ("mangadex"), a Discover entry ("suwayomi:123")
    |                            or a base key covering all its entries
    |                            ("suwayomi"). Unlisted sources follow in
    |                            Discover order.
    | page_url                 — the web Requests page linked from the
    |                            owner's email (default APP_URL/requests).
    | browse                   — the Requests page's Discover shelf (manga
    |                            charts readers request from; RequestBrowse).
    | browse_cache_minutes     — how long one page of a chart is kept.
    |
    */
    'requests' => [
        'enabled' => (bool) env('NOVARR_REQUESTS', true),
        'max_concurrent_searches' => (int) env('NOVARR_REQUESTS_MAX_CONCURRENT_SEARCHES', 3),
        'searches_per_window' => (int) env('NOVARR_REQUESTS_SEARCHES_PER_WINDOW', 12),
        'search_window_minutes' => (int) env('NOVARR_REQUESTS_SEARCH_WINDOW_MINUTES', 10),
        'search_budget_seconds' => (int) env('NOVARR_REQUESTS_SEARCH_BUDGET_SECONDS', 90),
        'search_parallelism' => (int) env('NOVARR_REQUESTS_SEARCH_PARALLELISM', 5),
        'search_host_parallelism' => (int) env('NOVARR_REQUESTS_SEARCH_HOST_PARALLELISM', 4),
        // One source running longer than this is given up and its lane goes
        // on without it (ProcessSearchRunner); 0 = no per-source limit.
        'source_timeout_seconds' => (float) env('NOVARR_REQUESTS_SOURCE_TIMEOUT_SECONDS', 20),
        'search_cache_minutes' => (int) env('NOVARR_REQUESTS_SEARCH_CACHE_MINUTES', 10),
        'max_sources_per_search' => (int) env('NOVARR_REQUESTS_MAX_SOURCES_PER_SEARCH', 15),
        'max_pending_per_reader' => (int) env('NOVARR_REQUESTS_MAX_PENDING_PER_READER', 5),
        'max_candidates' => (int) env('NOVARR_REQUESTS_MAX_CANDIDATES', 8),
        'rank_budget_seconds' => (int) env('NOVARR_REQUESTS_RANK_BUDGET_SECONDS', 300),
        'rank_tie_chapters' => (int) env('NOVARR_REQUESTS_RANK_TIE_CHAPTERS', 3),
        'rank_tie_percent' => (float) env('NOVARR_REQUESTS_RANK_TIE_PERCENT', 2),
        'source_order' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOVARR_REQUESTS_SOURCE_ORDER', 'mangadex'))
        ), fn($k) => $k !== '')),
        // Sources source_order does not place, by display name (case-insensitive;
        // "MANGA Plus" also matches "MANGA Plus by SHUEISHA"); the rest follow
        // in Discover order. RequestSearch::searchOrder().
        'source_name_preference' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOVARR_REQUESTS_SOURCE_NAME_PREFERENCE', 'MangaDex,MANGA Plus,Asura Scans,Flame Comics,MangaFire,Weeb Central,Webtoons,TCB Scans,Toonily,MangaPark,Mangakakalot,MangaPill,Kagane,MangaKatana,Manga Demon,Atsumaru,Mangago,MangaHub,MangaFox'))
        ), fn($n) => $n !== '')),
        // Mirrors of one site: only the best-placed installed member is
        // searched. Env: families split by ";", members by "|".
        'source_families' => array_values(array_filter(array_map(
            fn($family) => array_values(array_filter(array_map('trim', explode('|', $family)), fn($n) => $n !== '')),
            explode(';', (string) env('NOVARR_REQUESTS_SOURCE_FAMILIES', 'Mangakakalot|Manganato|Mangabat;MangaFox|Mangahere|Mangatown'))
        ), fn($family) => count($family) > 1)),
        'page_url' => env('NOVARR_REQUESTS_URL'),
        'browse' => (bool) env('NOVARR_REQUESTS_BROWSE', true),
        'browse_cache_minutes' => (int) env('NOVARR_REQUESTS_BROWSE_CACHE_MINUTES', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings-page defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks for the DB-backed settings (Settings page / setting()) that
    | have no other config home. A blank field on the Settings page falls
    | back to these. Snapshot retention defaults live under `snapshots`.
    |
    */
    'defaults' => [
        'scrape_min_delay' => 30,
        'scrape_max_delay' => 90,
        // Adaptive per-host pace (App\Scraping\HostPace): speeds a host up
        // after clean requests down to scrape_fastest_delay, backs off by
        // itself on a rate limit. Off = the plain random min..max delay.
        'scrape_adaptive' => true,
        'scrape_fastest_delay' => 15,
        'min_chapter_words' => 250,
        // 0 = no per-novel cap.
        'max_chapters_per_novel_per_run' => 25,
        // How long one download-lane run keeps starting chapters (at most
        // 140); what is left waits for the lane's next run.
        'max_run_minutes' => 100,
        // Download lanes (one source each) downloading at the same time.
        'max_parallel_lanes' => 6,
        // Mirrors App\Scraping\NovelUpdatesMatcher::THRESHOLD.
        'novelupdates_match_threshold' => 0.85,
    ],

    /*
    |--------------------------------------------------------------------------
    | Source health (App\Services\SourceHealth, Health → Sources)
    |--------------------------------------------------------------------------
    |
    | Each source's chapter downloads, chapter-list walks and searches are
    | counted per hour. A kind of call is judged over the last 24 hours once
    | it has min_attempts: Failing below failing_below of them ok, Degraded
    | below degraded_below. Rate-limited failures alone never make a source
    | Failing (that is the scraper's pace, not a broken site): it is at worst
    | Degraded. A source turning Failing is told to the admin once
    | (sources:health-check, hourly), not again for alert_repeat_hours.
    |
    */
    'source_health' => [
        'min_attempts' => (int) env('NOVARR_SOURCE_HEALTH_MIN_ATTEMPTS', 20),
        'failing_below' => (float) env('NOVARR_SOURCE_HEALTH_FAILING_BELOW', 0.5),
        'degraded_below' => (float) env('NOVARR_SOURCE_HEALTH_DEGRADED_BELOW', 0.85),
        'retention_days' => (int) env('NOVARR_SOURCE_HEALTH_RETENTION_DAYS', 90),
        'alert_repeat_hours' => (int) env('NOVARR_SOURCE_HEALTH_ALERT_REPEAT_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage usage (App\Services\StorageUsage, Health → Storage)
    |--------------------------------------------------------------------------
    |
    | storage:measure (daily) records where the disk goes. low_free_percent:
    | a volume with less free space than this (percent of its size) shows a
    | warning, and the admin is told once when a measurement first finds it
    | (not again within 24 hours).
    |
    */
    'storage' => [
        'low_free_percent' => (float) env('NOVARR_STORAGE_LOW_FREE_PERCENT', 10),
    ],
];
