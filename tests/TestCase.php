<?php

namespace Tests;

use App\ChapterRead;
use App\NovelChapter;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The array cache outlives RefreshDatabase: without this, one test's
        // cached aggregates (library counts, dashboard panels) leak into the next.
        \Illuminate\Support\Facades\Cache::flush();
        // No test reaches the network through the HTTP client (the breached-
        // password check, the human check): a test fakes what it needs.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        // The chapter dispatcher starts lanes as detached processes; tests
        // run them in-process (tests that check the dispatch bind their own).
        $this->app->instance(\App\Services\LaneProcessStarter::class, new \Tests\Support\InlineLaneStarter());
        // Request searches ask their sources in parallel processes, which
        // cannot see this process's fake sources: tests ask them in-process
        // (tests of the process runner bind their own).
        $this->app->instance(\App\Services\Requests\SearchRunner::class, new \App\Services\Requests\InlineSearchRunner());
        // Audiobooks are probed by a fake (no ffprobe in tests), and their
        // inbox and library are this process's own temporary folders (a
        // queued "Scan now" runs inline under the sync queue).
        $this->app->instance(\App\Audiobooks\AudioProbe::class, new \Tests\Support\FakeAudioProbe());
        $audiobooks = sys_get_temp_dir() . '/novarr-test-audiobooks-' . getmypid();
        config([
            'novarr.audiobooks.inbox' => $audiobooks . '/inbox',
            'novarr.audiobooks.path' => $audiobooks . '/library',
            // The comics inbox too (App\Comics\ComicInbox); Comic Vine is off
            // unless a test turns it on (with Http::fake).
            'novarr.comics.inbox' => sys_get_temp_dir() . '/novarr-test-comics-' . getmypid() . '/inbox',
            'novarr.comics.comicvine.api_key' => '',
        ]);
    }

    use CreatesApplication;

    /** The library's owner (created by the users migration): the reader every request uses in stage 1. */
    protected function owner(): User
    {
        return User::findOrFail(CurrentReader::ownerId());
    }

    /** Make every request / call from here on read as $user (there is no sign-in yet). */
    protected function actAsReader(User $user): void
    {
        // Rebound as scoped, so it survives a forgetScopedInstances() too.
        $this->app->forgetInstance(CurrentReader::class);
        $this->app->scoped(CurrentReader::class, fn() => CurrentReader::for($user));
    }

    /**
     * Fixture: $chapter read by $user (default: the owner) at $at (default:
     * now), optionally with a position. Written straight into chapter_reads.
     */
    protected function markRead(NovelChapter $chapter, ?User $user = null, $at = null, ?int $progress = null, $progressAt = null): void
    {
        $this->setReadState($chapter, [
            'read_at' => $at ?? now(),
            'progress' => $progress,
            'progress_at' => $progressAt,
        ], $user);
    }

    /**
     * Fixture: set $user's (default: the owner's) chapter_reads row for
     * $chapter to exactly $values (read_at / progress / progress_at; missing
     * keys are null).
     */
    protected function setReadState(NovelChapter $chapter, array $values, ?User $user = null): void
    {
        $row = [
            'user_id' => $user?->id ?? CurrentReader::ownerId(),
            'novel_id' => (int) $chapter->novel_id,
            'novel_chapter_id' => (int) $chapter->id,
            'read_at' => $values['read_at'] ?? null,
            'progress' => $values['progress'] ?? null,
            'progress_at' => $values['progress_at'] ?? null,
        ];
        DB::table('chapter_reads')->upsert([$row], ['user_id', 'novel_chapter_id'], ['read_at', 'progress', 'progress_at']);
    }

    /**
     * For fixture helpers that take a chapter's attributes: moves the read
     * keys (read_at / progress / progress_at) out of $attrs into the owner's
     * chapter_reads row for $chapter and returns the rest.
     */
    protected function takeReadState(NovelChapter $chapter, array $attrs): array
    {
        $values = array_intersect_key($attrs, array_flip(['read_at', 'progress', 'progress_at']));
        if ($values !== []) {
            $this->setReadState($chapter, $values);
        }

        return array_diff_key($attrs, $values);
    }

    /** $user's (default: the owner's) stored state of $chapter, or null when untouched. */
    protected function readStateOf(NovelChapter $chapter, ?User $user = null): ?ChapterRead
    {
        return ChapterRead::where('user_id', $user?->id ?? CurrentReader::ownerId())
            ->where('novel_chapter_id', $chapter->id)
            ->first();
    }

    /** When $user (default: the owner) read $chapter, or null. */
    protected function readAtOf(NovelChapter $chapter, ?User $user = null): ?\Illuminate\Support\Carbon
    {
        return $this->readStateOf($chapter, $user)?->read_at;
    }

    /** $user's (default: the owner's) position in $chapter, or null. */
    protected function progressOf(NovelChapter $chapter, ?User $user = null): ?int
    {
        return $this->readStateOf($chapter, $user)?->progress;
    }

    /** How many chapters of $novelId $user (default: the owner) has read. */
    protected function readCount(int $novelId, ?User $user = null): int
    {
        return ChapterRead::where('user_id', $user?->id ?? CurrentReader::ownerId())
            ->where('novel_id', $novelId)
            ->whereNotNull('read_at')
            ->count();
    }
}
