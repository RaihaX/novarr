<?php

namespace App\Http\Controllers;

use App\ApiToken;
use App\AuthEvent;
use App\Http\Controllers\Auth\SecondStepController;
use App\Passkey;
use App\Services\Authenticator;
use App\Services\Credentials;
use App\Services\Passkeys;
use App\Services\RecoveryCodes;
use App\Services\SecondStep;
use App\Support\AuthMode;
use App\Support\CurrentReader;
use App\Support\RecentConfirmation;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Account → Sign-in steps and Catalogue passwords (any signed-in reader,
 * sign-in on only, like the rest of /account):
 *
 *   - "Confirm it's you" (confirm*): the password on the home network, a
 *     second step on the public address; good for ten minutes on this
 *     browser (App\Support\RecentConfirmation);
 *   - passkeys: add (the browser makes one for this address) and remove;
 *   - the authenticator app: set up (QR code drawn here, then a code to
 *     prove it works) and remove;
 *   - recovery codes: make a new set (shown once; the old set stops working);
 *   - emailed codes: turn off (only with a passkey or authenticator) or on;
 *   - catalogue passwords: make one (shown once) and revoke.
 *
 * Every change but revoking a catalogue password needs a recent
 * confirmation. Confirming is limited like signing in: the public
 * address's per-IP cap and the account's lock are asked first, and every
 * wrong code counts toward them (App\Services\SecondStep::check), so a
 * code cannot be guessed here by making challenge after challenge. Removing the last passkey / authenticator turns emailed
 * codes back on, so nobody is left without a second step by accident.
 */
class AccountSecurityController extends Controller
{
    /** Session key: the authenticator secret being set up (encrypted) and until when. */
    public const SESSION_TOTP_SETUP = 'novarr_totp_setup';

    /** Session key: the token of this browser's "Confirm it's you" challenge (public address). */
    public const SESSION_CONFIRM = 'novarr_confirm_challenge';

    /** Flashes shown once on the Account page. */
    public const FLASH_RECOVERY_CODES = 'novarr_recovery_codes';

    public const FLASH_CATALOGUE_PASSWORD = 'novarr_catalogue_password';

    public const FLASH_CONFIRM_NEEDED = 'novarr_confirm_needed';

    public const TOTP_SETUP_SECONDS = 900;

    public const CONFIRMED_MESSAGE = 'Confirmed. You can change your sign-in settings for the next 10 minutes.';

    public function __construct(
        private SecondStep $steps,
        private Passkeys $passkeys,
        private Authenticator $authenticator,
        private RecoveryCodes $recoveryCodes,
    ) {
    }

    // ---------------------------------------------------------------- Confirm it's you

    public function confirm(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        $user = $reader->user();
        $json = $request->expectsJson() || $request->isJson();
        // The same limits as signing in, before anything is looked at.
        if (($seconds = Credentials::publicRefusal($request, $user)) !== null) {
            return $json ? Credentials::tooManyJson($seconds) : static::tooManyPage($seconds, Credentials::tooManyMessage($seconds));
        }

        if (!RecentConfirmation::needsSecondStep($request)) {
            $result = RecentConfirmation::checkPassword($user, (string) $request->input('password', ''), $request);
            if (is_int($result)) {
                return static::tooManyPage($result);
            }
            if ($result === false) {
                return static::toConfirm(['password' => 'That is not your password.']);
            }
            RecentConfirmation::mark($request);

            return redirect()->route('account')->withFragment('security')->with('status', self::CONFIRMED_MESSAGE);
        }

        // The code typed first (the authenticator, a recovery code) makes this browser's challenge.
        $challenge = $this->confirmChallenge($request, $user, create: true);
        $method = (string) $request->input('method', '');
        $answer = $method === SecondStep::PASSKEY ? $request->input('credential') : (string) $request->input('code', '');
        [$outcome, $left] = $this->steps->check($challenge, $user, $method, $answer, $request);

        if ($outcome === SecondStep::OK) {
            $request->session()->forget(self::SESSION_CONFIRM);
            RecentConfirmation::mark($request);
            AuthEvent::record(AuthEvent::SECOND_STEP_PASSED, $request, (int) $user->id, ['via' => 'web', 'method' => $method, 'purpose' => 'confirm']);
            $redirect = redirect()->route('account')->withFragment('security')->with('status', self::CONFIRMED_MESSAGE);

            return $json ? SecondStepController::jsonRedirect($redirect) : $redirect;
        }
        if ($outcome === SecondStep::LOCKED) {
            return $json ? Credentials::tooManyJson($left) : static::tooManyPage($left, Credentials::tooManyMessage($left));
        }
        if ($outcome !== SecondStep::WRONG) {
            $request->session()->forget(self::SESSION_CONFIRM);
            $message = $outcome === SecondStep::DEAD ? 'Too many wrong codes. Try again.' : 'That took too long. Try again.';

            return $json ? response()->json(['message' => $message], 404) : static::toConfirm(['code' => $message]);
        }
        $message = ($method === SecondStep::PASSKEY ? Passkeys::FAILED_MESSAGE : SecondStep::WRONG_CODE_MESSAGE)
            . ' ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.';

        return $json ? response()->json(['message' => $message, 'tries_left' => $left], 422) : static::toConfirm(['code' => $message]);
    }

