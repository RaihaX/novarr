<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_11_15_000002 adds the covering index for the Library's aggregate
 * queries and drops the index it supersedes; both directions are repeatable.
 */
class LibraryAggregatesIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_11_15_000002_add_library_aggregates_index.php');
    }

    public function test_up_and_down_are_repeatable(): void
    {
        $columns = ['novel_id', 'blacklist', 'deleted_at', 'status', 'read_at', 'download_date'];

        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_library_aggregates'));
        $this->assertTrue(Schema::hasIndex('novel_chapters', $columns));
        $this->assertFalse(Schema::hasIndex('novel_chapters', 'idx_novel_blacklist'));

        $this->migration()->up(); // a re-run changes nothing
        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_library_aggregates'));

        $this->migration()->down();
        $this->migration()->down();
        $this->assertFalse(Schema::hasIndex('novel_chapters', 'idx_library_aggregates'));
        $this->assertTrue(Schema::hasIndex('novel_chapters', ['novel_id', 'blacklist']));

        $this->migration()->up();
        $this->assertTrue(Schema::hasIndex('novel_chapters', 'idx_library_aggregates'));
        $this->assertFalse(Schema::hasIndex('novel_chapters', 'idx_novel_blacklist'));
    }
}
