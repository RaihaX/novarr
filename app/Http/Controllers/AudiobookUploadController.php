<?php

namespace App\Http\Controllers;

use App\Audiobook;
use App\AudiobookUpload;
use App\Audiobooks\AudiobookLibrary;
use App\Audiobooks\AudiobookMatcher;
use App\Audiobooks\AudiobookUploads;
use App\Audiobooks\UploadRefused;
use App\Novel;
use App\Support\CurrentReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Audiobook uploads on the web (App\Audiobooks\AudiobookUploads):
 *
 *   GET  /audiobooks/upload[?novel_id=]          the reader's upload page (resources/js/audiobook-upload.js,
 *                                                through the chunk API /api/v1/audiobook-uploads)
 *   GET  /audiobooks/uploads/{id}                the owner's review: tags, length, chapters, cover,
 *                                                a sample player, the suggested book, duplicates
 *   POST /audiobooks/uploads/{id}/approve        {novel_id|null, standalone?, replace?}
 *   POST /audiobooks/uploads/{id}/use-existing   {audiobook_id}: it is one the library has
 *   POST /audiobooks/uploads/{id}/decline        {reason?}
 *   GET  /audiobooks/uploads/{id}/sample/{index} the pending file, streamed with Range (owner only)
 */
class AudiobookUploadController extends Controller
{
    public function create(Request $request, CurrentReader $reader): View
    {
        abort_unless(AudiobookUploads::enabled(), 404);
        $novelId = (int) $request->query('novel_id', 0);
        $book = $novelId > 0 ? Novel::with('file')->find($novelId) : null;
        if ($book !== null && !$book->hasLibraryFile()) {
            $book = null;
        }
        $limits = AudiobookUploads::limits();

        return view('audiobooks.upload', [
            'book' => $book,
            'hasAudiobook' => $book !== null && AudiobookLibrary::forNovel((int) $book->id) !== null,
            'config' => [
                'endpoint' => '/api/v1/audiobook-uploads',
                'novel_id' => $book?->id,
                'max_file' => $limits['max_file'],
                'max_total' => $limits['max_total'],
                'max_files' => $limits['max_files'],
                'formats' => array_keys(AudiobookLibrary::FORMATS),
                'images' => AudiobookLibrary::IMAGE_EXTENSIONS,
                'image_max' => AudiobookUploads::IMAGE_MAX_BYTES,
                'max_images' => AudiobookUploads::MAX_IMAGES,
                'concurrency' => 3,
                'requests_url' => route('requests.index'),
                'user' => $reader->isGuest() ? 0 : $reader->id(),
            ],
            'limits' => $limits,
        ]);
    }

    public function review(Request $request, int $id): View
    {
        $upload = $this->find($id);
        $probe = (array) ($upload->probe ?? []);
        $suggestedId = $upload->novel_id ?: ($probe['suggested_novel_id'] ?? null);
        $suggested = $suggestedId ? Novel::with('file')->find($suggestedId) : null;

        // "Change the book": a search (?q=), prefilled with the files' title.
        $q = trim((string) $request->query('q', ''));
        $results = $q !== '' ? AudiobookMatcher::searchBooks($q) : collect();
        $linked = $results->isEmpty() ? [] : Audiobook::whereIn('novel_id', $results->pluck('id'))->pluck('id', 'novel_id')->all();
        $chosenId = (int) $request->query('novel_id', $suggested?->id ?? 0);
        $chosen = $chosenId > 0 ? Novel::with('file')->find($chosenId) : null;

        $duplicate = !empty($probe['duplicate_of']) ? Audiobook::withTrashed()->with('novel')->find($probe['duplicate_of']) : null;
        $bookAudiobook = $chosen !== null ? Audiobook::withTrashed()->where('novel_id', $chosen->id)->first() : null;

        return view('audiobooks.upload_review', [
            'upload' => $upload,
            'probe' => $probe,
            'uploader' => $upload->user,
            'suggested' => $suggested,
            'chosen' => $chosen,
            'q' => $q !== '' ? $q : AudiobookMatcher::cleanTitle((string) ($probe['title'] ?? $upload->displayTitle())),
            'searched' => $q !== '',
            'results' => $results,
            'linked' => $linked,
            'duplicate' => $duplicate,
            'bookAudiobook' => $bookAudiobook,
            'cover' => !empty($probe['cover_path']) ? \App\Http\Controllers\Api\V1\Presenter::url(\Illuminate\Support\Facades\Storage::disk('public')->url($probe['cover_path'])) : null,
            'audioFiles' => collect((array) $upload->files)->map(fn($f, $i) => $f + ['index' => (int) $i])->filter(fn($f) => !empty($f['audio']))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'imported' => $upload->audiobook_id ? Audiobook::withTrashed()->find($upload->audiobook_id) : null,
            'queueLeft' => AudiobookUpload::where('status', AudiobookUpload::STATUS_PENDING)->count(),
        ]);
    }