    /** Public address: email a code to confirm with. */
    public function confirmEmail(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        $user = $reader->user();
        if (($seconds = Credentials::publicRefusal($request, $user)) !== null) {
            return static::tooManyPage($seconds, Credentials::tooManyMessage($seconds));
        }
        $challenge = $this->confirmChallenge($request, $user, create: true);
        $error = $this->steps->sendEmailCode($challenge, $user, $request);
        if ($error !== null) {
            $message = $error['error'] === 'throttled'
                ? SecondStepController::tooManyCodesMessage((int) $error['retry_after'])
                : 'Emailed codes are not available for this account.';

            return static::toConfirm(['code' => $message]);
        }

        return redirect()->route('account')->withFragment('confirm')
            ->with(self::FLASH_CONFIRM_NEEDED, true)
            ->with('status', 'We emailed you a code. It works for 10 minutes.');
    }

    /** Public address: the passkey options to confirm with (JSON). */
    public function confirmPasskeyOptions(Request $request, CurrentReader $reader): JsonResponse
    {
        abort_unless(AuthMode::required(), 404);
        if (!Passkeys::available($request)) {
            return response()->json(['message' => Passkeys::UNAVAILABLE_MESSAGE], 422);
        }
        $user = $reader->user();
        if (($seconds = Credentials::publicRefusal($request, $user)) !== null) {
            return Credentials::tooManyJson($seconds);
        }
        $this->confirmChallenge($request, $user, create: true);

        return response()->json($this->passkeys->requestOptions($request, Passkeys::CONFIRM, $user));
    }

    /** This browser's confirm challenge (made when $create and missing or expired). */
    private function confirmChallenge(Request $request, User $user, bool $create): ?object
    {
        $token = $request->session()->get(self::SESSION_CONFIRM);
        $challenge = is_string($token) ? $this->steps->find($token, SecondStep::CONFIRM, 'web') : null;
        if ($challenge !== null && (int) $challenge->user_id === (int) $user->id) {
            return $challenge;
        }
        if (!$create) {
            return null;
        }
        [$challenge, $token] = $this->steps->begin($user, SecondStep::CONFIRM, 'web');
        $request->session()->put(self::SESSION_CONFIRM, $token);

        return $challenge;
    }

    // ---------------------------------------------------------------- passkeys

