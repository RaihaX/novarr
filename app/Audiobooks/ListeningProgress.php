<?php

namespace App\Audiobooks;

use App\Audiobook;
use App\Support\ReadingClient;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where each reader is in each audiobook: one audiobook_progress row per
 * (reader, audiobook), written by PUT /api/v1/audiobooks/{id}/progress
 * (the web player every 15 s and on pause; the Android app, also from its
 * offline queue).
 *
 * Last write wins by the time the client says the position was reached
 * (`recorded_at`, else the request's time; never later than now, so a fast
 * phone clock cannot pin a position): an older write is ignored and
 * answered `stale`, so a queue replayed hours later never moves a reader
 * back. The age check and the write are one statement (no race between
 * two devices). The row also keeps the device, client and address of the
 * write (App\Support\ReadingClient), as chapter progress does.
 */
class ListeningProgress
{
    public const TABLE = 'audiobook_progress';

    public const MIN_SPEED = 0.5;

    public const MAX_SPEED = 3.0;

    /** The reader's row for $audiobookId, or null. */
    public function get(int $userId, int $audiobookId): ?object
    {
        return DB::table(self::TABLE)->where('user_id', $userId)->where('audiobook_id', $audiobookId)->first();
    }

    /**
     * The reader's rows for these audiobooks, keyed by audiobook id (one statement).
     *
     * @param  int[]  $audiobookIds
     * @return array<int, object>
     */
    public function forMany(int $userId, array $audiobookIds): array
    {
        if ($audiobookIds === []) {
            return [];
        }

        return DB::table(self::TABLE)->where('user_id', $userId)->whereIn('audiobook_id', $audiobookIds)
            ->get()->keyBy(fn($row) => (int) $row->audiobook_id)->all();
    }

    /**
     * Record a position. $finished: true marks it finished (at $at), false
     * clears that, null leaves it as it is. The position is kept within the
     * book.
     *
     * @return array{row: object, stale: bool}
     */
    public function record(int $userId, Audiobook $audiobook, int $positionMs, float $speed, ?bool $finished, ?CarbonInterface $at = null, ?ReadingClient $client = null): array
    {
        $now = Carbon::now();
        $at = $at === null ? $now : Carbon::instance($at)->setTimezone($now->getTimezone());
        if ($at->greaterThan($now)) {
            $at = $now;
        }
        $at = $at->copy()->startOfSecond();

        $values = [
            'position_ms' => max(0, min($positionMs, max(0, (int) $audiobook->duration_ms))),
            'speed' => round(max(self::MIN_SPEED, min(self::MAX_SPEED, $speed)), 2),
            'device' => $client?->device,
            'client' => $client?->client,
            'ip' => $client?->ip,
            'updated_at' => $at,
        ];
        if ($finished !== null) {
            $values['finished_at'] = $finished ? $at : null;
        }

        $applied = $this->updateIfNotNewer($userId, (int) $audiobook->id, $values) > 0;
        if (!$applied && $this->get($userId, (int) $audiobook->id) === null) {
            try {
                DB::table(self::TABLE)->insert(['user_id' => $userId, 'audiobook_id' => (int) $audiobook->id] + $values + ['finished_at' => null]);
                $applied = true;
            } catch (UniqueConstraintViolationException) {
                // Another write made the row first: ours applies if it is not older.
                $applied = $this->updateIfNotNewer($userId, (int) $audiobook->id, $values) > 0;
            }
        }

        return ['row' => $this->get($userId, (int) $audiobook->id), 'stale' => !$applied];
    }

    private function updateIfNotNewer(int $userId, int $audiobookId, array $values): int
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('audiobook_id', $audiobookId)
            ->where(fn($q) => $q->whereNull('updated_at')->orWhere('updated_at', '<=', $values['updated_at']))
            ->update($values);
    }

    /**
     * The reader's started, unfinished audiobooks, newest progress first
     * (soft-deleted audiobooks left out).
     *
     * @return list<int>  audiobook ids
     */
    public function continueIds(int $userId, int $limit = 12): array
    {
        return DB::table(self::TABLE . ' as p')
            ->join('audiobooks as a', 'a.id', '=', 'p.audiobook_id')
            ->whereNull('a.deleted_at')
            ->where('p.user_id', $userId)
            ->whereNull('p.finished_at')
            ->where('p.position_ms', '>', 0)
            ->orderByDesc('p.updated_at')
            ->orderByDesc('p.audiobook_id')
            ->limit($limit)
            ->pluck('p.audiobook_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /** The API's progress object: {position_ms, finished, updated_at}, or null. */
    public static function present(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'position_ms' => (int) $row->position_ms,
            'finished' => $row->finished_at !== null,
            'updated_at' => \App\Http\Controllers\Api\V1\Presenter::timestamp($row->updated_at),
        ];
    }
}
