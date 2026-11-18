<?php

namespace App\Services;

use App\NovelChapter;
use App\ReadingPosition;
use App\Support\CurrentReader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The "latest position" of each series for each reader: which chapter was
 * read last, how far in, on which device and when — one reading_positions
 * row per (reader, novel). The readers use it to offer "read on Kindle 2
 * hours ago — go there?".
 *
 * Written by the read-state endpoints that mean "I am reading this here"
 * (progress, an explicit mark-read, opening a chapter in the web reader),
 * never by bulk marking. A device is whatever id the client generated for
 * itself. The reader is the current one (App\Support\CurrentReader) unless a
 * user id is passed, resolved per call: this service is held by controllers
 * across requests under Octane.
 */
class ReadingPositions
{
    public const HEADER_ID = 'X-Novarr-Device';
    public const HEADER_NAME = 'X-Novarr-Device-Name';
    public const COOKIE_ID = 'novarr_device';
    public const COOKIE_NAME = 'novarr_device_name';

    /** Set by the browser's JS, so never encrypted (AppServiceProvider). */
    public const COOKIES = [self::COOKIE_ID, self::COOKIE_NAME];

    public const MAX_NAME_LENGTH = 40;

    /**
     * The device a request comes from. Header first; body/query fields for
     * sendBeacon and plain links; cookies for page navigations. The first
     * source that carries a value wins. PHP has already URL-decoded cookie
     * values, so they are not decoded again.
     *
     * @return array{id: ?string, name: ?string}
     */
    public function device(Request $request): array
    {
        $id = $this->firstValue($request, self::HEADER_ID, 'device', self::COOKIE_ID);
        $id = is_string($id) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) ? $id : null;
        if ($id === null) {
            // A name without an id can't be told apart from this device's own.
            return ['id' => null, 'name' => null];
        }

        return ['id' => $id, 'name' => $this->cleanName($this->firstValue($request, self::HEADER_NAME, 'device_name', self::COOKIE_NAME))];
    }

    private function firstValue(Request $request, string $header, string $field, string $cookie): mixed
    {
        foreach ([$request->header($header), $request->input($field), $request->cookie($cookie)] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function cleanName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        // Invalid UTF-8 makes preg_replace return null: no name.
        $name = preg_replace('/\p{Cc}+/u', '', $name);
        if ($name === null) {
            return null;
        }
        $name = trim(mb_substr(trim($name), 0, self::MAX_NAME_LENGTH));

        return $name === '' ? null : $name;
    }

    /**
     * Record that $chapter was being read at $at. Applies only when $at is
     * not older than the stored position, so a write replayed from an
     * offline queue never moves the series back. Never throws: losing a
     * position must not fail the progress write that triggered it.
     *
     * @param array{id: ?string, name: ?string} $device
     */
    public function record(NovelChapter $chapter, ?int $progress, Carbon $at, array $device, ?int $userId = null): void
    {
        try {
            $userId = $userId ?? app(CurrentReader::class)->id();
            $novelId = (int) $chapter->novel_id;
            $values = [
                'novel_chapter_id' => (int) $chapter->id,
                'progress' => $progress === null ? null : max(0, min(100, $progress)),
                'device_id' => $device['id'] ?? null,
                'device_name' => ($device['id'] ?? null) === null ? null : ($device['name'] ?? null),
                // Stored to the second, like chapter_reads.progress_at.
                'recorded_at' => $at->copy()->startOfSecond(),
            ];

            // Conditional update first: the age check and the write are one
            // statement, so two racing writes cannot both win.
            if ($this->updateIfNotNewer($userId, $novelId, $values) > 0) {
                return;
            }
            if (ReadingPosition::where('user_id', $userId)->where('novel_id', $novelId)->exists()) {
                return; // A newer position is stored.
            }

            try {
                ReadingPosition::create(['user_id' => $userId, 'novel_id' => $novelId] + $values);
            } catch (UniqueConstraintViolationException) {
                // Another write created the row first: apply ours if it's newer.
                $this->updateIfNotNewer($userId, $novelId, $values);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function updateIfNotNewer(int $userId, int $novelId, array $values): int
    {
        return ReadingPosition::where('user_id', $userId)
            ->where('novel_id', $novelId)
            ->where(fn($q) => $q->whereNull('recorded_at')->orWhere('recorded_at', '<=', $values['recorded_at']))
            ->update($values + ['updated_at' => now()]);
    }

    /**
     * The series' latest position, as served by GET /api/v1/novels/{id}/position
     * (without `url`), or null when there is none or its chapter is gone,
     * soft-deleted or blacklisted. Two primary-key/unique lookups.
     */
    public function latest(int $novelId, ?int $userId = null): ?array
    {
        $position = ReadingPosition::where('user_id', $userId ?? app(CurrentReader::class)->id())
            ->where('novel_id', $novelId)
            ->first();
        if ($position === null) {
            return null;
        }

        $chapter = NovelChapter::whereKey($position->novel_chapter_id)
            ->where('novel_id', $novelId)
            ->where('blacklist', 0)
            ->first(['id', 'novel_id', 'chapter', 'book', 'number', 'part', 'label', 'status']);
        if ($chapter === null) {
            return null;
        }

        $at = $position->recorded_at ?? $position->updated_at;

        return [
            'chapter_id' => (int) $chapter->id,
            'chapter_label' => $chapter->label ?: null,
            'chapter_number' => $chapter->displayNumber(),
            'progress' => $position->progress === null ? null : (int) $position->progress,
            'downloaded' => (bool) $chapter->status,
            'device_id' => $position->device_id,
            'device_name' => $position->device_name,
            'at' => $at?->copy()->utc()->toIso8601String(),
            'age_seconds' => $at ? max(0, (int) $at->diffInSeconds(now(), false)) : 0,
        ];
    }

    /** latest() plus the chapter's web reader URL — the readers' `latest_position`. */
    public function latestForWeb(int $novelId, ?int $userId = null): ?array
    {
        $position = $this->latest($novelId, $userId);
        if ($position !== null) {
            $position['url'] = route('chapters.show', $position['chapter_id']);
        }

        return $position;
    }
}
