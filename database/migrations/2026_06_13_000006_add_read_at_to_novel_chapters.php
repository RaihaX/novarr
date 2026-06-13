<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            // When the reader last opened this chapter. Null = unread.
            $table->timestamp('read_at')->nullable()->after('download_date');
            // Find the first unread / count read chapters per novel quickly.
            $table->index(['novel_id', 'read_at'], 'idx_novel_read');
        });
    }

    public function down(): void
    {
        Schema::table('novel_chapters', function (Blueprint $table) {
            $table->dropIndex('idx_novel_read');
            $table->dropColumn('read_at');
        });
    }
};
