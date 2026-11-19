<?php

namespace App\Services\Requests;

use App\RequestNotification;
use App\User;

/**
 * The phone apps' notification feed (GET /api/v1/notifications?since=):
 * request_notifications rows addressed to the caller, plus — for an admin
 * account — those addressed to the admins (user_id null).
 *
 * The cursor is opaque to clients ("rn1:" + the last item id, base64url):
 * ids only grow, so "after the cursor" is stable and never repeats an item.
 * Without a cursor nothing older than BACKLOG_HOURS is returned. Each query
 * walks the (user_id, id) index; no source is ever called.
 */
class RequestFeed
{
    public const BACKLOG_HOURS = 24;

    public const LIMIT = 100;

    /** Rows older than this are deleted by `requests:availability`. */
    public const KEEP_DAYS = 30;

    /**
     * @return array{cursor: string, has_more: bool, items: array<int, array>}
     */
    public function read(User $user, ?string $since): array
    {
        $after = static::decode($since);
        $admin = $user->isAdmin();

        $query = fn(?int $recipient) => RequestNotification::query()
            ->when($recipient === null, fn($q) => $q->whereNull('user_id'), fn($q) => $q->where('user_id', $recipient))
            ->when($after !== null, fn($q) => $q->where('id', '>', $after))
            ->when($after === null, fn($q) => $q->where('created_at', '>=', now()->subHours(self::BACKLOG_HOURS)))
            ->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();

        $rows = $query((int) $user->id);
        if ($admin) {
            $rows = $rows->concat($query(null))->sortBy('id')->values();
        }

        $hasMore = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);

        if ($rows->isNotEmpty()) {
            $cursor = (int) $rows->last()->id;
        } elseif ($after !== null) {
            $cursor = $after;
        } else {
            // A first call with nothing to show: everything so far is seen.
            $cursor = (int) (RequestNotification::max('id') ?? 0);
        }

        return [
            'cursor' => static::encode($cursor),
            'has_more' => $hasMore,
            'items' => $rows->map(fn(RequestNotification $n) => [
                'id' => (int) $n->id,
                'kind' => (string) $n->kind,
                'title' => (string) $n->title,
                'body' => (string) $n->body,
                'request_id' => $n->novel_request_id !== null ? (int) $n->novel_request_id : null,
                'novel_id' => $n->novel_id !== null ? (int) $n->novel_id : null,
                'at' => \App\Http\Controllers\Api\V1\Presenter::timestamp($n->created_at),
            ])->all(),
        ];
    }

    public static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode('rn1:' . $id), '+/', '-_'), '=');
    }

    /** The id a cursor stands for; null for none or one this server did not issue. */
    public static function decode(?string $cursor): ?int
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '' || strlen($cursor) > 64) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return is_string($raw) && preg_match('/^rn1:(\d{1,18})$/', $raw, $m) ? (int) $m[1] : null;
    }

    /** Drop items older than KEEP_DAYS; the number removed. */
    public static function prune(): int
    {
        return RequestNotification::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
