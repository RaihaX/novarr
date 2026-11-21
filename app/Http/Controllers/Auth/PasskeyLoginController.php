<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Credentials;
use App\Services\Passkeys;
use App\Support\AuthMode;
use App\Support\PublicAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Sign in with a passkey" on /login: one step instead of the password and
 * the second step. The browser offers the passkeys it holds for this
 * address (discoverable credentials: no name is typed, so nothing tells
 * whether an account exists); the passkey names its account. Public routes,
 * throttled per address; a passkey that is not accepted counts toward the
 * public address's per-IP cap. JSON for the page's script.
 */
class PasskeyLoginController extends Controller
{
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        abort_unless(AuthMode::required(), 404);
        if (!Passkeys::available($request)) {
            return response()->json(['message' => Passkeys::UNAVAILABLE_MESSAGE], 422);
        }

        return response()->json($passkeys->requestOptions($request, Passkeys::SIGN_IN));
    }

    public function verify(Request $request, Passkeys $passkeys): JsonResponse
    {
        abort_unless(AuthMode::required(), 404);
        $publicHost = PublicAddress::isPublicRequest($request);
        if ($publicHost && ($seconds = Credentials::publicCapRetryAfter($request->ip())) !== null) {
            return Credentials::tooManyJson($seconds);
        }

        $passkey = $passkeys->verify($request, Passkeys::SIGN_IN, $request->input('credential'));
        $user = $passkey?->user;
        if ($user === null || $user->isDisabled()) {
            if ($publicHost) {
                Credentials::hitPublicCap($request->ip());
            }
            Credentials::recordFailed($request, 'passkey', $user, $user === null ? 'passkey' : 'disabled');

            return response()->json(['message' => $user === null ? Passkeys::FAILED_MESSAGE : Credentials::DISABLED], 422);
        }

        return SecondStepController::jsonRedirect(LoginController::finishSignIn($request, $user, true, 'passkey', 'passkey'));
    }
}
