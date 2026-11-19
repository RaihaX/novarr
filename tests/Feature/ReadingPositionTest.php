<?php

namespace Tests\Feature;

use App\Novel;
use App\NovelChapter;
use App\ReadingPosition;
use App\Services\ReadingPositions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The series' latest reading position (any device): which writes move it,
 * how the device is identified, and how it is served — the API endpoint and
 * the web readers' `latest_position`.
 */
class ReadingPositionTest extends TestCase
{
    use RefreshDatabase;

    private const MIN = 60_000;
    private const KINDLE = ['X-Novarr-Device' => 'kindle_0001', 'X-Novarr-Device-Name' => 'Kindle'];
    private const PHONE = ['X-Novarr-Device' => 'iphone_0001', 'X-Novarr-Device-Name' => 'iPhone'];

    private Novel $novel;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function novel(string $medium = 'novel'): Novel
    {
        return $this->novel = Novel::create([
            'name' => 'Position Test',
            'status' => 0,
            'group_id' => 1,
            'no_of_chapters' => 3,
            'medium' => $medium,
        ]);
    }

    private function chapter(int $n, bool $downloaded = true, ?string $label = null): NovelChapter
    {
        $chapter = NovelChapter::create([
            'novel_id' => $this->novel->id,
            'chapter' => $n,
            'book' => 0,
            'label' => $label ?? "Chapter {$n}",
            'url' => "https://example.test/novel/chapter-{$n}",
        ]);
        if ($downloaded) {
            $chapter->description = '<p>Once upon a time.</p>';
            $chapter->status = 1;
            $chapter->save();
        }

        return $chapter->fresh();
    }

    private function position(): ?ReadingPosition
    {
        return ReadingPosition::where('novel_id', $this->novel->id)->first();
    }

    private function apiPosition()
    {
        return $this->getJson(route('api.v1.novels.position', $this->novel->id));
    }

    /** The reader state JSON embedded in a chapter page. */
    private function readerState($response): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="readerState">(.*?)</script>#s', $response->getContent(), $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    // -----------------------------------------------------------------
    // Device resolution
    // -----------------------------------------------------------------

    private function resolve(array $server = [], array $input = [], array $cookies = []): array
    {
        $request = Request::create('/x', 'POST', $input, $cookies, [], $server);

        return app(ReadingPositions::class)->device($request);
    }

    public function test_device_header_wins_over_body_and_cookie(): void
    {
        $this->assertSame(['id' => 'header_id1', 'name' => 'Header'], $this->resolve(
            ['HTTP_X_NOVARR_DEVICE' => 'header_id1', 'HTTP_X_NOVARR_DEVICE_NAME' => 'Header'],
            ['device' => 'body_id12', 'device_name' => 'Body'],
            ['novarr_device' => 'cookie_id1', 'novarr_device_name' => 'Cookie'],
        ));
    }

    public function test_device_body_wins_over_cookie_and_cookie_is_the_fallback(): void
    {
        $this->assertSame(['id' => 'body_id12', 'name' => 'Body'], $this->resolve(
            [],
            ['device' => 'body_id12', 'device_name' => 'Body'],
            ['novarr_device' => 'cookie_id1', 'novarr_device_name' => 'Cookie'],
        ));
        $this->assertSame(['id' => 'cookie_id1', 'name' => 'Web · Mac'], $this->resolve(
            [], [], ['novarr_device' => 'cookie_id1', 'novarr_device_name' => 'Web · Mac'],
        ));
    }

    public function test_device_id_is_validated(): void
    {
        foreach (['short', str_repeat('a', 65), 'has space1', 'semi;colon', 'ünïcode123'] as $bad) {
            $this->assertSame(['id' => null, 'name' => null], $this->resolve(
                ['HTTP_X_NOVARR_DEVICE' => $bad, 'HTTP_X_NOVARR_DEVICE_NAME' => 'Named'],
            ), $bad);
        }
        $this->assertSame('A-z_0-9-', $this->resolve(['HTTP_X_NOVARR_DEVICE' => 'A-z_0-9-'])['id']);
        $this->assertSame(str_repeat('b', 64), $this->resolve(['HTTP_X_NOVARR_DEVICE' => str_repeat('b', 64)])['id']);
    }

