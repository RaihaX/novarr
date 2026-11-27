<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Novel;
use App\Services\SendEpub;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Send to my Kindle / email" (App\Services\SendEpub), for the web page's
 * button and the apps. Both answer {send: {status, message, to}}:
 *
 *   GET  /api/v1/novels/{id}/send   the reader's last send of this series (status null: none)
 *   POST /api/v1/novels/{id}/send   start one: 202 queued (poll GET until sent / failed)
 *
 * 422 {code: no_address} when the reader has no address (set it on the
 * Account page), {code: manga} for a manga; 429 past SendEpub::PER_HOUR.
 */
class SendEpubController extends Controller
{
    public function show(CurrentReader $reader, $id): JsonResponse
    {
        $novel = Novel::findOrFail($id);
        $state = SendEpub::state((int) $reader->user()->id, (int) $novel->id);

        return response()->json(['send' => $state ?? [
            'status' => null,
            'message' => null,
            'to' => SendEpub::address($reader->user()),
        ]]);
    }

    public function store(CurrentReader $reader, $id): JsonResponse
    {
        $novel = Novel::findOrFail($id);
        $user = $reader->user();
        if ($novel->hasPages()) {
            return response()->json(['message' => 'A manga has no ePub to send.', 'code' => 'manga'], 422);
        }
        $to = SendEpub::address($user);
        if ($to === null) {
            return response()->json([
                'message' => 'Add your Kindle or email address on your Account page first.',
                'code' => 'no_address',
                'account_url' => route('account') . '#send-to',
            ], 422);
        }

        $key = 'send-epub:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, SendEpub::PER_HOUR)) {
            return response()->json(['message' => 'That is a lot of sending: try again in a while.', 'code' => 'too_many'], 429);
        }
        RateLimiter::hit($key, 3600);

        return response()->json(['send' => SendEpub::start($user, $novel, $to)], 202);
    }
}
