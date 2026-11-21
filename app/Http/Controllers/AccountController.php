<?php

namespace App\Http\Controllers;

use App\ApiToken;
use App\AuthEvent;
use App\DevicePairing;
use App\Services\BrowserSessions;
use App\Services\Credentials;
use App\Support\AccountRules;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\Support\RecentConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * /account — any signed-in user: their display name and password, the
 * devices signed in with a token (Revoke), and "Link a device" (type the
 * code a Kindle shows → see which device → confirm).
 *
 * Only while "Require sign-in" is on; with it off there are no accounts in
 * use (Settings → Users is where they are prepared), so these answer 404.
 */
class AccountController extends Controller
{
    /** Code lookups per user per minute (wrong or right). */
    public const LINK_ATTEMPTS = 10;

    /** Own sign-ins and failed attempts listed under "Recent sign-ins". */
    public const RECENT_SIGN_INS = 20;

    public function show(CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        $user = $reader->user();

        $request = request();
        $steps = app(\App\Services\SecondStep::class);
        $authenticator = app(\App\Services\Authenticator::class);
        $setupSecret = AccountSecurityController::setupSecret($request);
        $setupUri = $setupSecret !== null ? $authenticator->uri($setupSecret, $user) : null;
        $email = \App\Services\SecondStep::emailAddress($user);
        $publicHost = \App\Support\PublicAddress::isPublicRequest($request);

        return view('account.index', [
            'user' => $user,
            'tokens' => ApiToken::where('user_id', $user->id)->where('kind', ApiToken::KIND_APP)->whereNull('revoked_at')
                ->orderByRaw('last_used_at IS NULL')->orderByDesc('last_used_at')->orderByDesc('id')->get(),
            'catalogue' => ApiToken::where('user_id', $user->id)->where('kind', ApiToken::KIND_CATALOGUE)->whereNull('revoked_at')
                ->orderByDesc('id')->get(),
            // Sign-in steps (App\Http\Controllers\AccountSecurityController)
            'security' => (object) [
                'confirmed' => RecentConfirmation::fresh($request),
                'confirmWithSecondStep' => RecentConfirmation::needsSecondStep($request),
                'confirmMethods' => $publicHost ? $steps->methods($user, $request, web: true) : [],
                'emailHint' => $email !== null ? \App\Services\SecondStep::emailHint($email) : null,
                'passkeys' => \App\Passkey::where('user_id', $user->id)->orderBy('id')->get(),
                'passkeysHere' => \App\Services\Passkeys::available($request),
                'rpId' => \App\Services\Passkeys::rpId($request),
                'totpOn' => $authenticator->secretOf($user) !== null,
                'totpSetup' => $setupSecret === null ? null : (object) [
                    'secret' => trim(chunk_split($setupSecret, 4, ' ')),
                    'qr' => $authenticator->qrSvg($setupUri),
                ],
                'email' => $email,
                'emailOn' => $steps->emailCodesOn($user),
                'strong' => $steps->hasStrongMethod($user),
                'recoveryLeft' => app(\App\Services\RecoveryCodes::class)->remaining($user),
                'publicHost' => $publicHost,
            ],
            'pairing' => session('pairing_id') ? DevicePairing::find(session('pairing_id')) : null,
            'browsers' => app(BrowserSessions::class)->forUser($user, request()),
            'signIns' => AuthEvent::where('user_id', $user->id)
                ->whereIn('event', [AuthEvent::SIGN_IN, AuthEvent::SIGN_IN_FAILED, AuthEvent::SECOND_STEP_FAILED])
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(self::RECENT_SIGN_INS)->get(),
        ]);
    }

    public function updateProfile(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        $data = $request->validate([
            'name' => 'required|string|max:80',
        ], ['name.required' => 'Enter a name.']);

        $user = $reader->user();
        $user->forceFill(['name' => trim($data['name'])])->save();
        if ($user->wasChanged('name')) {
            AuthEvent::record(AuthEvent::ACCOUNT_EDITED, $request, (int) $user->id, ['changed' => ['display name']]);
        }

        return redirect()->route('account')->with('status', 'Name saved.');
    }

