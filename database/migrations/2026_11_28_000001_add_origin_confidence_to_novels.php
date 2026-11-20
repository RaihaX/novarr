<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * novels.origin_confidence — how sure the origin is: high | medium | low,
 * null when it was never rated (rows set before this column, NovelUpdates
 * and manual rows from before it). Written by OriginInference (inferred),
 * the manga sources (source) and the edit form (manual = high).
 * Novel::originCountry() hides an inferred origin that is not at least
 * medium, so an unrated or low guess never shows as a country badge.
 *
 * origin_source gains 'source' (the series' own source said so: MangaDex's
 * originalLanguage, a Suwayomi Manhwa/Manhua/Manga genre, Royal Road);
 * the column is already a string(16), so nothing changes in its type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novels', 'origin_confidence')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->string('origin_confidence', 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('novels', 'origin_confidence')) {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropColumn('origin_confidence');
            });
        }
    }
};
