<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The categories a reader chose when asking for a series that is not in
 * the library yet (a long-press in Browse → "Add to category"): when the
 * request becomes available (App\Services\Requests\Requests::markAvailable*)
 * the series is put in each of them, for every reader who chose some (the
 * requester and "Me too" followers). No foreign keys, as the followers
 * table; rows go with their request, reader or category.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('novel_request_categories')) {
            return;
        }
        Schema::create('novel_request_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('novel_request_id');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('reading_list_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['novel_request_id', 'user_id', 'reading_list_id'], 'uniq_novel_request_categories');
            $table->index(['user_id', 'novel_request_id'], 'idx_novel_request_categories_user');
            $table->index('reading_list_id', 'idx_novel_request_categories_list');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_request_categories');
    }
};
