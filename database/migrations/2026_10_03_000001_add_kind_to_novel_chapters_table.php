<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            // null = a proper chapter; 'note' = an author/translator message
            // or short special accepted below the word-count threshold.
            $table->string('kind', 16)->nullable()->after('double_chapter');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
