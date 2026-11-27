<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI covers (App\Covers, `covers:generate`):
 *
 *   novels.cover_generated_at   when the current cover was painted (null = it
 *                               was not); cover_source is 'generated' then.
 *   novels.cover_brief          the brief it was painted from (JSON: genre,
 *                               setting, mood, …, the SDXL prompt and seed,
 *                               whether the LLM wrote it or the genre
 *                               fallback did), so the owner can see why.
 *   novels.cover_ai_checked_at  when `covers:generate` last found a real
 *                               cover on the series, so the nightly run does
 *                               not decode every cover of the library every
 *                               night (`--id` looks again). Indexed.
 *
 * Nullable, at the end; guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'cover_generated_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('cover_generated_at')->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'cover_brief')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->text('cover_brief')->nullable();
            });
        }
        if (!Schema::hasColumn('novels', 'cover_ai_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->timestamp('cover_ai_checked_at')->nullable()->index('novels_cover_ai_checked_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'cover_ai_checked_at')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropIndex('novels_cover_ai_checked_at_index');
            });
        }
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn(['cover_generated_at', 'cover_brief', 'cover_ai_checked_at']);
        });
    }
};