    public function approve(Request $request, int $id, AudiobookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        $upload = $this->find($id);
        $data = $request->validate([
            'novel_id' => ['nullable', 'integer', 'min:1'],
            'standalone' => ['sometimes', 'boolean'],
            'replace' => ['sometimes', 'boolean'],
        ]);
        $novelId = !empty($data['standalone']) ? null : (isset($data['novel_id']) ? (int) $data['novel_id'] : null);
        if ($novelId === null && empty($data['standalone'])) {
            return back()->withErrors(['approve' => 'Choose the book it is the audiobook of, or add it standalone.']);
        }

        try {
            $audiobook = $uploads->approve($upload, $reader->user(), $novelId, (bool) ($data['replace'] ?? false));
        } catch (UploadRefused $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }
        $audiobook->load('novel');

        return $this->next($upload, "“{$audiobook->displayTitle()}” is in the library" . ($audiobook->novel ? " as the audiobook of “{$audiobook->novel->name}”." : ' (standalone).'), route('audiobooks.show', $audiobook->id));
    }

    public function useExisting(Request $request, int $id, AudiobookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        $upload = $this->find($id);
        $data = $request->validate(['audiobook_id' => ['required', 'integer', 'min:1']]);
        $existing = Audiobook::find((int) $data['audiobook_id']);
        if ($existing === null) {
            return back()->withErrors(['approve' => 'That audiobook is no longer in the library.']);
        }
        try {
            $uploads->useExisting($upload, $reader->user(), $existing);
        } catch (UploadRefused $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return $this->next($upload, "Closed as “{$existing->title}”, which was already in the library; the upload’s files were deleted.", route('audiobooks.show', $existing->id));
    }

    public function decline(Request $request, int $id, AudiobookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        $upload = $this->find($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        try {
            $uploads->decline($upload, $reader->user(), $data['reason'] ?? null);
        } catch (UploadRefused $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return $this->next($upload, "Declined “{$upload->displayTitle()}”: its files were deleted.");
    }

    /** One pending file, streamed with HTTP Range (the review page's sample player). Owner only (RouteAccess). */
    public function sample(int $id, int $index): BinaryFileResponse
    {
        $upload = $this->find($id);
        $file = (array) (((array) $upload->files)[$index] ?? []);
        abort_if(empty($file['audio']) || empty($file['path']), 404, 'That file is not ready to play.');

        $root = realpath($upload->directory());
        $path = realpath($upload->directory() . '/' . $file['path']);
        // Inside the upload's own folder, always (the stored path is never trusted to stay there).
        abort_if($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path), 404, 'The file is no longer on the server.');

        $response = new BinaryFileResponse($path, 200, ['Content-Type' => AudiobookLibrary::mimeFor($path)], false, null, false, true);
        $response->setPrivate();
        $response->headers->set('Accept-Ranges', 'bytes');

        return $response;
    }

    /** After a decision: the next upload waiting (oldest first); Downloads → Needs you when none is left. */
    private function next(AudiobookUpload $decided, string $message, ?string $link = null): RedirectResponse
    {
        $next = AudiobookUpload::where('status', AudiobookUpload::STATUS_PENDING)->whereKeyNot($decided->id)->orderBy('id')->first();
        $flash = ['success' => $message, 'success_link' => $link];
        if ($next !== null) {
            return redirect()->route('audiobooks.uploads.review', $next->id)->with($flash);
        }

        return redirect()->route('downloads.index', ['tab' => 'attention'])->with($flash);
    }

    private function find(int $id): AudiobookUpload
    {
        $upload = AudiobookUpload::with('user')->find($id);
        abort_if($upload === null, 404);

        return $upload;
    }
}
