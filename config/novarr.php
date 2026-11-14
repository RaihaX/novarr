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
        'min_chapter_words' => 250,
        // 0 = no per-novel cap.
        'max_chapters_per_novel_per_run' => 25,
        // Must stay below the 150-minute withoutOverlapping() lock on the
        // scheduled novel:chapter run, or a run can still be going when the
        // lock expires and a second run starts on top of it.
        'max_run_minutes' => 100,
        // Mirrors App\Scraping\NovelUpdatesMatcher::THRESHOLD.
        'novelupdates_match_threshold' => 0.85,
    ],
];
