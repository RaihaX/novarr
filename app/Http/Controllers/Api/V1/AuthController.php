<?php

namespace App\Http\Controllers\Api\V1;

use App\ApiToken;
use App\AuthEvent;
use App\Http\Controllers\Auth\SecondStepController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\IdentifyReader;
use App\Passkey;
use App\Services\Credentials;
use App\Services\KnownDevices;
use App\Services\SecondStep;
use App\Support\CurrentReader;
use App\Support\PublicAddress;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Password sign-in for the native clients (auth contract):
 *
 *   POST   /api/v1/auth/token  {username, password, device_id, device_name}
 *          → 200 {token, user}
 *          | 401 {code: second_step_required, message, challenge, expires_in, methods, email_hint?}
 *            (the public address, sign-in on: App\Services\SecondStep)
 *          | 403 {code: second_step_unavailable, message}
 *          | 422 {message} | 429 {code: throttled, message, retry_after}
 *   POST   /api/v1/auth/token/email-code   {challenge, device_id}
 *          → 204 | 404 {code: challenge_expired, message} | 422 {code: method_unavailable, message}
 *          | 429 {code: throttled, message, retry_after}
 *   POST   /api/v1/auth/token/second-step  {challenge, method: totp|email|recovery, code, device_id}
 *          (device_id required when the password request sent one: the
 *          challenge is bound to it; missing or different → 404)
 *          → 200 {token, user} | 422 {code: wrong_code, message, tries_left ≥ 1}
 *          | 404 {code: challenge_expired, message} (timed out, used, or the 5th wrong code)
 *          | 429 {code: throttled, message, retry_after}
 *   GET    /api/v1/auth/me     → 200 {user} (sign-in off: the owner, admin)
 *   DELETE /api/v1/auth/token  → 204, revokes the token in use
 *
 * Every 429 also has Retry-After. Tokens can be made while sign-in is off
 * too (a client may sign in ahead of the switch); they are only used for
 * identity once it is on.
 */
class AuthController extends Controller
{
    public function store(Request $request, Credentials $credentials, SecondStep $steps): JsonResponse
    {
        $data = $request->validate([
            // A sign-in name or an email address (App\User::findForSignIn).
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'device_id' => 'nullable|string|max:255',
            'device_name' => 'nullable|string|max:255',
        ]);

        $secondStep = SecondStep::requiredFor($request);
        $result = $credentials->attempt($data['username'], $data['password'], $request->ip(), PublicAddress::isPublicRequest($request), $request, 'api', complete: !$secondStep);
        if (!$result instanceof User) {
            if ($result['status'] === 429) {
                return Credentials::tooManyJson((int) $result['retry_after'], $result['error']);
            }

            return response()->json(['message' => $result['error']], $result['status']);
        }

        if ($secondStep) {
            $methods = $steps->methods($result, $request, web: false);
            if ($methods === []) {
                Credentials::recordFailed($request, 'api', $result, 'no_second_step');
                // Only a passkey (web only): the way in is linking with a code.
                $message = Passkey::where('user_id', $result->id)->exists() ? SecondStep::PASSKEY_ONLY_MESSAGE : SecondStep::UNAVAILABLE_MESSAGE;

                return response()->json(['code' => 'second_step_unavailable', 'message' => $message], 403, [], JSON_UNESCAPED_UNICODE);
            }

            [, $challenge] = $steps->begin($result, SecondStep::SIGN_IN, 'api', $data['device_id'] ?? null, $data['device_name'] ?? null);
            $body = [
                'code' => 'second_step_required',
                'message' => SecondStep::REQUIRED_MESSAGE,
                'challenge' => $challenge,
                'expires_in' => SecondStep::TTL,
                'methods' => $methods,
            ];
            if (in_array(SecondStep::EMAIL, $methods, true)) {
                $body['email_hint'] = SecondStep::emailHint((string) SecondStep::emailAddress($result));
            }

            // Unescaped: "r•••@a•••.com.au" as the contract writes it.
            return response()->json($body, 401, [], JSON_UNESCAPED_UNICODE);
        }

        return $this->issue($request, $result, $data['device_id'] ?? null, $data['device_name'] ?? null, 'password');
    }

    /** POST /auth/token/email-code {challenge}: email a code for the pending sign-in. */
    public function emailCode(Request $request, SecondStep $steps)
    {
        $data = $request->validate(['challenge' => 'required|string|max:128', 'device_id' => 'nullable|string|max:255']);
        [$challenge, $user] = $this->challenge($steps, $data['challenge']);
        if ($challenge === null || !static::sameDevice($challenge, $data['device_id'] ?? null)) {
            return static::expired(SecondStep::EXPIRED_MESSAGE);
        }
        if (($seconds = Credentials::publicRefusal($request, $user)) !== null) {
            return Credentials::tooManyJson($seconds);
        }

        $error = $steps->sendEmailCode($challenge, $user, $request);
        if ($error === null) {
            return response()->noContent();
        }
        if ($error['error'] === 'throttled') {
            $seconds = (int) $error['retry_after'];

            return Credentials::tooManyJson($seconds, SecondStepController::tooManyCodesMessage($seconds));
        }

        return response()->json(['code' => 'method_unavailable', 'message' => 'Emailed codes are not available for this account.'], 422);
    }

