<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Books that are web fiction by their own ePub (App\Books\WebFictionEvidence)
 * and why a book was moved to Novels (App\Books\WebNovelCheck):
 *
 *   novels.webfiction_checked_at  when the book's ePub was read for
 *                                 web-fiction evidence (found or not); null
 *                                 = not yet. Separate from
 *                                 webnovel_checked_at (the NovelUpdates /
 *                                 Royal Road lookup), so books looked up
 *                                 before this pass existed still get it.
 *   novels.converted_reason       what moved it ("web fiction from
 *                                 storiesonline.net", "listed on
 *                                 NovelUpdates", "on Royal Road"), shown on
 *                                 its page; null when not converted.
 *   novels.webnovel_kept_at       the owner put a converted book back with
 *                                 Undo: it stays a book, even for --all.
 *   novels.web_source_url         where the web fiction was published (the
 *                                 ePub's dc:source, the Royal Road fiction
 *                                 page): translator_url stays the book://
 *                                 identity, so this is the page's source
 *                                 link.
 *
 * Earlier conversions (NovelUpdates only) get their reason. Guarded so a
 * repeated run is harmless (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'webfiction_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('webfiction_checked_at')->nullable()->index('novels_webfiction_checked_at_index');
            });
        }
        if (!Schema::hasColumn('novels', 'converted_reason')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('converted_reason', 191)->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'webnovel_kept_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('webnovel_kept_at')->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'web_source_url')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('web_source_url', 500)->nullable();
            });
        }

        DB::table('novels')->where('converted_from', 'book')->whereNull('converted_reason')
            ->update(['converted_reason' => 'listed on NovelUpdates']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'webfiction_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('novels_webfiction_checked_at_index');
            });
        }
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['webfiction_checked_at', 'converted_reason', 'web_source_url', 'webnovel_kept_at']);
        });
    }
};
