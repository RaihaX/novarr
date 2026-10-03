<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_10_04_000001_migrate_novelarrow_urls_to_novelping: a prefix REPLACE
 * on every stored source-URL column, idempotent, reversible.
 */
class NovelPingUrlMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_04_000001_migrate_novelarrow_urls_to_novelping.php');
    }

    private function seedRows(): array
    {
        $now = now();
        $groupId = DB::table('groups')->insertGetId(['label' => 'NovelArrow', 'url' => 'https://novelarrow.com', 'created_at' => $now]);
        $otherGroup = DB::table('groups')->insertGetId(['label' => 'NovelFull', 'url' => 'https://novelfull.com', 'created_at' => $now]);

        $novel = DB::table('novels')->insertGetId([
            'name' => 'Shadow Slave', 'group_id' => $groupId,
            'translator_url' => 'https://novelarrow.com/novel/shadow-slave',
            'alternative_url' => 'http://www.novelarrow.com/novel/shadow-slave',
            'created_at' => $now,
        ]);
        $nf = DB::table('novels')->insertGetId([
            'name' => 'Other', 'group_id' => $otherGroup,
            'translator_url' => 'https://novelfull.com/other.html',
            'created_at' => $now,
        ]);

        $chapters = [];
        foreach ([
            'https://novelarrow.com/chapter/shadow-slave/chapter-1-nightmare-begins',
            'https://www.novelarrow.com/chapter/shadow-slave/chapter-2',
            'http://novelarrow.com/chapter/shadow-slave/chapter-3',
            'https://novelarrow.community/chapter/x/1', // lookalike: untouched
            'https://novelfull.com/other/chapter-1.html',
        ] as $i => $url) {
            $chapters[$url] = DB::table('novel_chapters')->insertGetId([
                'novel_id' => $i < 4 ? $novel : $nf, 'label' => "Chapter {$i}", 'url' => $url, 'chapter' => $i + 1,
            ]);
        }

        return compact('groupId', 'otherGroup', 'novel', 'nf', 'chapters');
    }

    public function testUpRewritesEveryUrlColumnAndIsIdempotent(): void
    {
        $ids = $this->seedRows();

        $this->migration()->up();
        $this->assertRewritten($ids);

        // Second run is a no-op.
        $this->migration()->up();
        $this->assertRewritten($ids);
    }

    public function testDownReverses(): void
    {
        $ids = $this->seedRows();
        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame('https://novelarrow.com', DB::table('groups')->where('id', $ids['groupId'])->value('url'));
        $this->assertSame('https://novelarrow.com/novel/shadow-slave', DB::table('novels')->where('id', $ids['novel'])->value('translator_url'));
        $this->assertSame(
            'https://novelarrow.com/chapter/shadow-slave/chapter-1-nightmare-begins',
            DB::table('novel_chapters')->where('id', $ids['chapters']['https://novelarrow.com/chapter/shadow-slave/chapter-1-nightmare-begins'])->value('url')
        );
        $this->assertSame('https://novelfull.com/other.html', DB::table('novels')->where('id', $ids['nf'])->value('translator_url'));
    }

    private function assertRewritten(array $ids): void
    {
        $this->assertSame('https://novelping.com', DB::table('groups')->where('id', $ids['groupId'])->value('url'));
        $this->assertSame('https://novelfull.com', DB::table('groups')->where('id', $ids['otherGroup'])->value('url'));

        $novel = DB::table('novels')->where('id', $ids['novel'])->first();
        $this->assertSame('https://novelping.com/novel/shadow-slave', $novel->translator_url);
        $this->assertSame('https://novelping.com/novel/shadow-slave', $novel->alternative_url);
        $this->assertSame('https://novelfull.com/other.html', DB::table('novels')->where('id', $ids['nf'])->value('translator_url'));

        $expected = [
            'https://novelarrow.com/chapter/shadow-slave/chapter-1-nightmare-begins' => 'https://novelping.com/chapter/shadow-slave/chapter-1-nightmare-begins',
            'https://www.novelarrow.com/chapter/shadow-slave/chapter-2' => 'https://novelping.com/chapter/shadow-slave/chapter-2',
            'http://novelarrow.com/chapter/shadow-slave/chapter-3' => 'https://novelping.com/chapter/shadow-slave/chapter-3',
            'https://novelarrow.community/chapter/x/1' => 'https://novelarrow.community/chapter/x/1',
            'https://novelfull.com/other/chapter-1.html' => 'https://novelfull.com/other/chapter-1.html',
        ];
        foreach ($expected as $old => $new) {
            $this->assertSame($new, DB::table('novel_chapters')->where('id', $ids['chapters'][$old])->value('url'), $old);
        }
    }
}
