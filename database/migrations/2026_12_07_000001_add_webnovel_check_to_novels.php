<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Books that are web novels (App\Books\WebNovelCheck, `books:webnovel-check`):
 *
 *   novels.webnovel_checked_at  when the book was looked up on NovelUpdates
 *                               (match or not); null = not checked yet. A
 *                               failed lookup leaves it null so it is tried
 *                               again. Indexed: the nightly run picks the
 *                               unchecked books, oldest first.
 *   novels.converted_from       'book' when the row was moved from Books to
 *                               Novels by that check; null otherwise.
 *   novels.converted_snapshot   JSON of what the conversion replaced (title,
 *                               author, description, origin, tags, cover…),
 *                               so `--undo=` puts the book back exactly.
 *   novels.alternative_titles   JSON list of the series' other names (the
 *                               NovelUpdates "Associated Names"); the Library
 *                               search looks in it too.
 *
 * All nullable, added at the end. Guarded so a repeated run is harmless
 * (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'webnovel_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('webnovel_checked_at')->nullable()->index('novels_webnovel_checked_at_index');
            });
        }
        if (!Schema::hasColumn('novels', 'converted_from')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('converted_from', 16)->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'converted_snapshot')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->json('converted_snapshot')->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'alternative_titles')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->json('alternative_titles')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'webnovel_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('novels_webnovel_checked_at_index');
            });
        }
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['webnovel_checked_at', 'converted_from', 'converted_snapshot', 'alternative_titles']);
        });
    }
};
