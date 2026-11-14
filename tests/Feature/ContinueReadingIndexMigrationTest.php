<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_11_15_000001 replaces three novel_chapters indexes with
 * idx_continue_ordered. Every step is guarded, so up() and down() are safe to
 * repeat after an interrupted deploy.
 */
class ContinueReadingIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = ['idx_continue_next', 'idx_continue_next_sort', 'idx_novel_counts_cover'];

    private function migration()
    {
        return require database_path('migrations/2026_11_15_000001_rework_continue_reading_indexes.php');
    }

    private function assertMigratedState(): void
    {
        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_continue_ordered'));
        $this->assertTrue(Schema::hasIndex('novel_chapters',
            ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'sort_key', 'chapter']));
        foreach (self::OLD as $name) {
            $this->assertFalse(Schema::hasIndex('novel_chapters', $name), "$name should be dropped");
        }
    }

    private function assertRolledBackState(): void
    {
        $this->assertFalse(Schema::hasIndex('novel_chapters', 'idx_continue_ordered'));
        foreach (self::OLD as $name) {
            $this->assertTrue(Schema::hasIndex('novel_chapters', $name), "$name should be restored");
        }
        $this->assertTrue(Schema::hasIndex('novel_chapters',
            ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'chapter']));
        $this->assertTrue(Schema::hasIndex('novel_chapters',
            ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'sort_key']));
        $this->assertTrue(Schema::hasIndex('novel_chapters',
            ['novel_id', 'status', 'blacklist', 'deleted_at']));
    }

    public function test_up_down_up_and_reruns_are_safe(): void
    {
        $migration = $this->migration();
        $this->assertMigratedState(); // applied by RefreshDatabase

        $migration->up(); // re-run: no-op
        $this->assertMigratedState();

        $migration->down();
        $this->assertRolledBackState();
        $migration->down(); // re-run: no-op
        $this->assertRolledBackState();

        $migration->up();
        $this->assertMigratedState();
    }

    public function test_a_run_interrupted_after_the_new_index_was_added_completes(): void
    {
        $migration = $this->migration();
        $migration->down();
        // The first statement committed, then the run died.
        Schema::table('novel_chapters', fn ($table) => $table->index(
            ['novel_id', 'status', 'blacklist', 'deleted_at', 'read_at', 'book', 'sort_key', 'chapter'],
            'idx_continue_ordered'
        ));
        Schema::table('novel_chapters', fn ($table) => $table->dropIndex('idx_continue_next'));

        $migration->up();
        $this->assertMigratedState();
    }
}
