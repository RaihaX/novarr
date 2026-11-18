<?php

namespace App\Support;

use App\Setting;
use App\User;

/**
 * Whose reading state a request (or command, or queued job) reads and writes.
 *
 * Stage 1 of multi-user: always the library's owner (app setting
 * owner_user_id) — there is no sign-in yet. Bound as a scoped instance
 * (AppServiceProvider), so under Octane / RoadRunner and in queue workers it
 * is rebuilt per request / job: never resolve it into a singleton, a static
 * or a constructor of something that outlives the request — ask the
 * container (or App\Services\ReadState, which does) at the point of use.
 */
class CurrentReader
{
    private ?int $id = null;

    private ?User $user = null;

    /** A reader fixed to $user (tests; later: the signed-in account). */
    public static function for(User $user): self
    {
        $reader = new self();
        $reader->id = (int) $user->id;
        $reader->user = $user;

        return $reader;
    }

    /** The reader's user id. No query when the settings cache is warm. */
    public function id(): int
    {
        return $this->id ??= static::ownerId();
    }

    public function user(): User
    {
        return $this->user ??= User::findOrFail($this->id());
    }

    /**
     * The owner's user id: the owner_user_id setting (written by the
     * 2026_11_19_000001 migration), else the lowest-id admin.
     */
    public static function ownerId(): int
    {
        $id = (int) Setting::get('owner_user_id', 0);
        if ($id > 0) {
            return $id;
        }

        $id = (int) User::where('role', User::ROLE_ADMIN)->orderBy('id')->value('id');
        if ($id > 0) {
            return $id;
        }

        throw new \RuntimeException('Novarr has no owner account: run php artisan migrate.');
    }
}
