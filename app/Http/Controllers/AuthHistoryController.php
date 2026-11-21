<?php

namespace App\Http\Controllers;

use App\AuthEvent;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Http\Request;

/**
 * Settings → Users → Sign-in history (admin; so home network only, like
 * every admin page): the sign-in history (App\AuthEvent), newest first,
 * 50 a page, filtered by account and by group (sign-ins, failures, admin
 * actions). Works with sign-in on or off: accounts can be prepared, and
 * their history read, before the switch.
 */
class AuthHistoryController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request)
    {
        $userId = $request->integer('user') ?: null;
        $group = isset(AuthEvent::GROUPS[$request->query('group')]) ? (string) $request->query('group') : null;

        $events = AuthEvent::query()
            ->when($userId !== null, fn($q) => $q->where(fn($q) => $q->where('user_id', $userId)->orWhere('actor_user_id', $userId)))
            ->group($group)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ownerId = CurrentReader::ownerId();

        return view('settings.history', [
            'events' => $events,
            'users' => User::orderByRaw('id = ? DESC', [$ownerId])->orderBy('name')->orderBy('id')->get(['id', 'name', 'username']),
            'names' => User::whereIn('id', $events->getCollection()->flatMap(fn(AuthEvent $e) => [$e->user_id, $e->actor_user_id])->filter()->unique())
                ->get(['id', 'name', 'username'])->keyBy('id'),
            'userId' => $userId,
            'group' => $group,
        ]);
    }
}
