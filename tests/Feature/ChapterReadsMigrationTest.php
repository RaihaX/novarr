<?php

namespace Tests\Feature;

use App\Support\CurrentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Multi-user stage 1 migrations (2026_11_19_000001..4): users gain roles and
 * an owner; reading positions and highlights become the owner's; every
 * chapter's legacy read state (novel_chapters.read_at / read_progress /
 * read_progress_at, soft-deleted chapters included) is copied to the owner's
 * chapter_reads. Re-runnable; down() restores the legacy state.
 *
 * This test deliberately reads and writes the legacy novel_chapters columns.
 */
class ChapterReadsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $name)
    {
        return require database_path("migrations/{$name}.php");
    }

    private function users()
    {
        return $this->migration('2026_11_19_000001_add_roles_to_users_table');
    }

    private function ownership()
    {
        return $this->migration('2026_11_19_000003_add_user_id_to_reading_positions_and_bookmarks');
    }

    private function copy()
    {
        return $this->migration('2026_11_19_000004_move_read_state_to_chapter_reads');
    }

    private function novel(string $name): int
    {
        return DB::table('novels')->insertGetId(['name' => $name, 'status' => 0, 'group_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function chapter(int $novelId, int $n, array $legacy = []): int
    {
        return DB::table('novel_chapters')->insertGetId([
            'novel_id' => $novelId, 'chapter' => $n, 'book' => 0, 'label' => "Chapter {$n}",
            'url' => "https://example.test/{$novelId}/{$n}", 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ] + $legacy);
    }

    private function legacy(int $chapterId): array
    {
        return (array) DB::table('novel_chapters')->where('id', $chapterId)->first(['read_at', 'read_progress', 'read_progress_at']);
    }

    /** @return array<int, array{read_at:?string, progress:?int, progress_at:?string}> chapter id => owner's row */
    private function ownerRows(): array
    {
        return DB::table('chapter_reads')->where('user_id', CurrentReader::ownerId())->orderBy('novel_chapter_id')->get()
            ->mapWithKeys(fn($r) => [(int) $r->novel_chapter_id => [
                'novel_id' => (int) $r->novel_id,
                'read_at' => $r->read_at,
                'progress' => $r->progress === null ? null : (int) $r->progress,
                'progress_at' => $r->progress_at,
            ]])->all();
    }

    public function test_a_fresh_install_has_an_owner(): void
    {
        $owner = DB::table('users')->where('id', (int) DB::table('app_settings')->where('key', 'owner_user_id')->value('value'))->first();

        $this->assertNotNull($owner);
        $this->assertSame('Admin', $owner->name);
        $this->assertSame('admin', $owner->username);
        $this->assertSame('admin', $owner->role);
        $this->assertNull($owner->email);
        $this->assertTrue($this->owner()->isAdmin());
        $this->assertSame((int) $owner->id, app(CurrentReader::class)->id());
    }

    public function test_the_owner_is_the_lowest_existing_user_and_every_user_gets_a_username(): void
    {
        $users = $this->users();
        $users->down();
        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertNull(DB::table('app_settings')->where('key', 'owner_user_id')->value('value'));

        DB::table('users')->delete();
        foreach ([[5, 'Reyhan Thee'], [9, 'Reyhan Thee'], [12, '!!!']] as [$id, $name]) {
            DB::table('users')->insert(['id' => $id, 'name' => $name, 'email' => "u{$id}@example.test", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        }

        $users->up();
        $users->up(); // re-run: no-op
        Cache::forget('settings_all');

        $this->assertSame('5', DB::table('app_settings')->where('key', 'owner_user_id')->value('value'));
        $rows = DB::table('users')->orderBy('id')->get()->keyBy('id');
        $this->assertSame(['reyhan-thee', 'admin'], [$rows[5]->username, $rows[5]->role]);
        $this->assertSame(['reyhan-thee-2', 'reader'], [$rows[9]->username, $rows[9]->role]);
        $this->assertSame(['admin', 'reader'], [$rows[12]->username, $rows[12]->role]);
        $this->assertSame('Reyhan Thee', $rows[5]->name);
        $this->assertSame(3, DB::table('users')->count(), 'no user created when one exists');
        $this->assertSame(5, CurrentReader::ownerId());
    }

    public function test_read_state_positions_and_highlights_move_to_the_owner_and_back(): void
    {
        $owner = CurrentReader::ownerId();
        $copy = $this->copy();
        $ownership = $this->ownership();

        // Back to the pre-migration schema, then lay down legacy state.
        $copy->down();
        $ownership->down();
        $this->assertFalse(Schema::hasColumn('reading_positions', 'user_id'));
        $this->assertTrue(Schema::hasIndex('reading_positions', 'uniq_reading_positions_novel'));

        $n = $this->novel('Legacy');
        $read = $this->chapter($n, 1, ['read_at' => '2026-10-01 10:00:00', 'read_progress' => 100, 'read_progress_at' => '2026-10-01 10:00:00']);
        $progressOnly = $this->chapter($n, 2, ['read_progress' => 40, 'read_progress_at' => '2026-10-02 09:00:00']);
        $stampOnly = $this->chapter($n, 3, ['read_progress_at' => '2026-10-03 08:00:00']);
        $untouched = $this->chapter($n, 4);
        $deleted = $this->chapter($n, 5, ['read_at' => '2026-09-01 07:00:00', 'deleted_at' => now()]);
        $other = $this->novel('Other');
        $otherRead = $this->chapter($other, 1, ['read_at' => '2026-10-05 12:00:00']);

        DB::table('reading_positions')->insert(['novel_id' => $n, 'novel_chapter_id' => $progressOnly, 'progress' => 40, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bookmarks')->insert(['novel_id' => $n, 'novel_chapter_id' => $read, 'excerpt' => 'kept', 'created_at' => now(), 'updated_at' => now()]);

        $ownership->up();
        $copy->up();

        $expected = [
            $read => ['novel_id' => $n, 'read_at' => '2026-10-01 10:00:00', 'progress' => 100, 'progress_at' => '2026-10-01 10:00:00'],
            $progressOnly => ['novel_id' => $n, 'read_at' => null, 'progress' => 40, 'progress_at' => '2026-10-02 09:00:00'],
            $stampOnly => ['novel_id' => $n, 'read_at' => null, 'progress' => null, 'progress_at' => '2026-10-03 08:00:00'],
            $deleted => ['novel_id' => $n, 'read_at' => '2026-09-01 07:00:00', 'progress' => null, 'progress_at' => null],
            $otherRead => ['novel_id' => $other, 'read_at' => '2026-10-05 12:00:00', 'progress' => null, 'progress_at' => null],
        ];
        ksort($expected);
        $this->assertSame($expected, $this->ownerRows());
        $this->assertArrayNotHasKey($untouched, $this->ownerRows());
        $this->assertSame(5, DB::table('chapter_reads')->count(), 'only the owner has rows');

        $this->assertSame([$owner], DB::table('reading_positions')->pluck('user_id')->map(fn($id) => (int) $id)->all());
        $this->assertSame([$owner], DB::table('bookmarks')->pluck('user_id')->map(fn($id) => (int) $id)->all());
        $this->assertTrue(Schema::hasIndex('reading_positions', 'uniq_reading_positions_user_novel'));
        $this->assertFalse(Schema::hasIndex('reading_positions', 'uniq_reading_positions_novel'));
        $this->assertTrue(Schema::hasIndex('bookmarks', 'idx_bookmarks_user_novel'));

        // The legacy columns stay, untouched (rollback safety).
        $this->assertSame('2026-10-01 10:00:00', $this->legacy($read)['read_at']);

        // Re-runnable: nothing duplicated, and state written since is kept.
        DB::table('chapter_reads')->where('user_id', $owner)->where('novel_chapter_id', $progressOnly)
            ->update(['read_at' => '2026-11-01 08:00:00', 'progress' => 100]);
        $ownership->up();
        $copy->up();
        $this->assertSame(5, DB::table('chapter_reads')->count());
        $this->assertSame('2026-11-01 08:00:00', $this->ownerRows()[$progressOnly]['read_at']);

        // Another reader's state, position and highlight.
        $reader = DB::table('users')->insertGetId(['name' => 'Second', 'username' => 'second', 'password' => 'x', 'role' => 'reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('chapter_reads')->insert(['user_id' => $reader, 'novel_id' => $n, 'novel_chapter_id' => $untouched, 'read_at' => '2026-11-02 08:00:00']);
        DB::table('reading_positions')->insert(['user_id' => $reader, 'novel_id' => $n, 'novel_chapter_id' => $untouched, 'progress' => 10, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bookmarks')->insert(['user_id' => $reader, 'novel_id' => $n, 'novel_chapter_id' => $untouched, 'excerpt' => 'theirs', 'created_at' => now(), 'updated_at' => now()]);
        // The owner unread one chapter since the copy (row kept, no date).
        DB::table('chapter_reads')->where('user_id', $owner)->where('novel_chapter_id', $otherRead)->update(['read_at' => null]);

        // down(): the owner's current state back into the legacy columns.
        $copy->down();
        $this->assertSame(['read_at' => '2026-10-01 10:00:00', 'read_progress' => 100, 'read_progress_at' => '2026-10-01 10:00:00'], $this->legacy($read));
        $this->assertSame(['read_at' => '2026-11-01 08:00:00', 'read_progress' => 100, 'read_progress_at' => '2026-10-02 09:00:00'], $this->legacy($progressOnly));
        $this->assertSame(['read_at' => null, 'read_progress' => null, 'read_progress_at' => '2026-10-03 08:00:00'], $this->legacy($stampOnly));
        $this->assertSame('2026-09-01 07:00:00', $this->legacy($deleted)['read_at']);
        $this->assertNull($this->legacy($otherRead)['read_at']);
        $this->assertNull($this->legacy($untouched)['read_at'], "another reader's state is not the owner's");

        // ...and the pre-migration keys: one position per novel, no user_id.
        $ownership->down();
        $this->assertFalse(Schema::hasColumn('reading_positions', 'user_id'));
        $this->assertFalse(Schema::hasColumn('bookmarks', 'user_id'));
        $this->assertTrue(Schema::hasIndex('reading_positions', 'uniq_reading_positions_novel'));
        $this->assertFalse(Schema::hasIndex('reading_positions', 'uniq_reading_positions_user_novel'));
        $this->assertSame(1, DB::table('reading_positions')->count());
        $this->assertSame(['kept'], DB::table('bookmarks')->pluck('excerpt')->all());

        // down() twice is safe too, and up() brings everything back.
        $copy->down();
        $ownership->down();
        $ownership->up();
        $copy->up();
        $this->assertTrue(Schema::hasIndex('reading_positions', 'uniq_reading_positions_user_novel'));
        $this->assertSame('2026-10-01 10:00:00', $this->ownerRows()[$read]['read_at']);
    }

    public function test_the_copy_refuses_to_run_without_an_owner(): void
    {
        DB::table('app_settings')->where('key', 'owner_user_id')->delete();

        $this->expectException(\RuntimeException::class);
        $this->copy()->up();
    }
}
