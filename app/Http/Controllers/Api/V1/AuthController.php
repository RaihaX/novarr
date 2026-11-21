<?php

namespace App\Http\Controllers\Api\V1;

use App\ApiToken;
use App\AuthEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\IdentifyReader;
use App\Services\Credentials;
use App\Support\CurrentReader;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Password sign-in for the native clients (auth contract):
 *
 *   POST   /api/v1/auth/token  {username, password, device_id, device_name}
 *          → 200 {token, user} | 422 {message} | 429 {message}
 *   GET    /api/v1/auth/me     → 200 {user} (sign-in off: the owner, admin)
 *   DELETE /api/v1/auth/token  → 204, revokes the token in use
 *
 * Tokens can be made while sign-in is off too (a client may sign in ahead
 * of the switch); they are only used for identity once it is on.
 */
class AuthController extends Controller
{
    public function store(Request $request, Credentials $credentials): JsonResponse
    {
        $data = $request->validate([
            // A sign-in name or an email address (App\User::findForSignIn).
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'device_id' => 'nullable|string|max:255',
            'device_name' => 'nullable|string|max:255',
        ]);

        $result = $credentials->attempt($data['username'], $data['password'], $request->ip(), \App\Support\PublicAddress::isPublicRequest($request), $request, 'api');
        if (!$result instanceof User) {
            $headers = isset($result['retry_after']) ? ['Retry-After' => (string) $result['retry_after']] : [];

            return response()->json(['message' => $result['error']], $result['status'], $headers);
        }

        [$token, $plain] = ApiToken::issue($result, $data['device_name'] ?? null, $data['device_id'] ?? null);
        AuthEvent::record(AuthEvent::TOKEN_ISSUED, $request, (int) $result->id, ['via' => 'password', 'token_id' => (int) $token->id, 'name' => $token->name]);

        return response()->json(['token' => $plain, 'user' => $result->toApi()]);
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
}
