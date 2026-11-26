<?php

namespace App\Http\Controllers;

use App\Books\BookUploads;
use App\Books\EpubException;
use App\Services\Requests\RequestRefused;
use App\Support\CurrentReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $sent = [];
        $refused = [];
        $inLibrary = null;
        foreach ($request->file('epub') as $file) {
            $name = $file->getClientOriginalName();
            try {
                $sent[] = $uploads->receive($user, $file->getRealPath(), $name, $request->input('note'));
            } catch (EpubException $e) {
                $refused[] = "{$name}: {$e->getMessage()}";
            } catch (RequestRefused $e) {
                $refused[] = "{$name}: {$e->getMessage()}";
                $inLibrary ??= isset($e->extra['novel_id']) ? route('novels.show', $e->extra['novel_id']) : null;
                if ($e->errorCode === 'pending_limit') {
                    break; // every later file would be refused the same way
                }
            } catch (\Throwable $e) {
                Log::error("Book upload of {$name} failed: " . $e->getMessage(), ['exception' => $e]);
                $refused[] = "{$name}: it could not be added. Try again in a moment.";
            }
        }

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
