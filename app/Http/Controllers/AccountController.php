<?php

namespace App\Http\Controllers;

use App\ApiToken;
use App\AuthEvent;
use App\DevicePairing;
use App\Services\Credentials;
use App\Support\AccountRules;
use App\Support\AuthMode;
use App\Support\CurrentReader;
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

        return view('account.index', [
            'user' => $user,
            'tokens' => ApiToken::where('user_id', $user->id)->whereNull('revoked_at')
                ->orderByRaw('last_used_at IS NULL')->orderByDesc('last_used_at')->orderByDesc('id')->get(),
            'pairing' => session('pairing_id') ? DevicePairing::find(session('pairing_id')) : null,
            'signIns' => AuthEvent::where('user_id', $user->id)
                ->whereIn('event', [AuthEvent::SIGN_IN, AuthEvent::SIGN_IN_FAILED])
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
            'password' => AccountRules::password(confirmed: true),
        ], [
            'current_password.required' => 'Enter your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        if (!Credentials::check($data['current_password'], (string) $user->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.'], 'password');
        }

        $user->setNewPassword($data['password']);
        AuthEvent::record(AuthEvent::PASSWORD_CHANGED, $request, (int) $user->id);
        // Other browsers signed in with the old password are signed out;
        // this one stays, and so do the apps and devices listed below.
        $user->signOutEverywhere(keepTokens: true);
        \App\Http\Controllers\Auth\LoginController::signInHere($request, $user->fresh());

        return redirect()->route('account')->with('status', 'Password changed. Other browsers are signed out; your apps and devices stay signed in.');
    }

    public function revokeToken(Request $request, CurrentReader $reader, $id)
    {
        abort_unless(AuthMode::required(), 404);
        $token = ApiToken::where('user_id', $reader->id())->whereKey($id)->firstOrFail();
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
