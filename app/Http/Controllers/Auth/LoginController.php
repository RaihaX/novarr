<?php

namespace App\Http\Controllers\Auth;

use App\AuthEvent;
use App\Http\Controllers\Controller;
use App\Services\Credentials;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The web sign-in page (/login): name (or email) + password, always remembered (a
 * one-year cookie, config auth.guards.web.remember). With sign-in off there
 * is nothing to sign in to: /login goes Home.
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

    public function store(Request $request, Credentials $credentials)
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

        $result = $credentials->attempt($data['username'], $data['password'], $request->ip(), PublicAddress::isPublicRequest($request), $request, 'web');
        if (!$result instanceof User && !empty($result['public_cap'])) {
            // The public address's per-IP cap: a real 429 (with Retry-After),
            // the form shown again with the reason.
            return static::tooManyPage($result['error'], (int) $result['retry_after'], (string) $data['username']);
        }
        if (!$result instanceof User) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => $result['error']])
                ->setStatusCode(302);
        }

        static::signInHere($request, $result);

        return redirect()->intended(route('home'));
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
     * Sign $user in on this browser: remembered (a year), a new session id,
     * stamped with the sign-in time (App\User::sessionStillValid), and the
     * page told to empty its cached pages.
     */
    public static function signInHere(Request $request, User $user): void
    {
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $request->session()->put(User::SESSION_SIGNED_IN_AT, $user->signInStamp());
        $request->session()->flash(self::FLASH_SIGNED, 'in');
    }

    public function destroy(Request $request)
    {
        $user = AuthMode::required() ? Auth::guard('web')->user() : null;
        if ($user instanceof User) {
            AuthEvent::record(AuthEvent::SIGN_OUT, $request, (int) $user->id);
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
