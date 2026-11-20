<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last storage measurement (App\Services\StorageUsage, written by
 * `php artisan storage:measure`): one row per series, per bucket (backups,
 * logs, the database…), per disk volume and one for the run itself. The
 * whole set is replaced by each measurement, so the table stays a few
 * hundred rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storage_usage')) {
            return;
        }

        Schema::create('storage_usage', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);              // series | bucket | disk | run
            $table->string('key', 191);               // novel id, bucket key, volume key, "last"
            $table->string('medium', 16)->nullable(); // series only: novel | manga
            $table->unsignedBigInteger('novel_id')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('files')->default(0);
            $table->unsignedInteger('chapters')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('measured_at')->nullable();

            $table->unique(['scope', 'key'], 'uniq_storage_usage_scope_key');
            $table->index(['scope', 'bytes'], 'idx_storage_usage_scope_bytes');
            $table->index('novel_id', 'idx_storage_usage_novel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_usage');
    }
};
