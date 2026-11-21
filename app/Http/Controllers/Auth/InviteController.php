<?php

namespace App\Http\Controllers\Auth;

use App\AuthEvent;
use App\Http\Controllers\Controller;
use App\Support\AccountRules;
use App\Services\Credentials;
use App\Services\Turnstile;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use App\User;
use App\UserInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /invite/{token} — public (App\Support\RouteAccess), throttled per address.
 * The invited person chooses a display name, sign-in name and password; the
 * account is made as a reader with the invite's email, the invite is used
 * up, and they are signed in (remembered, like the login page) and sent
 * Home with a welcome notice.
 *
 * A link that is wrong, expired, cancelled or already used gets one friendly
 * page (the same for all, so nothing is learned about an email address).
 * Someone already signed in is told so, with a sign-out button, instead of
 * being switched to the new account. With "Require sign-in" off the account
 * is still made, and the page says sign-in is not needed yet.
 */
class InviteController extends Controller
{
    /** Flash key: the one-time welcome notice on Home (layouts.app). */
    public const FLASH_WELCOME = 'novarr_welcome';

    public function show(Request $request, string $token)
    {
        $invite = UserInvite::findUsable($token);
        if ($invite === null) {
            return $this->unusable();
        }

        return $this->page('auth.invite', [
            'invite' => $invite,
            'token' => $token,
            'signedIn' => $this->signedInUser(),
            'suggested' => AccountRules::suggestUsername($invite->name, $invite->email),
            'authOn' => AuthMode::required(),
        ]);
    }

    public function accept(Request $request, string $token)
    {
        // The public address: every acceptance counts toward the per-IP cap
        // on credential attempts (App\Services\Credentials).
        if (PublicAddress::isPublicRequest($request)) {
            if (($seconds = Credentials::publicCapRetryAfter($request->ip())) !== null) {
                Credentials::recordLimited($request, 'invite', 'address', null, Credentials::publicCapKey($request->ip()));

                return $this->tooMany($request, $seconds);
            }
            Credentials::hitPublicCap($request->ip());
        }

        $invite = UserInvite::findUsable($token);
        if ($invite === null) {
            return $this->unusable();
        }
        // Signed in already: the page explains (never switch accounts silently).
        if ($this->signedInUser() !== null) {
            return redirect()->route('invite.show', ['token' => $token]);
        }

        // The human check (public address, when configured). Cloudflare
        // unreachable refuses, as on the sign-in form.
        if (Turnstile::requiredFor($request)) {
            $outcome = app(Turnstile::class)->verify((string) $request->input(Turnstile::FIELD, ''), $request->ip());
            if ($outcome !== Turnstile::OK) {
                return back()
                    ->withInput($request->except(['password', 'password_confirmation', Turnstile::FIELD]))
                    ->withErrors(['turnstile' => $outcome === Turnstile::UNAVAILABLE ? Turnstile::UNAVAILABLE_MESSAGE : Turnstile::INVALID_MESSAGE]);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'username' => AccountRules::username(),
            'password' => AccountRules::password(confirmed: true, names: [$request->input('name'), $request->input('username'), $invite->email]),
        ], [
            'name.required' => 'Enter your name.',
            'username.required' => 'Choose a name to sign in with.',
            'password.required' => 'Choose a password.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        // An account was made for this address since the invite was sent.
        if (User::findByEmail($invite->email) !== null) {
            return $this->unusable();
        }

        $user = DB::transaction(function () use ($invite, $data) {
            // Claim the invite first: two submits at once make one account.
            $claimed = UserInvite::whereKey($invite->id)->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);
            if ($claimed !== 1) {
                return null;
            }

            $user = new User();
            $user->forceFill([
                'name' => trim($data['name']),
                'username' => User::normaliseUsername($data['username']),
                'email' => $invite->email,
                'role' => User::ROLE_READER,
                'password' => $data['password'],
                'password_set_at' => now(),
            ])->save();
            UserInvite::whereKey($invite->id)->update(['user_id' => $user->id]);

            return $user;
        });
        if ($user === null) {
            return $this->unusable();
        }
        AuthEvent::record(AuthEvent::ACCOUNT_CREATED, $request, (int) $user->id, ['via' => 'invite', 'invite_id' => (int) $invite->id]);
        AuthEvent::record(AuthEvent::INVITE_ACCEPTED, $request, (int) $user->id, ['invite_id' => (int) $invite->id, 'email' => $invite->email]);

        if (!AuthMode::required()) {
            return $this->page('auth.invite-done', ['user' => $user]);
        }

        // The public address: the new account signs in like any other, with
        // the second step (an emailed code: the invite went to that address).
        if (\App\Services\SecondStep::requiredFor($request)) {
            return LoginController::startSecondStep($request, $user, (string) $user->username)?->with(self::FLASH_WELCOME, $user->name)
                ?? redirect()->route('login')->withErrors(['username' => \App\Services\SecondStep::UNAVAILABLE_MESSAGE]);
        }

        LoginController::signInHere($request, $user);
        // The browser the account was made on is its first known device (no email).
        $device = app(\App\Services\KnownDevices::class)->webSignIn($request, $user, notify: false);

        return redirect()->route('home')->with(self::FLASH_WELCOME, $user->name)->withCookie($device);
    }

    /** The account signed in on this browser (only meaningful while sign-in is on). */
    private function signedInUser(): ?User
    {
        if (!AuthMode::required()) {
            return null;
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    private function unusable()
    {
        return $this->page('auth.invite-invalid', [], 404);
    }

    /** 429 with Retry-After and the sign-in throttle's sentence. */
    private function tooMany(Request $request, int $seconds)
    {
        $message = Credentials::tooManyMessage($seconds);
        if (\App\Http\Middleware\IdentifyReader::wantsJson($request)) {
            return response()->json(['message' => $message, 'code' => 'throttled'], 429, ['Retry-After' => (string) $seconds]);
        }

        return $this->page('auth.forbidden', ['message' => $message, 'title' => 'Too many attempts'], 429)
            ->header('Retry-After', (string) $seconds);
    }

    /** Pages here are never cached (a used link must not come back from a cache). */
    private function page(string $view, array $data, int $status = 200)
    {
        return response()->view($view, $data, $status)->header('Cache-Control', 'no-store, private');
    }
}
