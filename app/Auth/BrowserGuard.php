<?php

namespace App\Auth;

use App\Services\RememberTokens;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

/**
 * The `web` guard (config auth.guards.web, driver "novarr"): Laravel's
 * session guard with per-browser remember-me (App\Services\RememberTokens)
 * instead of the one users.remember_token every browser shared.
 *
 *   - the remember-me cookie ("selector|token") names one row; signing out
 *     deletes only that row, so other browsers stay remembered;
 *   - users.remember_token is never read or cycled any more: cookies of the
 *     old scheme are not honoured anywhere (the cookie name differs too:
 *     it includes this class);
 *   - on the public address, with sign-in on, a row that did not pass the
 *     second step (made on the home network) does not sign in.
 *
 * Guards are made per request (App\Http\Middleware\IdentifyReader forgets
 * them first, as Octane does), so the row kept here never outlives it.
 */
class BrowserGuard extends SessionGuard
{
    /** Session key: the remember_tokens row of this browser's sign-in. */
    public const SESSION_REMEMBER_ID = 'novarr_remember_id';

    /** The row the current request signed in with by cookie. */
    private ?object $recalledRow = null;

    protected function recaller()
    {
        if ($this->request === null) {
            return null;
        }
        $value = $this->request->cookies->get($this->getRecallerName());

        return is_string($value) && $value !== '' ? new RememberCookie($value) : null;
    }

    protected function userFromRecaller($recaller)
    {
        if (!$recaller instanceof RememberCookie || !$recaller->valid() || $this->recallAttempted) {
            return null;
        }
        $this->recallAttempted = true;

        $row = app(RememberTokens::class)->find($recaller->value());
        if ($row === null) {
            return null;
        }
        // Made without the second step (the home network): not on the public address.
        if ($row->second_step_passed_at === null && $this->request !== null
            && AuthMode::required() && PublicAddress::isPublicRequest($this->request)) {
            return null;
        }
        $user = $this->provider->retrieveById($row->user_id);
        if ($user === null) {
            return null;
        }

        $this->viaRemember = true;
        $this->recalledRow = $row;
        app(RememberTokens::class)->touch((int) $row->id);
        $this->session->put(self::SESSION_REMEMBER_ID, (int) $row->id);

        return $user;
    }

    /**
     * Treat this request as not signed in, without touching the session or
     * the cookie (a sign-in that does not count on this address).
     */
    public function forgetForThisRequest(): void
    {
        $this->user = null;
        $this->loggedOut = true;
    }

    /** The row this request signed in with by its remember-me cookie, or null. */
    public function recalledRow(): ?object
    {
        return $this->recalledRow;
    }

    /** The live row this browser's cookie names (without signing in with it), or null. */
    public function cookieRow(): ?object
    {
        $recaller = $this->recaller();

        return $recaller instanceof RememberCookie && $recaller->valid()
            ? app(RememberTokens::class)->find($recaller->value())
            : null;
    }

    /**
     * Remember this browser for $user: a new row (second step passed or
     * not) and its cookie, queued for the response. Returns the row id.
     */
    public function rememberBrowser(User $user, bool $secondStep): int
    {
        [$id, $value] = app(RememberTokens::class)->issue($user, $secondStep);
        $this->getCookieJar()->queue($this->createRecaller($value));
        $this->session->put(self::SESSION_REMEMBER_ID, $id);

        return $id;
    }

    /** login($user, true) from anywhere else: a row without the second step. */
    protected function queueRecallerCookie(AuthenticatableContract $user)
    {
        if ($user instanceof User) {
            $this->rememberBrowser($user, false);
        }
    }

    /** users.remember_token is not used. */
    protected function ensureRememberTokenIsSet(AuthenticatableContract $user)
    {
    }

    /** Signing out one browser never signs out the others. */
    protected function cycleRememberToken(AuthenticatableContract $user)
    {
    }

    public function logout()
    {
        $this->forgetThisBrowsersRow();
        parent::logout();
    }

    public function logoutCurrentDevice()
    {
        $this->forgetThisBrowsersRow();
        parent::logoutCurrentDevice();
    }

    /** Delete the row of this browser: the one in its session, and the one its cookie names. */
    private function forgetThisBrowsersRow(): void
    {
        $tokens = app(RememberTokens::class);
        $id = $this->session->get(self::SESSION_REMEMBER_ID);
        if (is_numeric($id)) {
            $tokens->delete((int) $id);
        }
        $this->session->remove(self::SESSION_REMEMBER_ID);
        if (($row = $this->cookieRow()) !== null) {
            $tokens->delete((int) $row->id);
        }
    }
}
