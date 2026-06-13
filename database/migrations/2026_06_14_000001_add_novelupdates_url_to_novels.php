<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            // Explicit NovelUpdates series URL, used for metadata instead of
            // guessing from the name. Auto-filled when search resolves an
            // alias; editable on the novel page.
            $table->string('novelupdates_url', 2048)->nullable()->after('translator_url');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropColumn('novelupdates_url');
        });
    }
};
