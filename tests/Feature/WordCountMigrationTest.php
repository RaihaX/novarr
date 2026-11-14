<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_11_14_000002 must survive a re-run after an interrupted deploy: each
 * change is guarded, so up() and down() are safe to repeat in any state.
 */
class WordCountMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_11_14_000002_add_word_count_and_read_at_index.php');
    }

    public function test_up_and_down_are_idempotent(): void
    {
        $migration = $this->migration();
        $this->assertTrue(Schema::hasColumn('chapter_texts', 'word_count'));
        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_read_at'));

        $migration->up(); // already applied: a no-op, not "Duplicate column"

        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('chapter_texts', 'word_count'));
        $this->assertFalse(Schema::hasIndex('novel_chapters', 'idx_read_at'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('chapter_texts', 'word_count'));
        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_read_at'));
    }

    public function test_a_run_interrupted_after_the_column_add_completes(): void
    {
        $migration = $this->migration();
        $migration->down();
        // The first statement committed, then the run died.
        Schema::table('chapter_texts', fn ($table) => $table->unsignedInteger('word_count')->nullable());

        $migration->up();
        $this->assertTrue(Schema::hasColumn('chapter_texts', 'word_count'));
        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_read_at'));
    }
}
