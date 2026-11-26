<?php

namespace App\Http\Controllers;

use App\Books\BookUploads;
use App\Novel;
use App\NovelRequest;
use App\Services\Requests\RequestRefused;
use App\Support\CurrentReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner's review of an uploaded book (admin only, see RouteAccess):
 * what the file says about itself beside catalogue matches, a search to
 * find the right book when the file's details are wrong, and the details
 * that will be used, pre-filled from the chosen match (?match=N) or from the
 * file (?match=file).
 */
class BookReviewController extends Controller
{
    public function show(int $id, Request $request, BookUploads $uploads): View
    {
        $bookRequest = $this->find($id);

        // Asked for by title: nothing to match until the owner uploads the ePub.
        if ($bookRequest->isWanted()) {
            return view('books.wanted', [
                'book' => $bookRequest,
                'requester' => $bookRequest->requester,
                'followers' => \App\NovelRequestFollower::where('novel_request_id', $bookRequest->id)->count(),
                'cover' => \App\Http\Controllers\RequestCoverController::url($bookRequest->cover_url),
                'maxMb' => BookUploadController::maxMb(),
            ]);
        }

        // The first visit searches with the file's own details.
        if ($bookRequest->isPending() && $bookRequest->candidates_ranked_at === null) {
            $uploads->search($bookRequest);
            $bookRequest->refresh();
        }

        $meta = (array) ($bookRequest->upload_meta ?? []);
        $candidates = $bookRequest->candidateList();
        $match = (string) $request->query('match', '');
        $chosen = ctype_digit($match) && isset($candidates[(int) $match]) ? $candidates[(int) $match] : null;

        // The form's starting values: the chosen match over the file's own.
        $fileAuthor = implode(', ', (array) ($meta['authors'] ?? [])) ?: (string) $bookRequest->author;
        $form = [
            'match' => $chosen !== null ? (int) $match : 'file',
            'title' => $chosen['title'] ?? ($meta['title'] ?: $bookRequest->title),
            'author' => $chosen !== null ? implode(', ', (array) $chosen['authors']) : $fileAuthor,
            'description' => ($chosen['description'] ?? null) ?: (string) $bookRequest->description,
            'genres' => implode(', ', $chosen !== null && $chosen['genres'] !== []
                ? $chosen['genres']
                : \App\Books\BookLibrary::subjectTags((array) ($meta['subjects'] ?? []))),
            'cover' => $chosen !== null && !empty($chosen['cover_url']) ? 'match' : (!empty($meta['cover_path']) ? 'file' : 'none'),
        ];

        return view('books.review', [
            'book' => $bookRequest,
            'meta' => $meta,
            'candidates' => $candidates,
            'chosen' => $chosen,
            'form' => $form,
            // On the address in use (the disk's URL is built on APP_URL).
            'fileCover' => !empty($meta['cover_path']) ? \App\Http\Controllers\Api\V1\Presenter::url(\Illuminate\Support\Facades\Storage::disk('public')->url($meta['cover_path'])) : null,
            'requester' => $bookRequest->requester,
            'novel' => $bookRequest->novel_id ? Novel::find($bookRequest->novel_id) : null,
            'fileMissing' => $bookRequest->isPending() && !is_file((string) BookUploads::pendingPath($bookRequest)),
        ]);
    }

    public function search(int $id, Request $request, BookUploads $uploads): RedirectResponse
    {
        $bookRequest = $this->find($id);
        $data = $request->validate([
            'q_title' => ['nullable', 'string', 'max:255'],
            'q_author' => ['nullable', 'string', 'max:255'],
            'q_isbn' => ['nullable', 'string', 'max:32'],
        ]);
        $title = trim((string) ($data['q_title'] ?? ''));
        $isbn = trim((string) ($data['q_isbn'] ?? ''));
        if ($title === '' && $isbn === '') {
            return back()->withErrors(['q_title' => 'Type a title or an ISBN to search for.'])->withInput();
        }

        $found = $uploads->search($bookRequest, $title !== '' ? $title : null, trim((string) ($data['q_author'] ?? '')) ?: null, $isbn !== '' ? $isbn : null);

        return redirect()->route('books.review', $bookRequest->id)
            ->withInput()
            ->with($found === [] ? 'error' : 'success', $found === [] ? 'Nothing found. Try fewer words, the author\'s surname, or the ISBN.' : count($found) . ' matches found.');
    }

