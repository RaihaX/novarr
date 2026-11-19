<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AddSeries;
use App\Services\DuplicateSeries;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/novels — add a Discover result to the library.
 *
 * The web Discover page adds through the `novel:create {name} {url}`
 * background command, which saves the novel and then fetches its metadata,
 * genres and cover in the same run. Here the row is saved in the request,
 * as novel:create saves it (name, source URL, group 1, origin inferred), so
 * the answer can carry the novel; the fetch then runs as `novel:metadata
 * {id}` on the `commands` queue, which does the same metadata, genre and
 * cover work for an existing novel. As on the web, the chapter list is not
 * scraped yet (POST /api/v1/novels/{id}/check does that).
 *
 * Duplicates (DuplicateSeries): the same source page answers the library
 * novel (200, already_in_library: true), as before. The same title in the
 * medium from another source answers 409 {message, duplicate: {id, title,
 * source}}, unless the request carries force=1 (two works can share a title).
 */
class AddNovelController extends Controller
{
    public const LOCK_BUSY = 'Another novel is being added right now. Try again in a moment.';

    public function store(Request $request, AddSeries $series): JsonResponse
    {
        $data = $request->validate(static::rules());
        $title = trim($data['title']);
        $url = trim($data['url']);
        $force = $request->boolean('force');

        // The decision and the insert are made under one lock (AddSeries),
        // so two taps on "Add" cannot both create the novel.
        try {
            $outcome = $series->add($title, $url, $force);
        } catch (LockTimeoutException) {
            return response()->json(['message' => self::LOCK_BUSY], 503);
        }

        if ($outcome['result'] === AddSeries::DUPLICATE) {
            return response()->json([
                'message' => DuplicateSeries::message($outcome['novel']),
                'duplicate' => DuplicateSeries::describe($outcome['novel']),
            ], 409);
        }

        $id = (int) $outcome['novel']->id;
        $novel = Presenter::loadNovels([$id])->get($id);
        $body = ['novel' => Presenter::summary($novel, Presenter::context())];

        if ($outcome['result'] === AddSeries::EXISTING) {
            return response()->json($body + ['already_in_library' => true]);
        }

        return response()->json($body + ['already_in_library' => false], 201);
    }

    /** Validation of a title + source URL to add (also an admin approving a request with a URL). */
    public static function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'force' => 'nullable|boolean',
        ];
    }
}
