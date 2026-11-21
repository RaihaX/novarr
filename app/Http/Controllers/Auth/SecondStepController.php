<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Credentials;
use App\Services\Passkeys;
use App\Services\SecondStep;
use App\Support\AuthMode;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The web second-step page (/login/second-step), after a correct password
 * on the public address (LoginController::toSecondStep). Public routes
 * (App\Support\RouteAccess), throttled per address; the pending state is
 * a challenge (App\Services\SecondStep) whose token only this browser's
 * session holds. It offers the account's methods in the order passkey,
 * authenticator app, emailed code ("Email me a code": never sent on its
 * own), recovery code. Five wrong answers end it; so do ten minutes.
 */
class SecondStepController extends Controller
{
    /** Session key: ['token', 'user', 'username'] of the pending sign-in. */
    public const SESSION_PENDING = 'novarr_second_step_pending';

    /** Flash: the emailed code went out (shows where to). */
    public const FLASH_SENT = 'novarr_code_sent';

    public function __construct(private SecondStep $steps)
    {
    }

    public function show(Request $request)
    {
        [$challenge, $user] = $this->pending($request);
        if ($challenge === null) {
            return $this->startAgain(SecondStep::EXPIRED_MESSAGE);
        }

        $methods = $this->steps->methods($user, $request, web: true);
        $email = SecondStep::emailAddress($user);

        return response()->view('auth.second-step', [
            'methods' => $methods,
            'emailHint' => $email !== null ? SecondStep::emailHint($email) : null,
            'sent' => (bool) session(self::FLASH_SENT) || (int) $challenge->email_sends > 0,
            'triesLeft' => SecondStep::MAX_WRONG - (int) $challenge->wrong_tries,
            'passkeysHere' => Passkeys::available($request),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** "Email me a code". */
    public function email(Request $request)
    {
        [$challenge, $user] = $this->pending($request);
        if ($challenge === null) {
            return $this->startAgain(SecondStep::EXPIRED_MESSAGE);
        }
        if (($refused = $this->refused($request, $user, false)) !== null) {
            return $refused;
        }

        $error = $this->steps->sendEmailCode($challenge, $user, $request);
        if ($error === null) {
            return redirect()->route('login.second_step')->with(self::FLASH_SENT, true)->withFragment('email');
        }
        $message = $error['error'] === 'throttled'
            ? static::tooManyCodesMessage((int) $error['retry_after'])
            : 'Emailed codes are not available for this account.';

        return redirect()->route('login.second_step')->withErrors(['email' => $message])->withFragment('email');
    }

    /** One answer: method = totp | email | recovery (code) or passkey (credential JSON, from the page's script). */
    public function verify(Request $request)
    {
        $json = $request->expectsJson() || $request->isJson();
        [$challenge, $user] = $this->pending($request);
        if ($challenge === null) {
            return $this->startAgain(SecondStep::EXPIRED_MESSAGE, $json);
        }
        if (($refused = $this->refused($request, $user, $json)) !== null) {
            return $refused;
        }

        $method = (string) $request->input('method', '');
        $answer = $method === SecondStep::PASSKEY ? $request->input('credential') : (string) $request->input('code', '');
        [$outcome, $left] = $this->steps->check($challenge, $user, $method, $answer, $request);

        if ($outcome === SecondStep::OK) {
            $pending = (array) $request->session()->get(self::SESSION_PENDING, []);
            $redirect = LoginController::finishSignIn($request, $user, true, 'web', $method, $pending['username'] ?? null);

            return $json ? static::jsonRedirect($redirect) : $redirect;
        }
        if ($outcome === SecondStep::LOCKED) {
            return $this->refusedFor($request, $left, $json);
        }
        if ($outcome === SecondStep::DEAD || $outcome === SecondStep::EXPIRED) {
            return $this->startAgain($outcome === SecondStep::DEAD ? SecondStep::TOO_MANY_WRONG_MESSAGE : SecondStep::EXPIRED_MESSAGE, $json);
        }

        $message = ($method === SecondStep::PASSKEY ? Passkeys::FAILED_MESSAGE : SecondStep::WRONG_CODE_MESSAGE)
            . ' ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.';
        if ($json) {
            return response()->json(['message' => $message, 'tries_left' => $left], 422);
        }

        return redirect()->route('login.second_step')->withErrors([$method ?: 'code' => $message])->withFragment($method ?: 'code');
    }

    /** The passkey options for this account on this address (JSON, the page's script). */
    public function passkeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        [$challenge, $user] = $this->pending($request);
        if ($challenge === null) {
            return response()->json(['message' => SecondStep::EXPIRED_MESSAGE, 'redirect' => route('login')], 404);
        }
        if (!Passkeys::available($request)) {
            return response()->json(['message' => Passkeys::UNAVAILABLE_MESSAGE], 422);
        }

        return response()->json($passkeys->requestOptions($request, Passkeys::SECOND_STEP, $user));
    }

    /** The pending challenge and its account, or [null, null]. */
    private function pending(Request $request): array
    {
        if (!AuthMode::required() || !$request->hasSession()) {
            return [null, null];
        }
        $pending = $request->session()->get(self::SESSION_PENDING);
        if (!is_array($pending) || !isset($pending['token'], $pending['user'])) {
            return [null, null];
        }
        $challenge = $this->steps->find((string) $pending['token'], SecondStep::SIGN_IN, 'web');
        $user = $challenge === null ? null : User::find($challenge->user_id);
        if ($challenge === null || $user === null || (int) $challenge->user_id !== (int) $pending['user'] || $user->isDisabled()) {
            $request->session()->forget(self::SESSION_PENDING);

            return [null, null];
        }

        return [$challenge, $user];
    }

    /** The public cap or the account's lock: 429 (the page, or JSON). */
    private function refused(Request $request, User $user, bool $json)
    {
        $seconds = Credentials::publicRefusal($request, $user);

        return $seconds === null ? null : $this->refusedFor($request, $seconds, $json);
    }

    private function refusedFor(Request $request, int $seconds, bool $json)
    {
        $request->session()->forget(self::SESSION_PENDING);
        if ($json) {
            return Credentials::tooManyJson($seconds);
        }

        return LoginController::tooManyPage(Credentials::tooManyMessage($seconds), $seconds);
    }

    private function startAgain(string $message, bool $json = false)
    {
        request()->session()->forget(self::SESSION_PENDING);
        if ($json) {
            return response()->json(['message' => $message, 'redirect' => route('login')], 404);
        }

        return redirect()->route('login')->withErrors(['username' => $message]);
    }

    public static function jsonRedirect(\Illuminate\Http\RedirectResponse $redirect): JsonResponse
    {
        $response = response()->json(['redirect' => $redirect->getTargetUrl()]);
        foreach ($redirect->headers->getCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public static function tooManyCodesMessage(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return $seconds < 120
            ? "Too many codes asked for. Try again in {$seconds} " . ($seconds === 1 ? 'second.' : 'seconds.')
            : "Too many codes asked for. Try again in {$minutes} minutes.";
    }
}
