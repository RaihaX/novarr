<?php

namespace App\Http\Controllers\Api\V1;

use App\Books\BookUploads;
use App\Http\Controllers\BookUploadController as WebBookUploadController;
use App\Http\Controllers\Controller;
use App\Support\CurrentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/books/uploads — the Android app's "Share to Novarr": one or
 * more ePubs (epub[]) and an optional note, each becoming a book request the
 * owner matches and approves, exactly like the website's upload page.
 *
 * 201 when at least one file was accepted, 422 when none were:
 *   {message, sent: [{request_id, title}], refused: [{file, message, code}]}
 */
class BookUploadController extends Controller
{
    public function store(Request $request, BookUploads $uploads, CurrentReader $reader): JsonResponse
    {
        if ($off = RequestController::disabled()) {
            return $off;
        }
        if ($reader->isGuest()) {
            return response()->json(['message' => 'Sign in to send books.', 'code' => 'unauthenticated'], 401);
        }

        // One file or several: always a list (see the web controller).
        $raw = $request->files->get('epub');
        if ($raw !== null && !is_array($raw)) {
            $request->files->set('epub', [$raw]);
        }
        $maxFiles = self::maxFiles();
        $maxMb = WebBookUploadController::maxMb();
        $request->validate([
            'epub' => ['required', 'array', 'min:1', 'max:' . $maxFiles],
            'epub.*' => ['required', 'file', 'max:' . ($maxMb * 1024), 'extensions:epub'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'epub.required' => 'Choose an ePub file to upload.',
            'epub.max' => "Up to {$maxFiles} files at a time.",
            'epub.*.max' => "A file is larger than {$maxMb} MB.",
            'epub.*.extensions' => 'Only ePub files (.epub) can be added.',
            'epub.*.uploaded' => 'A file did not arrive in one piece. Try again, or try fewer files at once.',
        ]);

        $result = $uploads->receiveMany($reader->user(), collect($request->file('epub'))
            ->map(fn($file) => ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()])
            ->all(), $request->input('note'));

        $sent = array_map(fn($r) => ['request_id' => (int) $r->id, 'title' => (string) $r->title], $result['sent']);
        $refused = array_map(fn($r) => array_intersect_key($r, array_flip(['file', 'message', 'code'])), $result['refused']);

        return response()->json([
            'message' => self::summary(count($sent), count($refused)),
            'sent' => $sent,
            'refused' => $refused,
        ], $sent === [] ? 422 : 201);
    }

    public static function maxFiles(): int
    {
        return max(1, (int) config('novarr.books.max_files_per_upload', 20));
    }

    public static function summary(int $sent, int $refused): string
    {
        $books = fn(int $n) => $n === 1 ? '1 book was' : "{$n} books were";
        if ($sent === 0) {
            return $refused === 1 ? 'The book could not be added.' : 'None of the books could be added.';
        }

        return "{$books($sent)} sent to the owner." . ($refused > 0 ? " {$refused} could not be added." : '');
    }
}
