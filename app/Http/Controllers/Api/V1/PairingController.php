<?php

namespace App\Http\Controllers\Api\V1;

use App\DevicePairing;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Linking a device by code (auth contract; the KOReader plugin). Both
 * endpoints are public; the code is entered on Account → "Link a device"
 * (App\Http\Controllers\AccountController).
 *
 *   POST /api/v1/auth/pairings {device_id, device_name}
 *        → 201 {code, secret, expires_in, interval}   (10 an hour per IP)
 *   GET  /api/v1/auth/pairings/{secret}
 *        → 200 {status: pending} | 200 {status: linked, token, user} (once)
 *        | 404 (expired, unknown or already collected)
 */
class PairingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'nullable|string|max:255',
            'device_name' => 'nullable|string|max:255',
        ]);

        [$pairing, $secret] = DevicePairing::begin($data['device_id'] ?? null, $data['device_name'] ?? null);

        return response()->json([
            'code' => $pairing->code,
            'secret' => $secret,
            'expires_in' => DevicePairing::TTL_SECONDS,
            'interval' => DevicePairing::POLL_INTERVAL,
        ], 201);
    }

    public function show(string $secret): JsonResponse
    {
        $pairing = DevicePairing::bySecret($secret);
        if ($pairing === null) {
            return $this->gone();
        }
        // Not linked yet — or being linked this instant (claimed, token not
        // written yet): ask again.
        if ($pairing->user_id === null || $pairing->token === null) {
            return response()->json(['status' => 'pending']);
        }

        $user = User::find($pairing->user_id);
        $token = $pairing->collect();
        if ($token === null || $user === null) {
            return $this->gone();
        }

        return response()->json(['status' => 'linked', 'token' => $token, 'user' => $user->toApi()]);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => 'This link code has expired or was already used. Start again.'], 404);
    }
}
