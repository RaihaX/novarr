<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Books readers upload (App\Books\BookUploads): a request of medium 'book'
 * whose ePub is parked until the owner matches and approves it.
 *
 *   novel_requests.upload_path  the parked ePub, relative to the books folder
 *                               ("pending/{uuid}.epub"); null for every other request
 *   novel_requests.upload_meta  what the file says about itself (title,
 *                               authors, ISBN, language, chapter labels, an
 *                               excerpt, the cover's public path), JSON
 *
 * Both nullable, added at the end of a small table. Guarded so a repeated
 * run is harmless (MariaDB DDL auto-commits).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('novel_requests', 'upload_path')) {
            Schema::table('novel_requests', function (Blueprint $table) {
                $table->string('upload_path', 255)->nullable();
            });
        }
        if (!Schema::hasColumn('novel_requests', 'upload_meta')) {
            Schema::table('novel_requests', function (Blueprint $table) {
                $table->longText('upload_meta')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('novel_requests', function (Blueprint $table) {
            $table->dropColumn(['upload_path', 'upload_meta']);
        });
    }
};
