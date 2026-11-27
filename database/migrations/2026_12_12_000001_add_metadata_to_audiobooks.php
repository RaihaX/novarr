<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audiobook metadata from the catalogues (App\Audiobooks\AudiobookEnricher):
 *
 *   asin             the Audible product it was matched to (indexed)
 *   metadata_source  who filled what the files left out: 'audible', 'itunes'
 *                    (a cover only); null = the files' tags only
 *   enriched_at      when it was last looked up (match or not); null = never
 *   chapters_source  where its chapters came from: 'embedded' (the files'
 *                    own, never replaced), 'files' (one per file / "Part 1"
 *                    placeholders), 'audible' (Audnexus' names and times);
 *                    null = imported before this was recorded (worked out
 *                    from the chapters themselves)
 *   cover_source     'files' (embedded / folder picture), 'audible',
 *                    'itunes'; null = none or unknown
 *   series / series_index  the Audible series and the book's number in it
 *
 * All nullable, added at the end; guarded so a repeated run is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'asin' => fn(Blueprint $t) => $t->string('asin', 16)->nullable()->index('idx_audiobooks_asin'),
            'metadata_source' => fn(Blueprint $t) => $t->string('metadata_source', 16)->nullable(),
            'enriched_at' => fn(Blueprint $t) => $t->timestamp('enriched_at')->nullable(),
            'chapters_source' => fn(Blueprint $t) => $t->string('chapters_source', 16)->nullable(),
            'cover_source' => fn(Blueprint $t) => $t->string('cover_source', 16)->nullable(),
            'series' => fn(Blueprint $t) => $t->string('series', 255)->nullable(),
            'series_index' => fn(Blueprint $t) => $t->decimal('series_index', 8, 2)->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('audiobooks', $name)) {
                Schema::table('audiobooks', $add);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audiobooks', 'asin')) {
            Schema::table('audiobooks', fn(Blueprint $table) => $table->dropIndex('idx_audiobooks_asin'));
        }
        Schema::table('audiobooks', function (Blueprint $table) {
            $table->dropColumn(['asin', 'metadata_source', 'enriched_at', 'chapters_source', 'cover_source', 'series', 'series_index']);
        });
    }
};
