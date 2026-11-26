<?php

namespace App\Services;

use App\Jobs\SendEpubToReader;
use App\Novel;
use App\User;
use Illuminate\Support\Facades\Cache;

/**
 * "Send to my Kindle / email": a reader emails a book's or novel's ePub to
 * their own address (users.send_to_email; for the owner, the Settings
 * "Kindle email" when they have not set one). The work is a queued job
 * (App\Jobs\SendEpubToReader): build the ePub when it is missing or older
 * than the newest chapter, then mail it. Its progress is kept per reader and
 * series in the cache for the page / app to poll:
 *
 *   queued → sending → sent | failed (with a message)
 */
class SendEpub
{
    public const QUEUED = 'queued';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    /** How long a send's outcome is remembered. */
    private const TTL_MINUTES = 60;

    /** Sends one reader may start per hour. */
    public const PER_HOUR = 20;

    public static function address(User $user): ?string
    {
        $own = trim((string) $user->send_to_email);
        if ($own !== '' && filter_var($own, FILTER_VALIDATE_EMAIL)) {
            return $own;
        }
        if ($user->isAdmin()) {
            $kindle = trim((string) setting('kindle_email', config('mail.kindle_email')));

            return filter_var($kindle, FILTER_VALIDATE_EMAIL) ? $kindle : null;
        }

        return null;
    }

    /** The address readers add to Amazon's approved senders. */
    public static function fromAddress(): string
    {
        return (string) config('mail.from.address');
    }

    public static function isKindle(?string $address): bool
    {
        return $address !== null && (bool) preg_match('/@(free\.)?kindle\.(com|cn)$/i', $address);
    }

    private static function key(int $userId, int $novelId): string
    {
        return "send_epub_{$userId}_{$novelId}";
    }

    /** @return array{status: string, message: ?string, to: ?string}|null */
    public static function state(int $userId, int $novelId): ?array
    {
        return Cache::get(self::key($userId, $novelId));
    }

    public static function put(int $userId, int $novelId, string $status, ?string $to, ?string $message = null): void
    {
        Cache::put(self::key($userId, $novelId), ['status' => $status, 'message' => $message, 'to' => $to], now()->addMinutes(self::TTL_MINUTES));
    }

    /** Queue the send, unless one for this series is already on its way. */
    public static function start(User $user, Novel $novel, string $to): array
    {
        $current = self::state((int) $user->id, (int) $novel->id);
        if ($current !== null && in_array($current['status'], [self::QUEUED, self::SENDING], true)) {
            return $current;
        }

        self::put((int) $user->id, (int) $novel->id, self::QUEUED, $to);
        SendEpubToReader::dispatch((int) $user->id, (int) $novel->id, $to)->onQueue('commands');

        return self::state((int) $user->id, (int) $novel->id) ?? ['status' => self::QUEUED, 'message' => null, 'to' => $to];
    }
}
