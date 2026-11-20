<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fallback sources for manga chapters that keep failing on their own source
 * (App\Scraping\MangaFallback, `php artisan manga:fill-missing`):
 *
 *  - manga_alternates: per series, the same series found on another
 *    installed source (exact title match only), in search-preference order,
 *    with its failure count;
 *  - manga_alternate_searches: per series, where the search through the
 *    other sources stands (a cycle walks them a few at a time) and the title
 *    the series' own source reports for it (cached here, not asked per run);
 *  - novel_chapters.filled_from / filled_at: which source a chapter's pages
 *    came from when it was not its own. The row's url never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('manga_alternates')) {
            Schema::create('manga_alternates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('novel_id');
                $table->string('source_key', 64);          // "suwayomi:<sourceId>" | "mangadex"
                $table->string('source_name', 191);        // display name ("Weeb Central", "MangaDex")
                $table->text('series_url');                // the series on that source
                $table->string('title', 255);              // the title as found there
                $table->unsignedSmallInteger('rank')->default(0); // search-preference position
                $table->unsignedInteger('chapter_count')->default(0);
                $table->decimal('max_chapter', 10, 3)->nullable();
                $table->timestamp('found_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('last_failed_at')->nullable();
                $table->unsignedSmallInteger('failures')->default(0); // consecutive
                $table->timestamps();

                $table->unique(['novel_id', 'source_key'], 'uniq_manga_alternates_novel_source');
            });
        }

        if (!Schema::hasTable('manga_alternate_searches')) {
            Schema::create('manga_alternate_searches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('novel_id')->unique('uniq_manga_alternate_searches_novel');
                $table->text('source_titles')->nullable();       // JSON: titles the own source reports
                $table->unsignedSmallInteger('cursor')->default(0); // next source to search in this cycle
                $table->unsignedSmallInteger('sources_total')->default(0);
                $table->timestamp('cycle_started_at')->nullable();
                $table->timestamp('cycle_completed_at')->nullable();
                $table->timestamp('searched_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('novel_chapters', function (Blueprint $table) {
            if (!Schema::hasColumn('novel_chapters', 'filled_from')) {
                $table->string('filled_from', 191)->nullable();
            }
            if (!Schema::hasColumn('novel_chapters', 'filled_at')) {
                $table->timestamp('filled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manga_alternates');
        Schema::dropIfExists('manga_alternate_searches');

        Schema::table('novel_chapters', function (Blueprint $table) {
            foreach (['filled_from', 'filled_at'] as $column) {
                if (Schema::hasColumn('novel_chapters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
