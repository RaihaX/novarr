<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a read came from (App\Support\ReadingClient), for the admin's
 * Readers pages:
 *  - chapter_reads: ip, device, client, public — stamped when the chapter
 *    is marked read (App\Services\ReadState), cleared when marked unread;
 *  - reading_positions: ip, client, public next to the existing
 *    device_name (App\Services\ReadingPositions).
 *
 * Rows from before stay empty (null / not public). The address is cleared
 * after NOVARR_AUTH_LOG_DAYS by novarr:prune-auth-log. No indexes: the
 * Readers pages read these columns off rows found by (user_id, read_at).
 * Guarded per column, so an interrupted run can be repeated (MariaDB DDL
 * auto-commits).
 */
return new class extends Migration
{
    /** table => column => definition */
    private function columns(): array
    {
        return [
            'chapter_reads' => [
                'ip' => fn(Blueprint $t) => $t->string('ip', 45)->nullable(),
                'device' => fn(Blueprint $t) => $t->string('device', 120)->nullable(),
                'client' => fn(Blueprint $t) => $t->string('client', 60)->nullable(),
                'public' => fn(Blueprint $t) => $t->boolean('public')->default(false),
            ],
            'reading_positions' => [
                'ip' => fn(Blueprint $t) => $t->string('ip', 45)->nullable(),
                'client' => fn(Blueprint $t) => $t->string('client', 60)->nullable(),
                'public' => fn(Blueprint $t) => $t->boolean('public')->default(false),
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->columns() as $table => $columns) {
            foreach ($columns as $column => $define) {
                if (!Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn(Blueprint $t) => $define($t));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columns() as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn(Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
