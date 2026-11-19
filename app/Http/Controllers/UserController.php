<?php

namespace App\Http\Controllers;

use App\ApiToken;
use App\Http\Controllers\Auth\LoginController;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Settings → Users (admin; also while sign-in is off, so accounts can be
 * prepared before the switch): the accounts, and the "Require sign-in"
 * switch.
 *
 * Rules: one admin — the owner — whose account cannot be deleted, disabled
 * or demoted (roles are not editable at all in this release). Deleting a
 * reader deletes their read state, positions, highlights and tokens
 * (App\User's deleted hook). The admin types a reader's first password.
 */
class UserController extends Controller
{
    public function index()
    {
        $ownerId = CurrentReader::ownerId();

        return view('settings.users', [
            'users' => User::orderByRaw('id = ? DESC', [$ownerId])->orderBy('name')->orderBy('id')->get(),
            'ownerId' => $ownerId,
            'tokenCounts' => ApiToken::whereNull('revoked_at')->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id')->pluck('n', 'user_id'),
            'authOn' => AuthMode::required(),
            'authSetting' => AuthMode::setting(),
            'authForced' => AuthMode::forced(),
            'authRefused' => AuthMode::forcedOnButRefused(),
            'cannotTurnOn' => AuthMode::cannotTurnOnReason(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validateWithBag('add', [
            'name' => 'required|string|max:80',
            'username' => $this->usernameRules(),
            'password' => ['required', 'string', Password::min(8), 'max:255'],
        ], $this->messages());

        $user = new User();
        $user->forceFill([
            'name' => trim($data['name']),
            'username' => User::normaliseUsername($data['username']),
            'role' => User::ROLE_READER,
            'password' => $data['password'],
            'password_set_at' => now(),
        ])->save();

        return redirect()->route('settings.users')->with('status', "Added {$user->name}. They sign in as “{$user->username}”.");
    }

    /** Display name and username (any account, the owner's too). */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validateWithBag('edit' . $user->id, [
            'name' => 'required|string|max:80',
            'username' => $this->usernameRules($user->id),
        ], $this->messages());

        $user->forceFill([
            'name' => trim($data['name']),
            'username' => User::normaliseUsername($data['username']),
        ])->save();

        return redirect()->route('settings.users')->with('status', "Saved {$user->name}.");
    }

    /** Set a new password (the owner's own while sign-in is off; a reader's reset). */
    public function password(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validateWithBag('password' . $user->id, [
            'password' => ['required', 'string', Password::min(8), 'max:255'],
        ], ['password.required' => 'Type the new password.']);

        $user->setNewPassword($data['password']);

        return redirect()->route('settings.users')->with('status', "New password set for {$user->name}.");
    }

    public function toggleDisabled($id)
    {
        $user = User::findOrFail($id);
        if ($user->isOwner()) {
            return redirect()->route('settings.users')->with('error', 'The owner account cannot be turned off.');
        }

        $user->forceFill(['disabled_at' => $user->isDisabled() ? null : now()])->save();

        return redirect()->route('settings.users')->with('status', $user->isDisabled()
            ? "Turned off {$user->name}: they are signed out everywhere and cannot sign in."
            : "Turned {$user->name} back on.");
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        if ($user->isOwner()) {
            return redirect()->route('settings.users')->with('error', 'The owner account cannot be deleted.');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('settings.users')->with('status', "Deleted {$name} and their reading history.");
    }

    /** The "Require sign-in" switch. */
    public function auth(Request $request)
    {
        $on = $request->boolean('required');

        if (AuthMode::forced() !== null) {
            return redirect()->route('settings.users')
                ->with('error', 'Sign-in is set by NOVARR_AUTH on the server, so it cannot be changed here.');
        }
        if ($on && ($reason = AuthMode::cannotTurnOnReason()) !== null) {
            return redirect()->route('settings.users')->with('error', $reason);
        }

        $wasOn = AuthMode::required();
        AuthMode::store($on);

        if ($on && !$wasOn) {
            // Whoever turned it on is the owner (with sign-in off every
            // request is): sign them in on the spot so they are not locked out.
            $owner = User::findOrFail(CurrentReader::ownerId());
            Auth::guard('web')->login($owner, true);
            $request->session()->regenerate();
            $request->session()->flash(LoginController::FLASH_SIGNED, 'in');

            return redirect()->route('settings.users')
                ->with('status', "Sign-in is on. You are signed in as {$owner->username}. The iOS app and Kindle need to sign in / be linked again.");
        }

        return redirect()->route('settings.users')->with('status', $on ? 'Sign-in is on.' : 'Sign-in is off: everyone using Novarr is the owner again.');
    }

    private function usernameRules(?int $ignoreId = null): array
    {
        return [
            'required', 'string', 'max:60',
            function ($attribute, $value, $fail) {
                if (!preg_match(User::USERNAME_PATTERN, User::normaliseUsername($value))) {
                    $fail('Use 2 to 60 letters, numbers, dots, dashes or underscores, starting with a letter or number.');
                }
            },
            function ($attribute, $value, $fail) use ($ignoreId) {
                $taken = User::where('username', User::normaliseUsername($value))
                    ->when($ignoreId !== null, fn($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();
                if ($taken) {
                    $fail('Someone already signs in with that name.');
                }
            },
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Enter a name.',
            'username.required' => 'Enter the name they sign in with.',
            'password.required' => 'Type a first password for them.',
        ];
    }
}
