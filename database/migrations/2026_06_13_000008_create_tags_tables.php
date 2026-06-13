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

        if (!Schema::hasTable('novel_tag')) {
            Schema::create('novel_tag', function (Blueprint $table) {
                $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
                $table->primary(['novel_id', 'tag_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_tag');
        Schema::dropIfExists('tags');
    }
};
