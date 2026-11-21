<?php

namespace App\Http\Controllers\Auth;

use App\Auth\BrowserGuard;
use App\AuthEvent;
use App\Http\Controllers\Controller;
use App\Services\BrowserSessions;
use App\Services\Credentials;
use App\Services\KnownDevices;
use App\Services\SecondStep;
use App\Services\Turnstile;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The web sign-in page (/login): name (or email) + password, always
 * remembered (a per-browser cookie, App\Auth\BrowserGuard). On the public
 * address a second step follows (App\Http\Controllers\Auth\SecondStepController),
 * unless this browser was remembered after one; "Sign in with a passkey"
 * replaces both (App\Http\Controllers\Auth\PasskeyLoginController). With
 * sign-in off there is nothing to sign in to: /login goes Home.
 */
class LoginController extends Controller
{
    /** Flashed after signing in / out: the page's JS empties the service worker's page caches. */
    public const FLASH_SIGNED = 'novarr_signed';

    public function show(Request $request)
    {
        if (!AuthMode::required()) {
            return redirect()->route('home');
        }
        if (Auth::guard('web')->user() !== null) {
            return redirect()->intended(route('home'));
        }

        return response()->view('auth.login', ['username' => (string) $request->old('username', '')])
            // The login page must never stand in for a real page in any cache.
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Credentials $credentials, Turnstile $turnstile)
    {
        if (!AuthMode::required()) {
            return redirect()->route('home');
        }

        $data = $request->validate([
            // A sign-in name or an email address (App\User::findForSignIn).
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ], [
            'username.required' => 'Enter your name or email.',
            'password.required' => 'Enter your password.',
        ]);

        $publicHost = PublicAddress::isPublicRequest($request);
        // The human check (public address, when configured) comes after the
        // limits and before the password: a missing or wrong answer counts
        // as a failed attempt; Cloudflare unreachable refuses (fails closed).
        $result = Turnstile::requiredFor($request)
            ? (Credentials::refusal($data['username'], $request->ip(), $publicHost) ?? $this->humanCheck($request, $turnstile, $data['username'], $publicHost))
            : null;
        $secondStep = SecondStep::requiredFor($request);
        // Not complete yet: finishSignIn() records it (after the second step when there is one).
        $result ??= $credentials->attempt($data['username'], $data['password'], $request->ip(), $publicHost, $request, 'web', complete: false);
        if (!$result instanceof User && (!empty($result['public_cap']) || !empty($result['account_lock']))) {
            // The public address's per-IP cap or the account's lock there: a
            // real 429 (with Retry-After), the form shown again with the reason.
            return static::tooManyPage($result['error'], (int) $result['retry_after'], (string) $data['username']);
        }
        if (!$result instanceof User) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => $result['error']])
                ->setStatusCode(302);
        }

        if ($secondStep) {
            return $this->toSecondStep($request, $result, (string) $data['username']);
        }

        return static::finishSignIn($request, $result, false, 'web', null, (string) $data['username']);
    }

    /**
     * The password was right on the public address: a remembered browser
     * (its remember-me row passed the second step) is done; an account with
     * no method it can use here is refused; otherwise the second-step page.
     */
    private function toSecondStep(Request $request, User $user, string $username)
    {
        $guard = Auth::guard('web');
        $row = $guard instanceof BrowserGuard ? $guard->cookieRow() : null;
        if ($row !== null && (int) $row->user_id === (int) $user->id && $row->second_step_passed_at !== null) {
            return static::finishSignIn($request, $user, true, 'web', 'remembered', $username);
        }

        $redirect = static::startSecondStep($request, $user, $username);
        if ($redirect === null) {
            Credentials::recordFailed($request, 'web', $user, 'no_second_step');

            return back()->withInput($request->only('username'))
                ->withErrors(['username' => SecondStep::UNAVAILABLE_MESSAGE])->setStatusCode(302);
        }

        return $redirect;
    }

    /**
     * After a correct password (or an invite just accepted) on the public
     * address: a challenge for $user held by this browser's session, and
     * the redirect to the second-step page; null when the account has no
     * method it can use here.
     */
    public static function startSecondStep(Request $request, User $user, string $username): ?\Illuminate\Http\RedirectResponse
    {
        $steps = app(SecondStep::class);
        if ($steps->methods($user, $request, web: true) === []) {
            return null;
        }

        [, $token] = $steps->begin($user, SecondStep::SIGN_IN, 'web');
        $request->session()->put(SecondStepController::SESSION_PENDING, [
            'token' => $token,
            'user' => (int) $user->id,
            'username' => $username,
        ]);

        return redirect()->route('login.second_step');
    }

    /**
     * The sign-in is done ($secondStep: it passed a second step, a passkey,
     * or this browser was remembered after one): signed in here, the limits
     * cleared, the history written, and a new browser's email sent. The
     * redirect to where the person was going, with the browser's cookie.
     */
    public static function finishSignIn(Request $request, User $user, bool $secondStep, string $via, ?string $method = null, ?string $username = null): \Illuminate\Http\RedirectResponse
    {
        $request->session()->forget(SecondStepController::SESSION_PENDING);
        static::signInHere($request, $user, $secondStep);
        $publicHost = PublicAddress::isPublicRequest($request);
        Credentials::completeSignIn($user, $username, $request->ip(), $publicHost, $request, $via, $method !== null ? ['method' => $method] : []);
        if ($method !== null && $method !== 'remembered' && $via !== 'passkey') {
            AuthEvent::record(AuthEvent::SECOND_STEP_PASSED, $request, (int) $user->id, ['via' => $via, 'method' => $method]);
        }
        // A browser this account has not signed in on: the owner of the
        // account gets an email (after the response).
        $device = app(KnownDevices::class)->webSignIn($request, $user);

        return redirect()->intended(route('home'))->withCookie($device);
    }

    /** Null when the human check passed, else attempt()'s error shape (422). */
    private function humanCheck(Request $request, Turnstile $turnstile, string $username, bool $publicHost): ?array
    {
        $outcome = $turnstile->verify((string) $request->input(Turnstile::FIELD, ''), $request->ip());
        if ($outcome === Turnstile::OK) {
            return null;
        }

        $user = User::findForSignIn($username);
        if ($outcome === Turnstile::UNAVAILABLE) {
            Credentials::recordFailed($request, 'web', $user, 'check_unavailable');

            return ['error' => Turnstile::UNAVAILABLE_MESSAGE, 'status' => 422];
        }

        Credentials::recordFailure($username, $request->ip(), $publicHost);
        Credentials::recordFailed($request, 'web', $user, 'check');

        return ['error' => Turnstile::INVALID_MESSAGE, 'status' => 422];
    }

    /** The sign-in page at 429 with $message under the name field. */
    public static function tooManyPage(string $message, int $retryAfter, string $username = ''): \Illuminate\Http\Response
    {
        $errors = (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['username' => $message]));

        return response()->view('auth.login', ['username' => $username, 'errors' => $errors], 429)
            ->header('Retry-After', (string) $retryAfter)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Sign $user in on this browser: remembered (its own remember-me row, a
     * year at most, 30 days unused), a new session id, stamped with the
     * sign-in time (App\User::sessionStillValid) and — $secondStep — with
     * the second step, counted as a fresh confirmation (Account page), and
     * the page told to empty its cached pages.
     */
    public static function signInHere(Request $request, User $user, bool $secondStep = false): void
    {
        $guard = Auth::guard('web');
        // A sign-in replaces this browser's previous remember-me row.
        if ($guard instanceof BrowserGuard && ($old = $guard->cookieRow()) !== null) {
            app(\App\Services\RememberTokens::class)->delete((int) $old->id);
        }
        $guard->login($user, false);
        $request->session()->regenerate();
        $session = $request->session();
        $session->put(User::SESSION_SIGNED_IN_AT, $stamp = $user->signInStamp());
        if ($secondStep) {
            $session->put(User::SESSION_SECOND_STEP_AT, $stamp);
        } else {
            $session->forget(User::SESSION_SECOND_STEP_AT);
        }
        \App\Support\RecentConfirmation::mark($request);
        if ($guard instanceof BrowserGuard) {
            $guard->rememberBrowser($user, $secondStep);
        }
        $session->flash(self::FLASH_SIGNED, 'in');
        // Listed under Account → Signed-in browsers.
        app(BrowserSessions::class)->start($request, $user, $stamp);
    }

    public function destroy(Request $request)
    {
        $user = AuthMode::required() ? Auth::guard('web')->user() : null;
        if ($user instanceof User) {
            AuthEvent::record(AuthEvent::SIGN_OUT, $request, (int) $user->id);
            app(BrowserSessions::class)->end($request);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Signing out to accept an invite (auth.invite): back to that link.
        $next = (string) $request->input('next', '');
        if (preg_match('#^/invite/[A-Za-z0-9]{1,128}$#', $next)) {
            return redirect($next)->with(self::FLASH_SIGNED, 'out');
        }

        if (!AuthMode::required()) {
            return redirect()->route('home');
        }

        return redirect()->route('login')->with(self::FLASH_SIGNED, 'out');
    }
}
