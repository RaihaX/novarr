<?php

namespace Tests\Feature;

use App\File;
use App\Novel;
use App\NovelChapter;
use App\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The native app's read/sync API (routes/api.php, prefix v1).
 */
class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_KEYS = [
        'id', 'name', 'author', 'cover_url', 'cover_large_url', 'state', 'completed', 'paused', 'origin', 'tags',
        'chapters_total', 'chapters_downloaded', 'chapters_read', 'chapters_new', 'read_percent', 'download_percent',
        'finished', 'last_read_at', 'updated_at', 'resume_chapter_id',
    ];

    private const REF_KEYS = ['id', 'label', 'chapter', 'book'];

    private const LIST_CHAPTER_KEYS = [
        'id', 'chapter', 'book', 'label', 'downloaded', 'read', 'read_progress', 'note', 'state', 'reason',
        'downloaded_at', 'word_count',
    ];

    private const READER_KEYS = [
        'id', 'novel_id', 'novel_name', 'label', 'chapter', 'book', 'downloaded', 'read', 'read_progress',
        'paragraphs', 'html', 'prev', 'next',
    ];

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private function novel(string $name, array $attrs = []): Novel
    {
        $novel = Novel::create(['name' => $name, 'author' => 'Someone', 'status' => 0, 'group_id' => 1, 'no_of_chapters' => 0]);
        if ($attrs) {
            $novel->forceFill($attrs)->save();
        }

        return $novel;
    }

    private function chapter(Novel $novel, float $n, array $attrs = [], ?string $content = null): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $novel->id, 'chapter' => $n, 'book' => 0,
            'label' => "Chapter {$n}", 'url' => "https://example.test/{$novel->id}/c-{$n}",
        ]);
        $chapter->forceFill(array_merge(['status' => 1, 'download_date' => now()->subDay()], $attrs));
        if ($content !== null) {
            $chapter->description = $content;
        }
        $chapter->save();

        return $chapter;
    }

    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    private function summaryOf(array $novels, int $id): array
    {
        foreach ($novels as $n) {
            if ($n['id'] === $id) {
                return $n;
            }
        }
        $this->fail("Novel {$id} not in the list");
    }

    public function test_meta_identifies_the_server(): void
    {
        $json = $this->getJson('/api/v1/meta')->assertOk()->json();

        $this->assertSame(['api_version', 'app', 'server_version'], $this->sorted(array_keys($json)));
        $this->assertSame('novarr', $json['app']);
        $this->assertSame(1, $json['api_version']);
        $this->assertTrue($json['server_version'] === null || is_string($json['server_version']));
    }

    public function test_novels_lists_the_whole_library_with_the_summary_shape(): void
    {
        $tag = Tag::create(['name' => 'Xianxia']);
        $read = $this->novel('Being Read', ['origin' => 'translated', 'origin_language' => 'zh', 'no_of_chapters' => 4]);
        $read->tags()->attach($tag->id);
        $read->file()->save(new File(['file_name' => 'c.jpg', 'file_path' => 'public/covers/c.jpg']));
        $one = $this->chapter($read, 1, ['read_at' => now()->subHour()->startOfSecond(), 'read_progress' => 100]);
        $two = $this->chapter($read, 2);
        $this->chapter($read, 3, ['status' => 0]);

        $empty = $this->novel('Nothing Downloaded', ['status' => 1]);
        $this->chapter($empty, 1, ['status' => 0, 'download_date' => null]);

        $fresh = $this->novel('Alpha Unread', ['paused_at' => now()]);
        $first = $this->chapter($fresh, 1);
        $this->chapter($fresh, 2);

        $novels = $this->getJson('/api/v1/novels')->assertOk()->json('novels');

        // Last read first, then name.
        $this->assertSame(['Being Read', 'Alpha Unread', 'Nothing Downloaded'], array_column($novels, 'name'));
        foreach ($novels as $n) {
            $this->assertSame($this->sorted(self::SUMMARY_KEYS), $this->sorted(array_keys($n)));
        }

        $r = $this->summaryOf($novels, $read->id);
        $this->assertSame([['id' => $tag->id, 'name' => 'Xianxia']], $r['tags']);
        $this->assertSame('Translated · ZH', $r['origin']);
        $this->assertSame('active', $r['state']);
        $this->assertFalse($r['completed']);
        $this->assertFalse($r['paused']);
        $this->assertSame(4, $r['chapters_total']);
        $this->assertSame(2, $r['chapters_downloaded']);
        $this->assertSame(1, $r['chapters_read']);
        $this->assertSame(25, $r['read_percent']);
        $this->assertSame(50, $r['download_percent']);
        $this->assertFalse($r['finished']);
        $this->assertSame($two->id, $r['resume_chapter_id']);
        $this->assertSame($one->read_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'), $r['last_read_at']);
        $this->assertMatchesRegularExpression(self::TIMESTAMP, $r['updated_at']);
        // Absolute, on the request's origin.
        $this->assertSame('http://localhost/storage/covers/c.jpg', $r['cover_url']);
        $this->assertSame('http://localhost/storage/covers/c.jpg', $r['cover_large_url']);

        $e = $this->summaryOf($novels, $empty->id);
        $this->assertNull($e['cover_url']);
        $this->assertNull($e['cover_large_url']);
        $this->assertNull($e['resume_chapter_id']);
        $this->assertNull($e['updated_at']);
        $this->assertNull($e['last_read_at']);
        $this->assertNull($e['origin']);
        $this->assertSame([], $e['tags']);
        $this->assertSame('completed', $e['state']);
        $this->assertTrue($e['completed']);
        $this->assertSame(0, $e['chapters_downloaded']);

        $f = $this->summaryOf($novels, $fresh->id);
        $this->assertSame('paused', $f['state']);
        $this->assertTrue($f['paused']);
        $this->assertNull($f['last_read_at']);
        $this->assertSame(0, $f['chapters_read']);
        $this->assertSame($first->id, $f['resume_chapter_id']);
    }

    public function test_resume_chapter_follows_the_most_recently_read_chapter_in_reading_order(): void
    {
        // Read 1-2 long ago, then jumped to 5 most recently: continue at the
        // next downloaded unread chapter after 5, skipping a queued 6 and a
        // blacklisted 7.
        $jump = $this->novel('Jumped Ahead');
        $this->chapter($jump, 1, ['read_at' => now()->subDays(3)]);
        $this->chapter($jump, 2, ['read_at' => now()->subDays(3)]);
        $this->chapter($jump, 3);
        $this->chapter($jump, 5, ['read_at' => now()->subHour()]);
        $this->chapter($jump, 6, ['status' => 0]);
        $this->chapter($jump, 7, ['blacklist' => 1]);
        $eight = $this->chapter($jump, 8);

        // Nothing read: the first downloaded chapter in reading order (book
        // before number).
        $books = $this->novel('Books');
        $this->chapter($books, 1, ['book' => 2]);
        $bookOne = $this->chapter($books, 9, ['book' => 1]);

        // Caught up: the last downloaded chapter.
        $done = $this->novel('Caught Up');
        $this->chapter($done, 1, ['read_at' => now()->subHours(2)]);
        $last = $this->chapter($done, 2, ['read_at' => now()->subHours(2)]);
        $this->chapter($done, 3, ['status' => 0]);

        $novels = $this->getJson('/api/v1/novels')->assertOk()->json('novels');

        $this->assertSame($eight->id, $this->summaryOf($novels, $jump->id)['resume_chapter_id']);
        $this->assertSame($bookOne->id, $this->summaryOf($novels, $books->id)['resume_chapter_id']);
        $this->assertSame($last->id, $this->summaryOf($novels, $done->id)['resume_chapter_id']);
    }

    public function test_resume_reopens_a_chapter_abandoned_part_way_like_the_web_continue_list(): void
    {
        $novel = $this->novel('Half Read');
        $this->chapter($novel, 1, ['read_at' => now()->subDays(2), 'read_progress' => 100]);
        $half = $this->chapter($novel, 2, ['read_at' => now()->subHour(), 'read_progress' => 40]);
        $this->chapter($novel, 3);

        $novels = $this->getJson('/api/v1/novels')->assertOk()->json('novels');
        $this->assertSame($half->id, $this->summaryOf($novels, $novel->id)['resume_chapter_id']);

        $continue = $this->getJson('/api/v1/home')->assertOk()->json('continue');
        $this->assertSame($half->id, $continue[0]['chapter']['id']);
        $this->assertSame($half->id, $continue[0]['novel']['resume_chapter_id']);

        // Finishing it moves on to the next unread chapter.
        $this->postJson("/api/v1/chapters/{$half->id}/progress", ['progress' => 100, 'read' => true])->assertOk();
        \Illuminate\Support\Facades\Cache::flush();
        $novels = $this->getJson('/api/v1/novels')->assertOk()->json('novels');
        $this->assertNotSame($half->id, $this->summaryOf($novels, $novel->id)['resume_chapter_id']);
    }

    public function test_novels_runs_a_fixed_number_of_queries_and_never_loads_descriptions(): void
    {
        $count = function (int $novels): array {
            Novel::query()->forceDelete();
            for ($i = 0; $i < $novels; $i++) {
                $novel = $this->novel("Novel {$i}", ['description' => 'A long synopsis']);
                $novel->tags()->attach(Tag::firstOrCreate(['name' => "Tag {$i}"])->id);
                $novel->file()->save(new File(['file_name' => 'c.jpg', 'file_path' => "public/c{$i}.jpg"]));
                $this->chapter($novel, 1, ['read_at' => now()->subMinutes($i + 1)]);
                $this->chapter($novel, 2);
                $this->chapter($novel, 3, ['status' => 0]);
            }
            Cache::flush();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/novels')->assertOk()->assertJsonCount($novels, 'novels');
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return array_map(fn($q) => str_replace('`', '"', $q['query']), $log);
        };

        $small = $count(2);
        $large = $count(7);

        $this->assertCount(count($small), $large);
        foreach ($large as $sql) {
            $this->assertStringNotContainsString('description', $sql);
            $this->assertStringNotContainsString('select * from "novels"', $sql);
        }

        // Warm caches: only the three statements that load the novels.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/novels')->assertOk();
        $this->assertCount(3, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_novels_and_chapter_lists_answer_conditional_gets_with_304(): void
    {
        $novel = $this->novel('Etag Novel');
        $this->chapter($novel, 1);

        foreach (['/api/v1/novels', "/api/v1/novels/{$novel->id}/chapters"] as $uri) {
            $first = $this->getJson($uri)->assertOk();
            $etag = $first->headers->get('ETag');
            $this->assertNotEmpty($etag, $uri);

            $this->getJson($uri, ['If-None-Match' => $etag])->assertStatus(304);
        }
    }

    public function test_home_returns_continue_new_chapters_and_stats(): void
    {
        $reading = $this->novel('Reading Now');
        $this->chapter($reading, 1, ['read_at' => now()->subHour()]);
        $next = $this->chapter($reading, 2);
        $this->chapter($reading, 3, ['status' => 0]);

        $fresh = $this->novel('Not Started');
        $this->chapter($fresh, 0, ['kind' => NovelChapter::KIND_NOTE]);
        $start = $this->chapter($fresh, 1);

        $json = $this->getJson('/api/v1/home')->assertOk()->json();

        $this->assertSame(['continue', 'new_chapters', 'stats'], $this->sorted(array_keys($json)));

        $this->assertCount(1, $json['continue']);
        $item = $json['continue'][0];
        $this->assertSame(['chapter', 'novel'], $this->sorted(array_keys($item)));
        $this->assertSame($this->sorted(self::SUMMARY_KEYS), $this->sorted(array_keys($item['novel'])));
        $this->assertSame($reading->id, $item['novel']['id']);
        $this->assertSame(['id' => $next->id, 'label' => 'Chapter 2', 'chapter' => 2, 'book' => 0], $item['chapter']);

        // Reading first, then not started — as on the web Home.
        $this->assertSame([$reading->id, $fresh->id], array_map(fn($g) => $g['novel']['id'], $json['new_chapters']));
        foreach ($json['new_chapters'] as $g) {
            $this->assertSame(['count', 'first_chapter', 'novel'], $this->sorted(array_keys($g)));
            $this->assertSame($this->sorted(self::REF_KEYS), $this->sorted(array_keys($g['first_chapter'])));
        }
        $this->assertSame(2, $json['new_chapters'][0]['count']);
        $this->assertSame($next->id, $json['new_chapters'][0]['first_chapter']['id']);
        // Start skips the chapter 0 note.
        $this->assertSame($start->id, $json['new_chapters'][1]['first_chapter']['id']);

        $this->assertSame(['novels' => 2, 'chapters_downloaded' => 4, 'chapters_queued' => 1], $json['stats']);
    }

    public function test_home_is_empty_on_an_empty_library(): void
    {
        $this->getJson('/api/v1/home')->assertOk()->assertExactJson([
            'continue' => [],
            'new_chapters' => [],
            'stats' => ['novels' => 0, 'chapters_downloaded' => 0, 'chapters_queued' => 0],
        ]);
    }

    public function test_novel_detail_adds_synopsis_source_and_parked_chapters(): void
    {
        $novel = $this->novel('Detail Novel', [
            'description' => '<h3>Detail Novel</h3><p>First line of the synopsis &amp; more.</p><p>Second<br>part here.</p>',
            'translator_url' => 'https://www.novelping.com/detail-novel',
            'source_fail_streak' => 1,
            'source_recheck_at' => now()->addHours(6),
        ]);
        $this->chapter($novel, 1);
        foreach ([2, 3] as $n) {
            $this->chapter($novel, $n, [
                'status' => 0, 'last_failure_reason' => 'not_found', 'next_attempt_at' => now()->addHours(6),
            ]);
        }

        $json = $this->getJson("/api/v1/novels/{$novel->id}")->assertOk()->json('novel');

        $this->assertSame(
            $this->sorted(array_merge(self::SUMMARY_KEYS, ['description', 'source_url', 'source_unavailable'])),
            $this->sorted(array_keys($json))
        );
        $this->assertSame("First line of the synopsis & more.\n\nSecond\npart here.", $json['description']);
        $this->assertSame('https://www.novelping.com/detail-novel', $json['source_url']);
        $this->assertSame(2, $json['source_unavailable']['count']);
        $this->assertStringStartsWith('2 chapters are listed on novelping.com but not available there', $json['source_unavailable']['message']);

        $plain = $this->novel('Plain Novel');
        $json = $this->getJson("/api/v1/novels/{$plain->id}")->assertOk()->json('novel');
        $this->assertNull($json['description']);
        $this->assertNull($json['source_url']);
        $this->assertNull($json['source_unavailable']);
        $this->assertNull($json['resume_chapter_id']);
    }

    public function test_unknown_ids_answer_404_json(): void
    {
        $missing = (int) Novel::withTrashed()->max('id') + 1000;
        $missingChapter = (int) NovelChapter::withTrashed()->max('id') + 1000;

        foreach (["/api/v1/novels/{$missing}", "/api/v1/novels/{$missing}/chapters", "/api/v1/chapters/{$missingChapter}"] as $uri) {
            // No Accept header: the API still answers JSON.
            $this->get($uri)->assertNotFound()->assertJsonStructure(['message']);
        }
        $this->post("/api/v1/chapters/{$missingChapter}/progress", ['progress' => 10])->assertNotFound()->assertJsonStructure(['message']);
        $this->post("/api/v1/chapters/{$missingChapter}/read", ['read' => true])->assertNotFound();
    }

    public function test_chapter_list_is_in_reading_order_without_blacklisted_rows(): void
    {
        $novel = $this->novel('List Novel');
        $late = $this->chapter($novel, 1, ['book' => 1]);
        $read = $this->chapter($novel, 2, ['read_at' => now(), 'read_progress' => 40], '<p>one two three</p>');
        $read->text()->update(['word_count' => 3]);
        $failed = $this->chapter($novel, 3, ['status' => 0, 'download_date' => null, 'last_failure_reason' => 'cloudflare']);
        $note = $this->chapter($novel, 4, ['kind' => NovelChapter::KIND_NOTE, 'label' => '']);
        $this->chapter($novel, 5, ['blacklist' => 1]);
        $half = $this->chapter($novel, 6.5, ['status' => 0, 'download_date' => null]);

        $chapters = $this->getJson("/api/v1/novels/{$novel->id}/chapters")->assertOk()->json('chapters');

        $this->assertSame([$read->id, $failed->id, $note->id, $half->id, $late->id], array_column($chapters, 'id'));
        foreach ($chapters as $c) {
            $this->assertSame($this->sorted(self::LIST_CHAPTER_KEYS), $this->sorted(array_keys($c)));
        }

        [$r, $f, $n, $h] = $chapters;
        $this->assertTrue($r['read']);
        $this->assertSame(40, $r['read_progress']);
        $this->assertSame(3, $r['word_count']);
        $this->assertSame('downloaded', $r['state']);
        $this->assertNull($r['reason']);
        $this->assertMatchesRegularExpression(self::TIMESTAMP, $r['downloaded_at']);

        $this->assertFalse($f['downloaded']);
        $this->assertSame('failed', $f['state']);
        $this->assertSame('The source answered with a Cloudflare check instead of the page.', $f['reason']);
        $this->assertNull($f['downloaded_at']);
        $this->assertNull($f['word_count']);
        $this->assertNull($f['read_progress']);

        $this->assertTrue($n['note']);
        $this->assertSame('Chapter 4', $n['label']);

        $this->assertSame('queued', $h['state']);
        $this->assertSame(6.5, $h['chapter']);
        $this->assertSame(1, $chapters[4]['book']);
    }

    public function test_reading_a_chapter_returns_text_and_neighbours_without_marking_it_read(): void
    {
        $novel = $this->novel('Reader Novel');
        $prev = $this->chapter($novel, 1);
        $chapter = $this->chapter($novel, 2, [], '<p class="x" onclick="evil()">First &amp; <em>best</em>.</p><p>&nbsp;</p><p>Second<br>line</p><script>bad()</script>');
        $next = $this->chapter($novel, 3, ['status' => 0]);

        $json = $this->getJson("/api/v1/chapters/{$chapter->id}")->assertOk()->json('chapter');

        $this->assertSame($this->sorted(self::READER_KEYS), $this->sorted(array_keys($json)));
        $this->assertSame($novel->id, $json['novel_id']);
        $this->assertSame('Reader Novel', $json['novel_name']);
        $this->assertFalse($json['read']);
        $this->assertNull($json['read_progress']);
        $this->assertSame($chapter->fresh()->description, $json['html']);
        $this->assertStringNotContainsString('onclick', $json['html']);
        $this->assertSame(['First & best.', 'Second', 'line', 'bad()'], $json['paragraphs']);

        $this->assertSame(['id' => $prev->id, 'label' => 'Chapter 1', 'chapter' => 1, 'book' => 0, 'downloaded' => true], $json['prev']);
        $this->assertSame(['id' => $next->id, 'label' => 'Chapter 3', 'chapter' => 3, 'book' => 0, 'downloaded' => false], $json['next']);

        $this->assertNull($chapter->fresh()->read_at);

        $edge = $this->getJson("/api/v1/chapters/{$prev->id}")->assertOk()->json('chapter');
        $this->assertNull($edge['prev']);
        $this->assertSame([], $edge['paragraphs']);
        $this->assertSame('', $edge['html']);
        $this->assertNull($prev->fresh()->read_at);
    }

    public function test_progress_records_position_and_marks_read_without_a_csrf_token(): void
    {
        $novel = $this->novel('Progress Novel');
        $chapter = $this->chapter($novel, 1);

        $this->post("/api/v1/chapters/{$chapter->id}/progress", ['progress' => 40])
            ->assertOk()->assertExactJson(['success' => true, 'read' => false]);
        $this->assertSame(40, (int) $chapter->fresh()->read_progress);

        $this->postJson("/api/v1/chapters/{$chapter->id}/progress", ['read' => true])
            ->assertOk()->assertExactJson(['success' => true, 'read' => true]);
        $this->assertNotNull($chapter->fresh()->read_at);

        // The Library summary sees the read straight away.
        $summary = $this->getJson('/api/v1/novels')->json('novels.0');
        $this->assertSame(1, $summary['chapters_read']);
        $this->assertTrue($summary['finished']);

        // No Accept header: validation errors still come back as JSON 422.
        $this->post("/api/v1/chapters/{$chapter->id}/progress", ['progress' => 150])
            ->assertStatus(422)->assertJsonValidationErrors('progress');
        $this->postJson("/api/v1/chapters/{$chapter->id}/progress", [])->assertStatus(422);
        $this->postJson("/api/v1/chapters/{$chapter->id}/progress", ['progress' => 10, 'age' => -1])->assertStatus(422);
    }

    public function test_read_sets_rather_than_toggles(): void
    {
        $novel = $this->novel('Set Read Novel');
        $chapter = $this->chapter($novel, 1);

        foreach ([true, true] as $read) {
            $this->postJson("/api/v1/chapters/{$chapter->id}/read", ['read' => $read])
                ->assertOk()->assertExactJson(['success' => true, 'read' => true]);
        }
        $fresh = $chapter->fresh();
        $this->assertNotNull($fresh->read_at);
        $this->assertSame(100, (int) $fresh->read_progress);

        $this->postJson("/api/v1/chapters/{$chapter->id}/read", ['read' => false])
            ->assertOk()->assertExactJson(['success' => true, 'read' => false]);
        $this->assertNull($chapter->fresh()->read_at);
        $this->assertNull($chapter->fresh()->read_progress);

        $this->postJson("/api/v1/chapters/{$chapter->id}/read", [])->assertStatus(422)->assertJsonValidationErrors('read');
        $this->postJson("/api/v1/chapters/{$chapter->id}/read", ['read' => 'maybe'])->assertStatus(422);
    }

    public function test_bulk_read_scope_all_marks_the_whole_novel(): void
    {
        $novel = $this->novel('Bulk Novel');
        $other = $this->novel('Other Novel');
        $this->chapter($novel, 1);
        $this->chapter($novel, 2);
        $this->chapter($novel, 3, ['status' => 0]);
        $skipped = $this->chapter($novel, 4, ['blacklist' => 1]);
        $untouched = $this->chapter($other, 1);

        $this->post('/api/v1/chapters/bulk-read', ['read' => true, 'scope' => 'all', 'novel_id' => $novel->id])
            ->assertOk()->assertExactJson(['success' => true, 'count' => 3]);

        $this->assertSame(0, NovelChapter::where('novel_id', $novel->id)->where('blacklist', 0)->whereNull('read_at')->count());
        $this->assertNull($skipped->fresh()->read_at);
        $this->assertNull($untouched->fresh()->read_at);

        $this->postJson('/api/v1/chapters/bulk-read', ['read' => true, 'scope' => 'all'])->assertStatus(422)->assertJsonValidationErrors('novel_id');
        $this->postJson('/api/v1/chapters/bulk-read', ['scope' => 'nope', 'read' => true])->assertStatus(422);
    }

    public function test_v1_routes_are_not_rate_limited(): void
    {
        $novel = $this->novel('Prefetch Novel');
        $chapter = $this->chapter($novel, 1, [], '<p>x</p>');

        for ($i = 0; $i < 70; $i++) {
            $this->getJson("/api/v1/chapters/{$chapter->id}")->assertOk();
        }

        // The rest of the api group keeps its 60/min limit.
        $statuses = [];
        for ($i = 0; $i < 61; $i++) {
            $statuses[] = $this->getJson('/api/ping')->getStatusCode();
        }
        $this->assertSame(429, end($statuses));
    }
}
