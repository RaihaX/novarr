<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Me too": readers who joined someone else's request
 * (App\NovelRequestFollower). One row per request and reader; the
 * requester is never a follower of their own request. Followers hear the
 * same decisions as the requester (App\Services\Requests\RequestNotifier).
 *
 * No foreign keys, like novel_requests: rows go with their request
 * (Requests::delete(), the NovelRequest hook) and with their reader (the
 * User hook). Short integer columns only. Guarded so an interrupted run can
 * be repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('novel_request_followers')) {
            return;
        }

        Schema::create('novel_request_followers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('novel_request_id');
            $table->unsignedInteger('user_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['novel_request_id', 'user_id'], 'uniq_novel_request_followers');
            $table->index(['user_id', 'novel_request_id'], 'idx_novel_request_followers_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_request_followers');
    }
};
