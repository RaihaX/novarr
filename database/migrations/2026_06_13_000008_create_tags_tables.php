<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A leftover (empty) Voyager `tags` table may already exist with the
        // same shape — reuse it rather than collide.
        if (!Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        // A partial run may have left this table behind — drop it so we
        // recreate cleanly with the right (FK-free) shape.
        Schema::dropIfExists('novel_tag');

        Schema::create('novel_tag', function (Blueprint $table) {
            // No foreign-key constraints: novels.id on older installs is a
            // plain INT while these default to BIGINT, which makes MySQL
            // reject the FK (errno 150). The pivot is managed in app code,
            // so a composite primary key + tag index is enough.
            $table->unsignedBigInteger('novel_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['novel_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_tag');
        Schema::dropIfExists('tags');
    }
};
