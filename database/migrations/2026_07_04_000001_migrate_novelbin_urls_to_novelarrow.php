<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * novelbin rebranded to novelarrow.com with a new URL scheme. Slugs are
 * unchanged (verified against the live api-web endpoints for every stored
 * chapter), so URLs rewrite mechanically:
 *
 *   novel pages    …novelbin…?novelId={slug} | …/novel-book/{slug} | …/b/{slug}
 *                    → https://novelarrow.com/novel/{slug}
 *   chapter pages  novelbin.com/b/{s}/{c} | novelbin.me/novel-book/{s}/{c}
 *                  | novelbin.lanovels.net/book/{s}/{c}?subsite=1
 *                    → https://novelarrow.com/chapter/{s}/{c}
 *
 * Statements stay portable (the test suite runs migrations on SQLite): the
 * handful of novel rows are rewritten in PHP, the bulk chapter rows via
 * REPLACE(), which both drivers support.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Novels — few rows; derive the slug in PHP to cover every URL shape.
        $novels = DB::table('novels')
            ->where('translator_url', 'like', '%novelbin%')
            ->get(['id', 'translator_url']);

        foreach ($novels as $novel) {
            parse_str(parse_url($novel->translator_url, PHP_URL_QUERY) ?: '', $query);
            $slug = $query['novelId']
                ?? basename(rtrim(parse_url($novel->translator_url, PHP_URL_PATH) ?: '', '/'));

            if ($slug === '' || $slug === false) {
                continue;
            }

            DB::table('novels')
                ->where('id', $novel->id)
                ->update(['translator_url' => "https://novelarrow.com/novel/{$slug}"]);
        }

        // Renamed on the new site (verified: old slug 404s, new slug 200s).
        DB::table('novels')
            ->where('translator_url', 'https://novelarrow.com/novel/magic-emperor')
            ->update(['translator_url' => 'https://novelarrow.com/novel/the-steward-demonic-emperor']);

        // Chapters — prefix swaps; slugs and chapter ids carry over as-is.
        DB::statement("
            UPDATE novel_chapters
            SET url = REPLACE(url, 'https://novelbin.com/b/', 'https://novelarrow.com/chapter/')
            WHERE url LIKE 'https://novelbin.com/b/%'
        ");
        DB::statement("
            UPDATE novel_chapters
            SET url = REPLACE(url, 'https://novelbin.me/novel-book/', 'https://novelarrow.com/chapter/')
            WHERE url LIKE 'https://novelbin.me/novel-book/%'
        ");
        DB::statement("
            UPDATE novel_chapters
            SET url = REPLACE(url, 'https://novelbin.lanovels.net/book/', 'https://novelarrow.com/chapter/')
            WHERE url LIKE 'https://novelbin.lanovels.net/book/%'
        ");

        // The lanovels mirror URLs also carried a ?subsite=1 query — few
        // rows, so strip it in PHP (portable).
        $withQuery = DB::table('novel_chapters')
            ->where('url', 'like', 'https://novelarrow.com/chapter/%?%')
            ->get(['id', 'url']);

        foreach ($withQuery as $chapter) {
            DB::table('novel_chapters')
                ->where('id', $chapter->id)
                ->update(['url' => strtok($chapter->url, '?')]);
        }
    }

    public function down(): void
    {
        // novelbin.com no longer resolves — there is nothing to roll back to.
    }
};
