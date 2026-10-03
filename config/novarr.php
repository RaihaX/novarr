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

return [
    'flaresolverr_url' => env('FLARESOLVERR_URL', 'http://192.168.1.41:8191/v1'),
    // Verify TLS certificates on every scraper request (App\Scraping\Fetcher).
    // Only turn off for a source with a broken certificate chain.
    'tls_verify' => (bool) env('NOVARR_TLS_VERIFY', true),
    'notification_webhook_url' => env('NOTIFICATION_WEBHOOK_URL'),

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
];
