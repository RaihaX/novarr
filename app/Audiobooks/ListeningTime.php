<?php

namespace App\Audiobooks;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Time spent listening (the Stats page's "hours listened"), counted from
 * consecutive progress writes of one reader in one audiobook
 * (ListeningProgress::record() calls add()):
 *
 *   listened = min( forward position delta / speed,
 *                   wall-clock time between the two writes + SLACK_SECONDS,
 *                   MAX_SECONDS_PER_WRITE )
 *
 * so a pause (long wall-clock gap, no movement) adds nothing, a seek
 * forward adds at most the time that really passed, and a write replayed
 * from an offline queue counts by its own recorded time. Going back adds
 * nothing. The first write for a book has nothing to compare with and adds
 * nothing either (clients write every 15 s while playing).
 *
 * Seconds are rolled up per (reader, day, hour, audiobook) in the app
 * timezone, in listening_activity.
 */
class ListeningTime
{
    public const TABLE = 'listening_activity';

    /** Clock rounding between the two writes' times (whole seconds). */
    public const SLACK_SECONDS = 2;

    /** One write never adds more than this (clients write every 15 s while playing). */
    public const MAX_SECONDS_PER_WRITE = 600;

    /**
     * Seconds listened between a previous write ($fromMs at $fromAt) and a
     * new one ($toMs at $toAt, at $speed). Pure: no database.
     */
    public static function listenedSeconds(int $fromMs, CarbonInterface $fromAt, int $toMs, CarbonInterface $toAt, float $speed): int
    {
        $forwardMs = $toMs - $fromMs;
        if ($forwardMs <= 0) {
            return 0;
        }
        $speed = max(ListeningProgress::MIN_SPEED, min(ListeningProgress::MAX_SPEED, $speed > 0 ? $speed : 1.0));
        $wall = $toAt->getTimestamp() - $fromAt->getTimestamp();
        if ($wall < 0) {
            return 0;
        }
        $byPosition = $forwardMs / 1000 / $speed;

        return (int) floor(max(0, min($byPosition, $wall + self::SLACK_SECONDS, self::MAX_SECONDS_PER_WRITE)));
    }

    /** Adds $seconds to the reader's row for the hour of $at (app timezone). */
    public function add(int $userId, int $audiobookId, CarbonInterface $at, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $local = $at->copy()->setTimezone(config('app.timezone'));
        $key = [
            'user_id' => $userId,
            'day' => $local->toDateString(),
            'hour' => (int) $local->format('G'),
            'audiobook_id' => $audiobookId,
        ];

        $updated = DB::table(self::TABLE)->where($key)->increment('seconds', $seconds);
        if ($updated > 0) {
            return;
        }
        try {
            DB::table(self::TABLE)->insert($key + ['seconds' => $seconds]);
        } catch (UniqueConstraintViolationException) {
            DB::table(self::TABLE)->where($key)->increment('seconds', $seconds);
        }
    }
}