    public function test_device_name_is_cleaned(): void
    {
        $name = fn($raw) => $this->resolve([], ['device' => 'device_001', 'device_name' => $raw])['name'];

        $this->assertSame('Kindle', $name("  Kin\x00dle\n "));
        $this->assertSame(str_repeat('x', 40), $name(str_repeat('x', 50)));
        $this->assertNull($name("\t\x07 "));
        $this->assertNull($this->resolve([], ['device' => 'device_001'])['name']);
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    public function test_a_progress_write_records_the_position_with_its_device(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->novel();
        $chapter = $this->chapter(1);

        $this->postJson(route('api.v1.chapters.progress', $chapter->id), ['progress' => 40], self::KINDLE)
            ->assertOk()->assertExactJson(['success' => true, 'read' => false]);

        $p = $this->position();
        $this->assertSame($chapter->id, $p->novel_chapter_id);
        $this->assertSame(40, $p->progress);
        $this->assertSame('kindle_0001', $p->device_id);
        $this->assertSame('Kindle', $p->device_name);
        $this->assertSame('2026-11-18 10:00:00', $p->recorded_at->format('Y-m-d H:i:s'));
    }

    public function test_read_only_progress_write_records_the_stored_progress(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);

        // A web beacon: form-encoded, device in the body.
        $this->post(route('chapters.progress', $chapter->id), ['read' => '1', 'device' => 'web_mac_01', 'device_name' => 'Web · Mac'])
            ->assertOk();

        $this->assertSame(0, $this->position()->progress);
        $this->assertSame('web_mac_01', $this->position()->device_id);
        $this->assertSame('Web · Mac', $this->position()->device_name);
    }

    public function test_a_write_without_a_device_succeeds_and_stores_a_null_device(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);

        $this->postJson(route('chapters.progress', $chapter->id), ['progress' => 10], ['X-Novarr-Device' => 'bad'])
            ->assertOk()->assertExactJson(['success' => true, 'read' => false]);

