<?php

namespace App\Support;

use App\Setting;
use App\User;
use Illuminate\Auth\AuthenticationException;

/**
 * Whose reading state a request (or command, or queued job) reads and writes.
 *
 * - Sign-in off (App\Support\AuthMode): always the library's owner (app
 *   setting owner_user_id).
 * - Sign-in on: the user App\Http\Middleware\IdentifyReader identified for
 *   this request — by web session, bearer token, HTTP Basic (OPDS) or a
 *   signed URL alike. The middleware rebinds this class per request with
 *   for($user), or guest() when nobody signed in (any read-state access then
 *   answers 401 / the login page instead of silently using the owner).
 * - Console commands and queue jobs: the owner (nothing rebinds it).
 *
 * Bound as a scoped instance (AppServiceProvider), so under Octane /
 * RoadRunner and in queue workers it is rebuilt per request / job: never
 * resolve it into a singleton, a static or a constructor of something that
 * outlives the request — ask the container (or App\Services\ReadState, which
 * does) at the point of use.
 */
class CurrentReader
{
    private ?int $id = null;

    private ?User $user = null;

    private bool $guest = false;

    /** A reader fixed to $user (the signed-in account; tests). */
    public static function for(User $user): self
    {
        $reader = new self();
        $reader->id = (int) $user->id;
        $reader->user = $user;

        return $reader;
    }

    /** A request nobody is signed in to (sign-in on, public route). */
    public static function guest(): self
    {
        $reader = new self();
        $reader->guest = true;

        return $reader;
    }

    public function isGuest(): bool
    {
        return $this->guest;
    }

    /** The reader's user id. No query when the settings cache is warm. */
    public function id(): int
    {
        if ($this->guest) {
            throw new AuthenticationException('Sign in to continue.');
        }

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
