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
        ]);
    }

    public function store(Request $request, BookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        abort_unless(config('novarr.requests.enabled', true), 404);
        abort_if($reader->isGuest(), 403);

        $request->validate([
            'epub' => ['required', 'file', 'max:' . (self::maxMb() * 1024), 'extensions:epub'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'epub.required' => 'Choose an ePub file to upload.',
            'epub.max' => 'That file is larger than ' . self::maxMb() . ' MB.',
            'epub.extensions' => 'Only ePub files (.epub) can be added.',
            'epub.uploaded' => 'The upload did not arrive in one piece. Try again, or try a smaller file.',
        ]);

        $file = $request->file('epub');
        $user = $reader->user();

        try {
            $bookRequest = $uploads->receive($user, $file->getRealPath(), $file->getClientOriginalName(), $request->input('note'));
        } catch (EpubException $e) {
            return back()->withInput()->withErrors(['epub' => $e->getMessage()]);
        } catch (RequestRefused $e) {
            $novelId = $e->extra['novel_id'] ?? null;

            return back()->withInput()
                ->withErrors(['epub' => $e->getMessage()])
                ->with('in_library', $novelId ? route('novels.show', $novelId) : null);
        } catch (\Throwable $e) {
            Log::error('Book upload failed: ' . $e->getMessage(), ['exception' => $e]);

            return back()->withInput()->withErrors(['epub' => 'The book could not be added. Try again in a moment.']);
        }

        // The owner (at home) matches it now; a reader waits for the owner.
        if ($user->isAdmin() && Gate::allows('admin')) {
            return redirect()->route('books.review', $bookRequest->id);
        }

        return redirect()->route('books.upload')->with('success', "Thanks: “{$bookRequest->title}” was sent to the owner. You'll hear when it is in the library.");
    }

    public static function maxMb(): int
    {
        return max(1, (int) config('novarr.books.max_upload_mb', 100));
    }
}