    public function approve(int $id, Request $request, BookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        $bookRequest = $this->find($id);
        $data = $request->validate([
            'match' => ['required', 'string', 'max:8'],
            'title' => ['required', 'string', 'max:250'],
            'author' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:20000'],
            'genres' => ['nullable', 'string', 'max:500'],
            'cover' => ['required', 'in:match,file,none'],
            'force' => ['nullable', 'boolean'],
        ]);

        $candidates = $bookRequest->candidateList();
        $chosen = ctype_digit($data['match']) ? ($candidates[(int) $data['match']] ?? null) : null;

        $details = [
            'title' => $data['title'],
            'author' => (string) ($data['author'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'genres' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['genres'] ?? ''))))),
            'cover_url' => $data['cover'] === 'match' ? ($chosen['cover_url'] ?? null) : null,
            'file_cover' => $data['cover'] === 'file',
        ];

        try {
            $novel = $uploads->approve($bookRequest, $reader->user(), $details, (bool) ($data['force'] ?? false));
        } catch (RequestRefused $e) {
            return back()->withInput()
                ->withErrors(['approve' => $e->getMessage()])
                ->with('duplicate', $e->errorCode === 'duplicate');
        }

        return $this->next($bookRequest, "“{$novel->name}” is in the library.", route('novels.show', $novel->id));
    }

    /** The owner fulfils a book asked for by title with the ePub they found (BookUploads::attach()). */
    public function upload(int $id, Request $request, BookUploads $uploads): RedirectResponse
    {
        $bookRequest = $this->find($id);
        $maxMb = BookUploadController::maxMb();
        $request->validate([
            'epub' => ['required', 'file', 'max:' . ($maxMb * 1024), 'extensions:epub'],
        ], [
            'epub.required' => 'Choose the ePub file to upload.',
            'epub.max' => "The file is larger than {$maxMb} MB.",
            'epub.extensions' => 'Only ePub files (.epub) can be added.',
        ]);
        $file = $request->file('epub');

        try {
            $uploads->attach($bookRequest, $file->getRealPath(), $file->getClientOriginalName());
        } catch (\App\Books\EpubException $e) {
            return back()->withErrors(['epub' => $e->getMessage()]);
        } catch (RequestRefused $e) {
            return back()->withErrors(['epub' => $e->getMessage()]);
        }

        return redirect()->route('books.review', [$bookRequest->id, 'match' => 0])
            ->with('success', 'Uploaded. Check the details and cover, then add it to the library.');
    }

    public function decline(int $id, Request $request, BookUploads $uploads, CurrentReader $reader): RedirectResponse
    {
        $bookRequest = $this->find($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $uploads->decline($bookRequest, $reader->user(), $data['reason'] ?? null);
        } catch (RequestRefused $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return $this->next($bookRequest, "Declined “{$bookRequest->title}”.");
    }

    /**
     * After a decision: the next uploaded book waiting (oldest first), so a
     * batch is reviewed one after another; the Requests page when none is left.
     */
    private function next(NovelRequest $decided, string $message, ?string $link = null): RedirectResponse
    {
        $next = NovelRequest::where('medium', Novel::MEDIUM_BOOK)
            ->where('status', NovelRequest::STATUS_PENDING)
            ->whereKeyNot($decided->id)
            ->orderBy('id')
            ->first();
        $flash = ['success' => $message, 'success_link' => $link];
        if ($next !== null) {
            $left = NovelRequest::where('medium', Novel::MEDIUM_BOOK)->where('status', NovelRequest::STATUS_PENDING)->count();

            return redirect()->route('books.review', $next->id)->with($flash + ['queue_left' => $left]);
        }

        return redirect()->route('requests.index')->with($flash);
    }

    private function find(int $id): NovelRequest
    {
        $bookRequest = NovelRequest::find($id);
        abort_if($bookRequest === null || !$bookRequest->isUpload(), 404);

        return $bookRequest;
    }
}