    public function passkeyOptions(Request $request, CurrentReader $reader): JsonResponse
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::confirmJson();
        }
        if (!Passkeys::available($request)) {
            return response()->json(['message' => Passkeys::UNAVAILABLE_MESSAGE], 422);
        }

        return response()->json($this->passkeys->creationOptions($request, $reader->user()));
    }

    public function storePasskey(Request $request, CurrentReader $reader): JsonResponse
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::confirmJson();
        }
        $user = $reader->user();
        $name = Passkeys::cleanName((string) $request->input('name', ''));
        $result = $this->passkeys->register($request, $user, $request->input('credential'), $name);
        if (is_string($result)) {
            return response()->json(['message' => $result], 422);
        }
        AuthEvent::record(AuthEvent::METHOD_ADDED, $request, (int) $user->id, ['method' => 'passkey', 'name' => $result->name]);
        session()->flash('status', "Passkey “{$result->name}” added." . ($this->recoveryCodes->remaining($user) === 0 ? ' Make recovery codes too, in case you lose it.' : ''));

        return response()->json(['redirect' => route('account') . '#security']);
    }

    public function destroyPasskey(Request $request, CurrentReader $reader, $id)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $user = $reader->user();
        $passkey = Passkey::where('user_id', $user->id)->whereKey($id)->firstOrFail();
        $passkey->delete();
        AuthEvent::record(AuthEvent::METHOD_REMOVED, $request, (int) $user->id, ['method' => 'passkey', 'name' => $passkey->name]);
        $this->afterRemoving($user);

        return redirect()->route('account')->withFragment('security')->with('status', "Removed the passkey “{$passkey->name}”.");
    }

    // ---------------------------------------------------------------- authenticator app

    public function startAuthenticator(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $request->session()->put(self::SESSION_TOTP_SETUP, [
            'secret' => Crypt::encryptString($this->authenticator->newSecret()),
            'expires' => now()->getTimestamp() + self::TOTP_SETUP_SECONDS,
        ]);

        return redirect()->route('account')->withFragment('authenticator');
    }

    public function storeAuthenticator(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $user = $reader->user();
        $secret = static::setupSecret($request);
        if ($secret === null) {
            return redirect()->route('account')->withFragment('authenticator')
                ->withErrors(['code' => 'That took too long. Start again.'], 'authenticator');
        }
        $step = $this->authenticator->matchStep($secret, (string) $request->input('code', ''));
        if ($step === null) {
            return redirect()->route('account')->withFragment('authenticator')
                ->withErrors(['code' => 'That code is not right. Check the time on your phone and type the newest code.'], 'authenticator');
        }

        $replacing = $this->authenticator->secretOf($user) !== null;
        $this->authenticator->enable($user, $secret, $step);
        $request->session()->forget(self::SESSION_TOTP_SETUP);
        AuthEvent::record(AuthEvent::METHOD_ADDED, $request, (int) $user->id, ['method' => 'authenticator'] + ($replacing ? ['replaced' => true] : []));

        return redirect()->route('account')->withFragment('security')->with('status', 'The authenticator app is set up.'
            . ($this->recoveryCodes->remaining($user) === 0 ? ' Make recovery codes too, in case you lose your phone.' : ''));
    }

    public function destroyAuthenticator(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $user = $reader->user();
        $request->session()->forget(self::SESSION_TOTP_SETUP);
        if ($this->authenticator->secretOf($user) !== null || $user->totp_secret !== null) {
            $this->authenticator->disable($user);
            AuthEvent::record(AuthEvent::METHOD_REMOVED, $request, (int) $user->id, ['method' => 'authenticator']);
            $this->afterRemoving($user);
        }

        return redirect()->route('account')->withFragment('security')->with('status', 'The authenticator app is removed.');
    }

    /** The secret being set up in this browser, or null (none, or expired). */
    public static function setupSecret(Request $request): ?string
    {
        $setup = $request->session()->get(self::SESSION_TOTP_SETUP);
        if (!is_array($setup) || !isset($setup['secret'], $setup['expires']) || (int) $setup['expires'] < now()->getTimestamp()) {
            return null;
        }
        try {
            return Crypt::decryptString($setup['secret']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------- recovery codes, emailed codes

    public function recoveryCodes(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $user = $reader->user();
        if (!$this->steps->hasStrongMethod($user)) {
            return redirect()->route('account')->withFragment('security')
                ->with('error', 'Add a passkey or the authenticator app first: recovery codes stand in for them.');
        }
        $codes = $this->recoveryCodes->regenerate($user);
        AuthEvent::record(AuthEvent::RECOVERY_CODES_MADE, $request, (int) $user->id);

        return redirect()->route('account')->withFragment('recovery')->with(self::FLASH_RECOVERY_CODES, $codes);
    }

    public function emailCodes(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $user = $reader->user();
        $on = $request->boolean('on');
        if (!$on && !$this->steps->hasStrongMethod($user)) {
            return redirect()->route('account')->withFragment('security')
                ->with('error', 'Add a passkey or the authenticator app before turning emailed codes off.');
        }
        $user->forceFill(['email_codes_off_at' => $on ? null : now()])->save();
        if ($user->wasChanged('email_codes_off_at')) {
            AuthEvent::record(AuthEvent::EMAIL_CODES_SWITCHED, $request, (int) $user->id, ['on' => $on]);
        }

        return redirect()->route('account')->withFragment('security')
            ->with('status', $on ? 'Emailed sign-in codes are on.' : 'Emailed sign-in codes are off.');
    }

    // ---------------------------------------------------------------- catalogue passwords

    public function storeCataloguePassword(Request $request, CurrentReader $reader)
    {
        abort_unless(AuthMode::required(), 404);
        if (!RecentConfirmation::fresh($request)) {
            return static::toConfirm();
        }
        $data = $request->validateWithBag('catalogue', [
            'name' => 'required|string|max:80',
        ], ['name.required' => 'Name it after the app or e-reader that will use it.']);
        $user = $reader->user();
        [$token, $plain] = ApiToken::issue($user, $data['name'], null, ApiToken::KIND_CATALOGUE);
        AuthEvent::record(AuthEvent::CATALOGUE_PASSWORD_CREATED, $request, (int) $user->id, ['token_id' => (int) $token->id, 'name' => $token->name]);

        return redirect()->route('account')->withFragment('catalogue')
            ->with(self::FLASH_CATALOGUE_PASSWORD, ['name' => $token->name, 'password' => $plain]);
    }

    public function destroyCataloguePassword(Request $request, CurrentReader $reader, $id)
    {
        abort_unless(AuthMode::required(), 404);
        $token = ApiToken::where('user_id', $reader->id())->where('kind', ApiToken::KIND_CATALOGUE)->whereKey($id)->firstOrFail();
        if ($token->revoked_at === null) {
            $token->revoke();
            AuthEvent::record(AuthEvent::CATALOGUE_PASSWORD_REVOKED, $request, (int) $token->user_id, ['token_id' => (int) $token->id, 'name' => $token->name]);
        }

        return redirect()->route('account')->withFragment('catalogue')->with('status', "Revoked the catalogue password “{$token->name}”.");
    }

    // ---------------------------------------------------------------- helpers

    /** The last passkey / authenticator is gone: emailed codes are on again. */
    private function afterRemoving(User $user): void
    {
        if (!$this->steps->hasStrongMethod($user) && $user->email_codes_off_at !== null) {
            $user->forceFill(['email_codes_off_at' => null])->save();
        }
    }

    /** Back to the Account page's "Confirm it's you" (with $errors). */
    public static function toConfirm(array $errors = []): \Illuminate\Http\RedirectResponse
    {
        $redirect = redirect()->route('account')->withFragment('confirm')->with(self::FLASH_CONFIRM_NEEDED, true);
        // Linking a device: the device being linked is kept across the confirmation.
        if (session()->has('pairing_id')) {
            $redirect->with('pairing_id', session('pairing_id'));
        }

        return $redirect->withErrors($errors !== [] ? $errors : ['confirm' => RecentConfirmation::NEEDED_MESSAGE], 'confirm');
    }

    /** 428 Precondition Required: the page's script goes to "Confirm it's you". */
    public static function confirmJson(): JsonResponse
    {
        session()->flash(self::FLASH_CONFIRM_NEEDED, true);

        return response()->json([
            'code' => 'confirm_required',
            'message' => RecentConfirmation::NEEDED_MESSAGE,
            'redirect' => route('account') . '#confirm',
        ], 428);
    }

    public static function tooManyPage(int $seconds, ?string $message = null)
    {
        return response()->view('auth.forbidden', [
            'message' => $message ?? 'Too many wrong passwords. Try again in ' . $seconds . ' ' . ($seconds === 1 ? 'second.' : 'seconds.'),
            'title' => 'Too many attempts',
        ], 429)->header('Retry-After', (string) $seconds);
    }
}