    public function updatePassword(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        $user = $reader->user();
        $data = $request->validateWithBag('password', [
            'current_password' => 'required|string',
            'password' => AccountRules::password(confirmed: true, names: [$user->name, $user->username, $user->email]),
        ], [
            'current_password.required' => 'Enter your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        // The public address: a recent second step first (the current
        // password alone is not enough there).
        if (RecentConfirmation::needsSecondStep($request) && !RecentConfirmation::fresh($request)) {
            return AccountSecurityController::toConfirm();
        }
        $checked = RecentConfirmation::checkPassword($user, $data['current_password'], $request);
        if (is_int($checked)) {
            return AccountSecurityController::tooManyPage($checked);
        }
        if ($checked === false) {
            return back()->withErrors(['current_password' => 'That is not your current password.'], 'password');
        }

        $user->setNewPassword($data['password']);
        AuthEvent::record(AuthEvent::PASSWORD_CHANGED, $request, (int) $user->id);
        // Other browsers signed in with the old password are signed out;
        // this one stays, and so do the apps and devices listed below.
        $secondStep = $request->session()->has(\App\User::SESSION_SECOND_STEP_AT);
        $user->signOutEverywhere(keepTokens: true);
        \App\Http\Controllers\Auth\LoginController::signInHere($request, $user->fresh(), $secondStep);

        return redirect()->route('account')->with('status', 'Password changed. Other browsers are signed out; your apps and devices stay signed in.');
    }

    /**
     * "Sign out all other browsers": every other web session of this account
     * ends, with its remember-me cookie (App\User::signOutEverywhere); this
     * browser is signed in again on the spot; apps and devices stay.
     */
    public function signOutOtherBrowsers(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        $user = $reader->user();

        $secondStep = $request->session()->has(\App\User::SESSION_SECOND_STEP_AT);
        $user->signOutEverywhere(keepTokens: true);
        \App\Http\Controllers\Auth\LoginController::signInHere($request, $user->fresh(), $secondStep);
        AuthEvent::record(AuthEvent::SIGNED_OUT_EVERYWHERE, $request, (int) $user->id, ['kept' => 'this browser, apps and devices']);

        return redirect()->route('account')->withFragment('browsers')
            ->with('status', 'Signed out all other browsers. This browser and your apps and devices stay signed in.');
    }

    /**
     * "Sign out" on one row of Signed-in browsers: that browser's session
     * ends and its remember-me goes; every other browser stays signed in.
     * The current browser's own row is an ordinary sign-out.
     */
    public function signOutBrowser(Request $request, CurrentReader $reader, BrowserSessions $browsers, $id)
    {
        abort_unless(AuthMode::required(), 404);
        $user = $reader->user();
        $row = $browsers->forUser($user, $request)->firstWhere('id', (int) $id);
        abort_if($row === null, 404);
        if ($row->current) {
            return app(\App\Http\Controllers\Auth\LoginController::class)->destroy($request);
        }

        $browsers->signOut($request, $user, (int) $id);
        AuthEvent::record(AuthEvent::BROWSER_SIGNED_OUT, $request, (int) $user->id, ['device' => $row->device, 'ip' => $row->ip]);

        return redirect()->route('account')->withFragment('browsers')->with('status', "Signed out {$row->device}. Your other browsers stay signed in.");
    }

    public function revokeToken(Request $request, CurrentReader $reader, $id)
    {
        abort_unless(AuthMode::required(), 404);
        $token = ApiToken::where('user_id', $reader->id())->where('kind', ApiToken::KIND_APP)->whereKey($id)->firstOrFail();
        if ($token->revoked_at === null) {
            $token->revoke();
            AuthEvent::record(AuthEvent::TOKEN_REVOKED, $request, (int) $token->user_id, ['via' => 'account', 'token_id' => (int) $token->id, 'name' => $token->name]);
        }

        return redirect()->route('account')->with('status', "Revoked “{$token->name}”: it is signed out.");
    }

    /** Step 1: the typed code → which device would be linked (or why not). */
    public function linkLookup(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);

        if ($throttled = $this->throttled($reader)) {
            return $throttled;
        }

        $pairing = DevicePairing::pendingByCode($request->input('code'));
        if ($pairing === null) {
            $message = DevicePairing::normaliseCode($request->input('code')) === null
                ? 'A code is 6 letters and numbers, like K7M 4QX.'
                : 'That code is wrong or has expired. Codes last 10 minutes: ask the device for a new one.';

            return back()->withErrors(['code' => $message], 'link')->withInput();
        }

        return redirect()->route('account')->with('pairing_id', $pairing->id)->withFragment('link');
    }

    /** Step 2: confirmed → the device gets a token for this account (collected by its next poll). */
    public function linkConfirm(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);

        // Counted too, or the confirm step could be used to guess codes.
        if ($throttled = $this->throttled($reader)) {
            return $throttled;
        }
        // Giving a device a token for this account: a recent confirmation
        // (the device stays chosen meanwhile).
        if (!RecentConfirmation::fresh($request)) {
            session()->now('pairing_id', (int) $request->input('pairing'));

            return AccountSecurityController::toConfirm();
        }

        $pairing = DevicePairing::where('id', (int) $request->input('pairing'))
            ->where('code', DevicePairing::normaliseCode($request->input('code')) ?? '')
            ->where('expires_at', '>', now())
            ->whereNull('user_id')
            ->first();
        if ($pairing === null) {
            return redirect()->route('account')->withFragment('link')
                ->withErrors(['code' => 'That code has expired or was already used. Ask the device for a new one.'], 'link');
        }

        $token = $pairing->link($reader->user());
        if ($token === null) {
            return redirect()->route('account')->withFragment('link')
                ->withErrors(['code' => 'That code has expired or was already used. Ask the device for a new one.'], 'link');
        }
        AuthEvent::record(AuthEvent::TOKEN_ISSUED, $request, (int) $token->user_id, ['via' => 'pairing', 'token_id' => (int) $token->id, 'name' => $token->name]);

        return redirect()->route('account')->withFragment('link')
            ->with('status', "Linked “{$pairing->displayName()}”. It signs in on its own within a few seconds.");
    }

    /**
     * Code entry is limited per user (LINK_ATTEMPTS a minute, lookups and
     * confirmations together): a redirect with the error once over, else null.
     */
    private function throttled(CurrentReader $reader)
    {
        $key = 'novarr-link:' . $reader->id();
        if (RateLimiter::tooManyAttempts($key, self::LINK_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return redirect()->route('account')->withFragment('link')
                ->withErrors(['code' => "Too many codes tried. Wait {$seconds} seconds and try again."], 'link')->withInput();
        }
        RateLimiter::hit($key, 60);

        return null;
    }
}
