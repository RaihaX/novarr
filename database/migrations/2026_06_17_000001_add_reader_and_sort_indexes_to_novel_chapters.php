<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            // The novels-list "updated" sort runs a correlated subselect
            //   (... where novel_id = novels.id order by download_date desc limit 1)
            // and NovelHealth computes MAX(download_date) per novel. The existing
            // lone idx_download_date can't seek by novel_id, so each lookup
            // scanned. This composite serves both as an index-only seek.
            $table->index(['novel_id', 'download_date'], 'idx_novel_download_date');

            // The reader's prev/next navigation and the novel-page chapter list
            // order by (book, chapter). The existing idx_novel_chapter_book is
            // (novel_id, chapter, book) — wrong column order for that access
            // pattern. This matches it so boundary seeks are index-served.
            $table->index(['novel_id', 'book', 'chapter'], 'idx_novel_book_chapter');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_download_date');
            $table->dropIndex('idx_novel_book_chapter');
        });
    }
};