    /** POST /auth/token/second-step {challenge, method, code}: finish the sign-in, get the token. */
    public function secondStep(Request $request, SecondStep $steps): JsonResponse
    {
        $data = $request->validate([
            'challenge' => 'required|string|max:128',
            'method' => 'required|string|in:totp,email,recovery',
            'code' => 'required|string|max:64',
            'device_id' => 'nullable|string|max:255',
        ]);

        if (PublicAddress::isPublicRequest($request) && ($seconds = Credentials::publicCapRetryAfter($request->ip())) !== null) {
            return Credentials::tooManyJson($seconds);
        }
        [$challenge, $user] = $this->challenge($steps, $data['challenge']);
        // Bound to the device that typed the password: without its device_id
        // (or with another) it is not this sign-in.
        if ($challenge === null || !static::sameDevice($challenge, $data['device_id'] ?? null)) {
            return static::expired(SecondStep::EXPIRED_MESSAGE);
        }
        if (($seconds = Credentials::publicRefusal($request, $user)) !== null) {
            return Credentials::tooManyJson($seconds);
        }

        [$outcome, $left] = $steps->check($challenge, $user, $data['method'], $data['code'], $request);
        if ($outcome === SecondStep::LOCKED) {
            return Credentials::tooManyJson($left);
        }
        if ($outcome === SecondStep::WRONG) {
            return response()->json(['code' => 'wrong_code', 'message' => SecondStep::WRONG_CODE_MESSAGE, 'tries_left' => $left], 422);
        }
        if ($outcome !== SecondStep::OK) {
            return static::expired($outcome === SecondStep::DEAD ? SecondStep::TOO_MANY_WRONG_MESSAGE : SecondStep::EXPIRED_MESSAGE);
        }

        $device = $challenge->device_name !== null ? ['device' => AuthEvent::truncate($challenge->device_name, 120)] : [];
        Credentials::completeSignIn($user, null, $request->ip(), PublicAddress::isPublicRequest($request), $request, 'api', $device + ['method' => $data['method']]);
        AuthEvent::record(AuthEvent::SECOND_STEP_PASSED, $request, (int) $user->id, ['via' => 'api', 'method' => $data['method']]);

        return $this->issue($request, $user, $challenge->device_id, $challenge->device_name, 'second_step');
    }

    public function me(CurrentReader $reader): JsonResponse
    {
        $api = $reader->user()->toApi();
        if (!\App\Support\AuthMode::required()) {
            // Sign-in off: everyone is the owner, with the owner's rights.
            $api['role'] = User::ROLE_ADMIN;
        }

        return response()->json(['user' => $api]);
    }

    public function destroy(Request $request): Response
    {
        // The token this request signed in with (sign-in on), else the bearer
        // token presented (sign-in off: still forgotten on sign-out).
        $token = $request->attributes->get(IdentifyReader::TOKEN_ATTRIBUTE)
            ?? ApiToken::findActive($request->bearerToken());
        if ($token !== null && $token->revoked_at === null) {
            $token->revoke();
            AuthEvent::record(AuthEvent::TOKEN_REVOKED, $request, (int) $token->user_id, ['via' => 'api', 'token_id' => (int) $token->id, 'name' => $token->name]);
        }

        return response()->noContent();
    }

    private function issue(Request $request, User $user, ?string $deviceId, ?string $deviceName, string $via): JsonResponse
    {
        [$token, $plain] = ApiToken::issue($user, $deviceName, $deviceId);
        AuthEvent::record(AuthEvent::TOKEN_ISSUED, $request, (int) $user->id, ['via' => $via, 'token_id' => (int) $token->id, 'name' => $token->name]);
        // A device this account has not signed in on: an email (after the response).
        app(KnownDevices::class)->appSignIn($request, $user, $deviceId, $deviceName);

        return response()->json(['token' => $plain, 'user' => $user->toApi()]);
    }

    /** The live app challenge and its (enabled) account, or [null, null]. */
    private function challenge(SecondStep $steps, string $token): array
    {
        $challenge = $steps->find(strtolower(trim($token)), SecondStep::SIGN_IN, 'api');
        $user = $challenge === null ? null : User::find($challenge->user_id);
        if ($user === null || $user->isDisabled()) {
            return [null, null];
        }

        return [$challenge, $user];
    }

    /** A challenge made with a device_id is answered only with that device_id. */
    private static function sameDevice(object $challenge, ?string $deviceId): bool
    {
        if ($challenge->device_id === null) {
            return true;
        }

        return is_string($deviceId) && hash_equals((string) $challenge->device_id, mb_substr(trim($deviceId), 0, 64));
    }

    private static function expired(string $message): JsonResponse
    {
        return response()->json(['code' => 'challenge_expired', 'message' => $message], 404);
    }
}
