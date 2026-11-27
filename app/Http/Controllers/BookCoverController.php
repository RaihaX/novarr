<?php

namespace App\Http\Controllers;

use App\Books\BookCovers;
use App\Books\CatalogueHttp;
use App\Novel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Find a better cover" on a book's page (the owner; RouteAccess admin):
 *
 *   GET  /novels/{id}/covers   the current cover (size, placeholder or not)
 *                              beside every catalogue cover of the book,
 *                              largest first, with their sizes
 *                              (App\Books\BookCovers::chooserCandidates();
 *                              ?fresh=1 asks the catalogues again)
 *   POST /novels/{id}/covers   choice=N: that cover becomes the book's, as
 *                              the owner's choice (never replaced by
 *                              books:covers afterwards)
 */
class BookCoverController extends Controller
{
    public function index(int $id, Request $request, BookCovers $covers): View
    {
        $book = $this->book($id);

        return view('books.covers', [
            'book' => $book,
            'current' => $covers->current($book),
            'currentUrl' => $book->coverUrl('md'),
            'candidates' => $covers->chooserCandidates($book, $request->boolean('fresh')),
        ]);
    }

    public function choose(int $id, Request $request, BookCovers $covers): RedirectResponse
    {
        $book = $this->book($id);
        $data = $request->validate(['choice' => 'required|integer|min:0|max:50']);
        $chosen = $covers->chooserCandidates($book)[(int) $data['choice']] ?? null;
        if ($chosen === null) {
            return redirect()->route('novels.covers', $book->id)->with('error', 'That cover is no longer on the list. Choose again.');
        }
        $image = CatalogueHttp::image((string) $chosen['url']);
        if ($image === null || !$covers->apply($book, $image + ['url' => (string) $chosen['url']], BookCovers::SOURCE_MANUAL)) {
            return redirect()->route('novels.covers', $book->id)->with('error', 'That cover could not be downloaded. Try another one.');
        }

        return redirect()->route('novels.show', $book->id)->with('success', "The cover from {$chosen['source_name']} ({$image['width']}×{$image['height']}) is the book's now.");
    }

    private function book(int $id): Novel
    {
        $book = Novel::with('file')->findOrFail($id);
        abort_unless($book->isBook(), 404);

        return $book;
    }
}
