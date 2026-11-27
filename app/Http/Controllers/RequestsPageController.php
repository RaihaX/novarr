<?php

namespace App\Http\Controllers;

use App\Novel;
use App\Services\Discover;
use App\Services\Requests\RequestSearch;
use App\Support\CurrentReader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * GET /requests — the reader-requests page shell (resources/views/requests).
 * Everything on it comes from the JSON API (/api/v1/requests…), called by
 * resources/js/requests.js with the session cookie; this only renders the
 * frame. A reader route (also on the public address); 404 with the feature
 * off (config novarr.requests.enabled).
 */
class RequestsPageController extends Controller
{
    public function index(RequestSearch $searches, Discover $discover): View
    {
        abort_unless(config('novarr.requests.enabled', true), 404);

        $reader = app(CurrentReader::class);
        $user = $reader->isGuest() ? null : $reader->user();

        return view('requests.index', [
            // How many sources a search asks, for "Searching N sources…".
            'sourceCounts' => [
                Novel::MEDIUM_NOVEL => $this->sourceCount($searches, $discover, Novel::MEDIUM_NOVEL),
                Novel::MEDIUM_MANGA => $this->sourceCount($searches, $discover, Novel::MEDIUM_MANGA),
                Novel::MEDIUM_BOOK => $this->sourceCount($searches, $discover, Novel::MEDIUM_BOOK),
                Novel::MEDIUM_COMIC => $this->sourceCount($searches, $discover, Novel::MEDIUM_COMIC),
            ],
            // The admin account on the public address: deciding is refused
            // there (Gate 'admin'), so the page explains instead.
            'ownerAway' => $user !== null && $user->isAdmin() && !Gate::allows('admin'),
            // The reader's own audiobook uploads (App\Audiobooks\AudiobookUploads), newest first.
            'audiobookUploads' => $user !== null && \App\Audiobooks\AudiobookUploads::enabled()
                ? \App\AudiobookUpload::with('audiobook')->where('user_id', $user->id)->orderByDesc('id')->limit(30)->get()
                : collect(),
        ]);
    }

    private function sourceCount(RequestSearch $searches, Discover $discover, string $medium): ?int
    {
        try {
            return count($searches->sources($discover, $medium));
        } catch (\Throwable $e) {
            // The count is only a label: never fail the page over it.
            Log::warning("Requests page: counting {$medium} sources failed: " . $e->getMessage());

            return null;
        }
    }
}
