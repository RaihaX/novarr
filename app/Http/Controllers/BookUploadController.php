<?php

namespace App\Http\Controllers;

use App\Books\BookInbox;
use App\Books\BookUploads;
use App\Support\CurrentReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * GET/POST /books/upload: any reader sends an ePub. It becomes a book
 * request the owner matches and approves (App\Books\BookUploads); the owner's
 * own upload goes straight to that review page.
 */
class BookUploadController extends Controller
{
    public function create(): View
    {
        abort_unless(config('novarr.requests.enabled', true), 404);

        return view('books.upload', [
            'maxMb' => self::maxMb(),
            'maxFiles' => max(1, (int) config('novarr.books.max_files_per_upload', 20)),
            'inboxAddress' => BookInbox::enabled() ? BookInbox::address() : null,
            'readerEmail' => !app(CurrentReader::class)->isGuest() && ($user = app(CurrentReader::class)->user()) && \App\Services\Requests\RequestNotifier::hasEmail($user) ? $user->email : null,
        ]);
    }

    public function store(Request $request, BookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        abort_unless(config('novarr.requests.enabled', true), 404);
        abort_if($reader->isGuest(), 403);

        // One file or several (epub[]): always a list from here on. Read the
        // raw bag: $request->file() would cache the single file before this.
        $raw = $request->files->get('epub');
        if ($raw !== null && !is_array($raw)) {
            $request->files->set('epub', [$raw]);
        }
        $maxFiles = max(1, (int) config('novarr.books.max_files_per_upload', 20));

        $request->validate([
            'epub' => ['required', 'array', 'min:1', 'max:' . $maxFiles],
            'epub.*' => ['required', 'file', 'max:' . (self::maxMb() * 1024), 'extensions:epub'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'epub.required' => 'Choose an ePub file to upload.',
            'epub.max' => "Up to {$maxFiles} files at a time.",
            'epub.*.max' => 'A file is larger than ' . self::maxMb() . ' MB.',
            'epub.*.extensions' => 'Only ePub files (.epub) can be added.',
            'epub.*.uploaded' => 'A file did not arrive in one piece. Try again, or try fewer files at once.',
        ]);

        $user = $reader->user();
        $result = $uploads->receiveMany($user, collect($request->file('epub'))
            ->map(fn($file) => ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()])
            ->all(), $request->input('note'));
        $sent = $result['sent'];
        $refused = array_map(fn($r) => "{$r['file']}: {$r['message']}", $result['refused']);
        $inLibrary = collect($result['refused'])->whereNotNull('novel_id')->pluck('novel_id')
            ->map(fn($id) => route('novels.show', $id))->first();

        // The owner (at home) matches them now: one goes straight to its review, several to the review queue.
        if ($sent !== [] && $user->isAdmin() && Gate::allows('admin')) {
            $redirect = count($sent) === 1 && $refused === []
                ? redirect()->route('books.review', $sent[0]->id)
                : redirect()->route('requests.index');

            return $refused === [] ? $redirect : $redirect->withErrors(['epub' => $refused]);
        }

        $redirect = redirect()->route('books.upload');
        if ($sent !== []) {
            $titles = collect($sent)->map(fn($r) => "“{$r->title}”");
            $redirect = $redirect->with('success', count($sent) === 1
                ? "Thanks: {$titles[0]} was sent to the owner. You'll hear when it is in the library."
                : 'Thanks: ' . count($sent) . ' books were sent to the owner (' . $titles->take(5)->implode(', ') . (count($sent) > 5 ? ', …' : '') . "). You'll hear as each is added.");
        }
        if ($refused !== []) {
            $redirect = $redirect->withInput($request->only('note'))->withErrors(['epub' => $refused])->with('in_library', $inLibrary);
        }

        return $redirect;
    }

    public static function maxMb(): int
    {
        return max(1, (int) config('novarr.books.max_upload_mb', 50));
    }
}