        $this->assertSame(10, (int) $this->progressOf($chapter));
        $this->assertNull($this->position()->device_id);
        $this->assertNull($this->position()->device_name);
    }

    public function test_an_aged_write_does_not_move_a_newer_position(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $this->novel();
        $one = $this->chapter(1);
        $two = $this->chapter(2);

        $this->postJson(route('api.v1.chapters.progress', $two->id), ['progress' => 30], self::PHONE)->assertOk();

        // The Kindle's queued write from an hour ago, on another chapter: the
        // chapter takes it (its own stored time is older), the series does not.
        Carbon::setTestNow('2026-11-18 12:05:00');
        $this->postJson(route('api.v1.chapters.progress', $one->id), ['progress' => 90, 'age' => 60 * self::MIN], self::KINDLE)->assertOk();

        $this->assertSame(90, (int) $this->progressOf($one));
        $this->assertSame($two->id, $this->position()->novel_chapter_id);
        $this->assertSame('iphone_0001', $this->position()->device_id);

        // A live write moves it.
        $this->postJson(route('api.v1.chapters.progress', $one->id), ['progress' => 95], self::KINDLE)->assertOk();
        $this->assertSame($one->id, $this->position()->novel_chapter_id);
        $this->assertSame(95, $this->position()->progress);
    }

    public function test_a_write_rejected_as_stale_for_its_chapter_records_nothing(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $this->novel();
        $chapter = $this->chapter(1);
        $this->postJson(route('api.v1.chapters.progress', $chapter->id), ['progress' => 80], self::PHONE)->assertOk();
        ReadingPosition::query()->delete();

        Carbon::setTestNow('2026-11-18 12:30:00');
        $this->postJson(route('api.v1.chapters.progress', $chapter->id), ['progress' => 20, 'age' => 90 * self::MIN], self::KINDLE)
            ->assertOk()->assertJson(['success' => true]);

        $this->assertNull($this->position());
    }

    public function test_set_read_true_records_100_and_unread_does_not(): void
    {
        $this->novel();
        $one = $this->chapter(1);
        $two = $this->chapter(2);

        $this->postJson(route('api.v1.chapters.read', $one->id), ['read' => true], self::KINDLE)
            ->assertOk()->assertExactJson(['success' => true, 'read' => true]);
        $this->assertSame($one->id, $this->position()->novel_chapter_id);
        $this->assertSame(100, $this->position()->progress);

        $this->postJson(route('api.v1.chapters.read', $two->id), ['read' => false], self::PHONE)->assertOk();
        $this->assertSame($one->id, $this->position()->novel_chapter_id);
        $this->assertSame('kindle_0001', $this->position()->device_id);
    }

    public function test_web_toggle_read_records_only_when_marking_read(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);

        $this->postJson(route('chapters.toggle_read', $chapter->id), [], self::KINDLE)->assertOk()->assertJson(['read' => true]);
        $this->assertSame(100, $this->position()->progress);

        ReadingPosition::query()->delete();
        $this->postJson(route('chapters.toggle_read', $chapter->id), [], self::KINDLE)->assertOk()->assertJson(['read' => false]);
        $this->assertNull($this->position());
    }

    public function test_bulk_read_and_read_through_do_not_record(): void
    {
        $this->novel();
        $one = $this->chapter(1);
        $two = $this->chapter(2);

        $this->postJson(route('chapters.bulk_read'), ['read' => true, 'ids' => [$one->id, $two->id]], self::KINDLE)->assertOk();
        $this->postJson(route('api.v1.chapters.bulk_read'), ['read' => true, 'scope' => 'all', 'novel_id' => $this->novel->id], self::KINDLE)->assertOk();
        $this->postJson(route('chapters.read_through', $two->id), [], self::KINDLE)->assertOk();

        $this->assertNull($this->position());
    }

    public function test_opening_a_chapter_records_with_the_cookie_device_and_prefetch_does_not(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);

        $this->withHeaders(['X-Novarr-Fetch' => 'prefetch'])
            ->withUnencryptedCookie('novarr_device', 'web_mac_01')
            ->get(route('chapters.show', $chapter->id))->assertOk();
        $this->assertNull($this->position());

        // Opened for real, already read and part-way through: still "reading it here".
        $this->markRead($chapter, null, now(), 35);
        $this->flushHeaders();
        $this->withUnencryptedCookie('novarr_device', 'web_mac_01')
            ->withUnencryptedCookie('novarr_device_name', 'Web · Mac')
            ->get(route('chapters.show', $chapter->id))->assertOk();

        $p = $this->position();
        $this->assertSame($chapter->id, $p->novel_chapter_id);
        $this->assertSame(35, $p->progress);
        $this->assertSame('web_mac_01', $p->device_id);
        $this->assertSame('Web · Mac', $p->device_name);
    }

    public function test_opening_a_chapter_not_downloaded_records_nothing(): void
    {
        $this->novel();
        $chapter = $this->chapter(1, downloaded: false);

        $this->get(route('chapters.show', $chapter->id))->assertOk();

        $this->assertNull($this->position());
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    public function test_endpoint_shape(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->novel();
        $this->chapter(1);
        $chapter = $this->chapter(124, label: 'Chapter 124: The Gate');

        $this->apiPosition()->assertOk()->assertExactJson(['position' => null]);

        $this->postJson(route('api.v1.chapters.progress', $chapter->id), ['progress' => 40], self::KINDLE)->assertOk();
        Carbon::setTestNow('2026-11-18 12:00:00');

        $this->apiPosition()->assertOk()->assertExactJson(['position' => [
            'chapter_id' => $chapter->id,
            'chapter_label' => 'Chapter 124: The Gate',
            'chapter_number' => '124',
            'progress' => 40,
            'downloaded' => true,
            'device_id' => 'kindle_0001',
            'device_name' => 'Kindle',
            'at' => '2026-11-18T10:00:00+00:00',
            'age_seconds' => 7200,
        ]]);
    }

    public function test_endpoint_404s_for_an_unknown_or_soft_deleted_novel(): void
    {
        $this->getJson(route('api.v1.novels.position', 999999))->assertNotFound();

        $this->novel();
        $this->novel->delete();
        $this->apiPosition()->assertNotFound();
    }

    public function test_a_soft_deleted_or_blacklisted_chapter_reads_as_null(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);
        $this->postJson(route('api.v1.chapters.progress', $chapter->id), ['progress' => 40], self::KINDLE)->assertOk();

        $chapter->forceFill(['blacklist' => 1])->saveQuietly();
        $this->apiPosition()->assertOk()->assertExactJson(['position' => null]);

        $chapter->forceFill(['blacklist' => 0])->saveQuietly();
        $this->apiPosition()->assertOk()->assertJsonPath('position.chapter_id', $chapter->id);

        $chapter->delete();
        $this->apiPosition()->assertOk()->assertExactJson(['position' => null]);
        $this->assertNotNull($this->position(), 'a soft delete keeps the row');
    }

    public function test_hard_deletes_remove_the_row(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);
        $this->postJson(route('api.v1.chapters.progress', $chapter->id), ['progress' => 40])->assertOk();

        $chapter->forceDelete();
        $this->assertNull($this->position());

        $other = $this->chapter(2);
        $this->postJson(route('api.v1.chapters.progress', $other->id), ['progress' => 40])->assertOk();
        $this->novel->forceDelete();
        $this->assertSame(0, ReadingPosition::count());
    }

    public function test_reader_state_shows_the_position_from_before_this_open(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->novel();
        $one = $this->chapter(1);
        $two = $this->chapter(2);

        $first = $this->readerState($this->get(route('chapters.show', $one->id))->assertOk());
        $this->assertArrayHasKey('latest_position', $first);
        $this->assertNull($first['latest_position']);

        Carbon::setTestNow('2026-11-18 10:30:00');
        $this->postJson(route('api.v1.chapters.progress', $two->id), ['progress' => 60], self::KINDLE)->assertOk();

        Carbon::setTestNow('2026-11-18 11:00:00');
        $state = $this->readerState(
            $this->withUnencryptedCookie('novarr_device', 'web_mac_01')->get(route('chapters.show', $one->id))->assertOk()
        );
        $this->assertSame($two->id, $state['latest_position']['chapter_id']);
        $this->assertSame(60, $state['latest_position']['progress']);
        $this->assertSame('kindle_0001', $state['latest_position']['device_id']);
        $this->assertSame(1800, $state['latest_position']['age_seconds']);
        $this->assertSame(route('chapters.show', $two->id), $state['latest_position']['url']);

        // ...and this open is now the latest.
        $this->assertSame($one->id, $this->position()->novel_chapter_id);
        $this->assertSame('web_mac_01', $this->position()->device_id);
    }

    public function test_manga_pages_json_carries_the_latest_position_but_the_api_payload_does_not(): void
    {
        $this->novel('manga');
        $one = $this->chapter(1);
        $two = $this->chapter(2);
        $this->postJson(route('api.v1.chapters.progress', $two->id), ['progress' => 50], self::PHONE)->assertOk();

        $this->getJson(route('chapters.pages', $one->id))->assertOk()
            ->assertJsonPath('latest_position.chapter_id', $two->id)
            ->assertJsonPath('latest_position.progress', 50)
            ->assertJsonPath('latest_position.url', route('chapters.show', $two->id));

        $this->assertArrayNotHasKey('latest_position', $this->getJson(route('api.v1.chapters.pages', $one->id))->assertOk()->json());
    }

    public function test_record_never_throws(): void
    {
        $this->novel();
        $chapter = $this->chapter(1);
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler = \Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class));
        $handler->shouldReceive('report')->once();

        // A failing insert (not a dropped table: DDL would end the test's
        // transaction on MariaDB).
        ReadingPosition::creating(fn() => throw new \RuntimeException('disk full'));

        app(ReadingPositions::class)->record($chapter, 10, now(), ['id' => null, 'name' => null]);
        $this->addToAssertionCount(1);
    }
}
