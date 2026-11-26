<?php

namespace App\Http\Controllers\Api\V1;

use App\Books\BookInbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/books/inbox — the books email address (App\Books\BookInbox).
 * Called only by the Cloudflare Email Worker (cloudflare/books-inbox): the
 * body is the raw email (message/rfc822), the X-Novarr-Inbox-Secret header
 * the shared secret (config novarr.books.inbox.secret), X-Novarr-Envelope-From
 * the envelope sender.
 *
 *   200 accepted / ignored (the reader gets a reply; ignored: no reply)
 *   403 wrong secret
 *   404 the inbox is not set up
 *   422 unknown_sender (the Worker bounces the email with `message`)
 */
class BookInboxController extends Controller
{
    public function store(Request $request, BookInbox $inbox): JsonResponse
    {
        if (!BookInbox::enabled() || !config('novarr.requests.enabled', true)) {
            return response()->json(['message' => 'The books inbox is not set up.', 'code' => 'inbox_off'], 404);
        }
        if (!hash_equals((string) BookInbox::secret(), (string) $request->header('X-Novarr-Inbox-Secret', ''))) {
            return response()->json(['message' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $raw = (string) $request->getContent();
        if ($raw === '') {
            return response()->json(['message' => 'The email was empty.', 'code' => 'empty'], 422);
        }

        try {
            $result = $inbox->receive($raw, $request->header('X-Novarr-Envelope-From'));
        } catch (\Throwable $e) {
            Log::error('Books inbox: the email could not be read: ' . $e->getMessage(), ['exception' => $e]);

            return response()->json(['message' => 'Novarr could not read this email. Try again later, or upload the book on the website.', 'code' => 'failed'], 500);
        }

        return response()->json(
            ['code' => $result['status']] + $result,
            $result['status'] === BookInbox::UNKNOWN_SENDER ? 422 : 200,
        );
    }
}
