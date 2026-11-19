<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Credentials;
use App\Support\AuthMode;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The web sign-in page (/login): name + password, always remembered (a
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
            'username' => 'required|string|max:60',
            'password' => 'required|string|max:255',
        ], [
            'username.required' => 'Enter your name.',
            'password.required' => 'Enter your password.',
        ]);

        $result = $credentials->attempt($data['username'], $data['password'], $request->ip());
        if (!$result instanceof User) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => $result['error']])
                ->setStatusCode(302);
        }

        static::signInHere($request, $result);

        return redirect()->intended(route('home'));
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
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if (!AuthMode::required()) {
            return redirect()->route('home');
        }

        return redirect()->route('login')->with(self::FLASH_SIGNED, 'out');
    }
}
