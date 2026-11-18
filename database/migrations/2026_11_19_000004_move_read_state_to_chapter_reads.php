<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copies the library's read state — novel_chapters.read_at / read_progress /
 * read_progress_at — into chapter_reads as the owner's (app setting
 * owner_user_id). Every chapter with any of the three set is copied,
 * soft-deleted ones included.
 *
 * Safe to re-run: rows are inserted with insert-or-ignore, so a row the app
 * has written since (newer state) is never overwritten. Afterwards every
 * legacy row must have its owner row, or the migration throws.
 *
 * The three novel_chapters columns and their indexes STAY in place, no longer
 * read or written, so a rollback to the previous release works; a later
 * release drops them. down() copies the owner's chapter_reads back into them.
 */
return new class extends Migration
{
    /** Chapter-id range copied per statement (~252k chapters, ~11k read). */
    private const CHUNK = 20000;

    public function up(): void
    {
        $owner = $this->ownerId();

        $maxId = (int) DB::table('novel_chapters')->max('id');
        for ($from = 0; $from <= $maxId; $from += self::CHUNK) {
            DB::table('chapter_reads')->insertOrIgnoreUsing(
                ['user_id', 'novel_id', 'novel_chapter_id', 'read_at', 'progress', 'progress_at'],
                $this->legacyRows()
                    ->where('id', '>', $from)
                    ->where('id', '<=', $from + self::CHUNK)
                    ->selectRaw('?, novel_id, id, read_at, read_progress, read_progress_at', [$owner])
            );
        }

        $missing = $this->legacyRows()
            ->whereNotExists(fn($q) => $q->from('chapter_reads')
                ->selectRaw('1')
                ->where('chapter_reads.user_id', $owner)
                ->whereColumn('chapter_reads.novel_chapter_id', 'novel_chapters.id'))
            ->count();
        if ($missing > 0) {
            throw new RuntimeException("chapter_reads: {$missing} chapters' read state was not copied to the owner.");
        }
    }

    /**
     * The owner's state back into the legacy columns: chapters without an
     * owner row are cleared, the rest copied (other readers' rows are not
     * representable there and are left in chapter_reads until it is dropped).
     */
    public function down(): void
    {
        $owner = $this->ownerId();
        $ownerChapters = fn($q) => $q->from('chapter_reads')->select('novel_chapter_id')->where('user_id', $owner);

        DB::table('novel_chapters')
            ->where(fn($q) => $q->whereNotNull('read_at')->orWhereNotNull('read_progress')->orWhereNotNull('read_progress_at'))
            ->whereNotIn('id', $ownerChapters)
            ->update(['read_at' => null, 'read_progress' => null, 'read_progress_at' => null]);

        $column = fn(string $name) => DB::raw("(SELECT r.{$name} FROM chapter_reads r WHERE r.user_id = " . $owner
            . ' AND r.novel_chapter_id = novel_chapters.id)');

        DB::table('novel_chapters')
            ->whereIn('id', $ownerChapters)
            ->update([
                'read_at' => $column('read_at'),
                'read_progress' => $column('progress'),
                'read_progress_at' => $column('progress_at'),
            ]);
    }

    /** novel_chapters rows (soft-deleted included) carrying any legacy read state. */
    private function legacyRows()
    {
        return DB::table('novel_chapters')
            ->where(fn($q) => $q->whereNotNull('read_at')->orWhereNotNull('read_progress')->orWhereNotNull('read_progress_at'));
    }

    private function ownerId(): int
    {
        $owner = (int) DB::table('app_settings')->where('key', 'owner_user_id')->value('value');
        if ($owner <= 0) {
            throw new RuntimeException('No owner_user_id setting: run 2026_11_19_000001 first.');
        }

        return $owner;
    }
};
