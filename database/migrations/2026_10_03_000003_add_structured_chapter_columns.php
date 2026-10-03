<?php

use App\Scraping\ChapterLabelParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A4: structured chapter columns so `chapter` no longer doubles as
 * identity, sort key and part encoding. `chapter` stays populated as before
 * (compatibility); readers order by (book, sort_key, chapter, id).
 *
 * The backfill lives in ChapterLabelParser::backfill() (tested there): it
 * reproduces the current (book, chapter, id) order for every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded per column so a run that failed partway (e.g. during the
        // backfill on MySQL) can be re-run without "Duplicate column".
        Schema::table('novel_chapters', function (Blueprint $table) {
            if (!Schema::hasColumn('novel_chapters', 'number')) {
                $table->decimal('number', 10, 3)->nullable()->after('chapter');
            }
            if (!Schema::hasColumn('novel_chapters', 'part')) {
                $table->smallInteger('part')->default(0)->after('number');
            }
            if (!Schema::hasColumn('novel_chapters', 'title')) {
                $table->string('title', 255)->nullable()->after('label');
            }
            if (!Schema::hasColumn('novel_chapters', 'source_label')) {
                $table->string('source_label', 255)->nullable()->after('title');
            }
            if (!Schema::hasColumn('novel_chapters', 'sort_key')) {
                $table->decimal('sort_key', 14, 4)->nullable()->after('part');
                $table->index(['novel_id', 'book', 'sort_key'], 'idx_novel_book_sort_key');
            }
        });

        // URL is a chapter's identity, but it is NOT made unique: the TOC sync
        // deliberately creates a fresh row for a URL whose only row was
        // soft-deleted (TocSyncTest::testSoftDeletedRowUrlIsRecreated), and a
        // unique index covers trashed rows too. Plain index; duplicates logged.
        $duplicates = DB::table('novel_chapters')
            ->whereNotNull('url')
            ->select('novel_id', 'url')
            ->groupBy('novel_id', 'url')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        if ($duplicates > 0) {
            Log::warning("novel_chapters: {$duplicates} (novel_id, url) pair(s) appear on more than one row (incl. soft-deleted); (novel_id, url) index created non-unique");
        }
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->index(['novel_id', 'url'], 'idx_novel_url');
        });

        ChapterLabelParser::backfill(null, 1000);
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_url');
            $table->dropIndex('idx_novel_book_sort_key');
        });
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropColumn(['number', 'part', 'title', 'source_label', 'sort_key']);
        });
    }
};
