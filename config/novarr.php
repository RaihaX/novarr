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
        'empirenovel' => \App\Sources\EmpireNovelSource::class,
        'novelfull' => \App\Sources\NovelFullSource::class,
        'novelarrow' => \App\Sources\NovelArrowSource::class,
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
    */
    'manga' => [
        'max_page_bytes' => (int) env('NOVARR_MANGA_MAX_PAGE_BYTES', 30 * 1024 * 1024),
        'page_delay_ms' => (int) env('NOVARR_MANGA_PAGE_DELAY_MS', 200),
    ],

    /*
    | Hosts whose Discover covers are served through Novarr
    | (discover.cover) because the host refuses hotlinked images.
    */
    'discover_cover_proxy_hosts' => ['uploads.mangadex.org'],

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
];
