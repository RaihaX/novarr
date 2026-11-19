<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader requests (App\NovelRequest): a reader asks the owner to add a novel
 * or manga; the owner approves (the series is added to the library) or
 * declines. request_notifications is the polling feed of the phone apps
 * (GET /api/v1/notifications): one row per event, addressed to a user, or to
 * the admins when user_id is null.
 *
 * user_id is never null: with sign-in off every request is the owner's (as
 * chapter_reads / bookmarks). No foreign keys, like those tables: rows are
 * cleaned up by the application (User / Novel model hooks).
 *
 * Only short columns are indexed (MariaDB's key limit): URLs (cover_url,
 * link, the candidates JSON) never are. Guarded so an interrupted run can be
 * repeated (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('novel_requests')) {
            Schema::create('novel_requests', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->string('medium', 16);
                $table->string('title', 255);
                $table->string('normalized_title', 255);
                $table->string('author', 255)->nullable();
                $table->text('cover_url')->nullable();
                $table->text('description')->nullable();
                $table->string('note', 500)->nullable();
                $table->text('link')->nullable();
                $table->string('status', 16)->default('pending');
                $table->longText('candidates')->nullable();
                $table->timestamp('candidates_ranked_at')->nullable();
                $table->unsignedInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decline_reason', 500)->nullable();
                $table->unsignedInteger('novel_id')->nullable();
                $table->timestamp('available_at')->nullable();
                $table->timestamp('requester_notified_at')->nullable();
                $table->timestamps();

                $table->index(['normalized_title', 'medium', 'status'], 'idx_novel_requests_title');
                $table->index(['user_id', 'status'], 'idx_novel_requests_user_status');
                $table->index(['status', 'available_at'], 'idx_novel_requests_status_available');
                $table->index('novel_id', 'idx_novel_requests_novel');
            });
        }

        if (!Schema::hasTable('request_notifications')) {
            Schema::create('request_notifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                // The recipient; null = every admin.
                $table->unsignedInteger('user_id')->nullable();
                $table->unsignedBigInteger('novel_request_id')->nullable();
                $table->string('kind', 32);
                $table->string('title', 255);
                $table->string('body', 1000);
                $table->unsignedInteger('novel_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['user_id', 'id'], 'idx_request_notifications_user');
                $table->index('novel_request_id', 'idx_request_notifications_request');
                $table->index('created_at', 'idx_request_notifications_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_notifications');
        Schema::dropIfExists('novel_requests');
    }
};
